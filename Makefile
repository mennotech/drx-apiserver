# Local development orchestration for drx-apiserver.
#
# `make base`   — build the reusable drx-apiserver base image locally
# `make build`  — build the reference app on top of it
# `make up`     — bring up the reference app via docker compose (no rebuild)
# `make up-build` — rebuild app image, then bring it up
# `make down`   — stop the reference app
# `make up-base` — run only the base image locally
# `make down-base` — stop the base-only local container
# `make smoke`  — boot the base image and hit its healthcheck
# `make scan`   — run the same Trivy scan CI runs (HIGH/CRITICAL, ignore-unfixed)
# `make verify` — smoke + scan; the minimum check before `git push`
# `make clean`  — remove build artifacts and the local image tags

BASE_IMAGE   ?= drx-apiserver:dev
APP_IMAGE    ?= drx-apiserver-demo:dev
SMOKE_PORT   ?= 8089
BASE_UP_PORT ?= 8087
BASE_UP_CONTAINER ?= drx-base
BASE_UP_ADMIN_PASS ?= dev-password
VERSION      ?= 0.0.0-dev
VCS_REF      := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
BUILD_DATE   := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)

# Container engine selection. Defaults to docker; set CONTAINER_ENGINE=podman
# to drive the same targets through rootless or rootful Podman. COMPOSE is
# split out so users can pair `podman` with whichever compose front-end they
# have available (`podman compose`, `podman-compose`, ...).
#
# `make scan` is engine-agnostic by design: it pipes
# `$(CONTAINER_ENGINE) save` into Trivy on stdin, so no container socket
# needs to be mounted or auto-detected. Junior devs can run
# `make verify CONTAINER_ENGINE=podman` with no other configuration.
CONTAINER_ENGINE  ?= docker
COMPOSE           ?= $(CONTAINER_ENGINE) compose
COMPOSE_FILE      ?= server/docker-compose.yml
COMPOSE_PROJECT   ?= drx-apiserver
COMPOSE_ARGS      ?= -f $(COMPOSE_FILE) --project-directory . -p $(COMPOSE_PROJECT)

# Trivy invocation must stay in lock-step with .github/workflows/base-image.yml.
TRIVY_VERSION  ?= 0.70.0
TRIVY_SEVERITY ?= CRITICAL,HIGH

.PHONY: base build up up-build down up-base down-base smoke scan verify clean

base:
	$(CONTAINER_ENGINE) build \
		--tag $(BASE_IMAGE) \
		--build-arg DRX_BASE_VERSION=$(VERSION) \
		--build-arg DRX_BASE_VCS_REF=$(VCS_REF) \
		--build-arg DRX_BASE_BUILD_DATE=$(BUILD_DATE) \
		./base

build: base
	APP_IMAGE=$(APP_IMAGE) DRX_BASE_IMAGE=$(BASE_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) build

up:
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) up -d

up-build: build up

down:
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) down

up-base: base
	@$(CONTAINER_ENGINE) rm -f $(BASE_UP_CONTAINER) >/dev/null 2>&1 || true
	$(CONTAINER_ENGINE) run -d --name $(BASE_UP_CONTAINER) \
		-e DRUPAL_ADMIN_PASS=$(BASE_UP_ADMIN_PASS) \
		-p $(BASE_UP_PORT):80 $(BASE_IMAGE)

down-base:
	@$(CONTAINER_ENGINE) rm -f $(BASE_UP_CONTAINER) >/dev/null 2>&1 || true

# The healthcheck script is invoked directly via `$(CONTAINER_ENGINE) exec`
# rather than read from `.State.Health.Status`, so this target works the
# same under Docker and under rootless Podman (which does not run
# HEALTHCHECK timers automatically).
smoke: base
	@$(CONTAINER_ENGINE) rm -f drx-smoke >/dev/null 2>&1 || true
	$(CONTAINER_ENGINE) run --rm -d --name drx-smoke \
		-e DRUPAL_ADMIN_PASS=smoke-password \
		-p $(SMOKE_PORT):80 $(BASE_IMAGE)
	@echo "Waiting for healthcheck..."
	@for i in $$(seq 1 60); do \
		if ! $(CONTAINER_ENGINE) inspect drx-smoke >/dev/null 2>&1; then \
			echo "smoke failed early (container gone)"; \
			$(CONTAINER_ENGINE) logs drx-smoke 2>/dev/null || true; exit 1; fi; \
		if $(CONTAINER_ENGINE) exec drx-smoke /usr/local/bin/drx-healthcheck >/dev/null 2>&1; then \
			echo "ok after $${i}s"; $(CONTAINER_ENGINE) rm -f drx-smoke >/dev/null; exit 0; fi; \
		sleep 2; done; \
	echo "smoke failed"; $(CONTAINER_ENGINE) logs drx-smoke || true; $(CONTAINER_ENGINE) rm -f drx-smoke >/dev/null 2>&1 || true; exit 1

# Run Trivy with the same gating policy as CI:
#   - severity: CRITICAL,HIGH
#   - ignore-unfixed: true       (only fail on issues with an upstream fix)
#   - exit-code: 1 on findings
# Trivy is run via its official OCI image so contributors don't need to
# install the binary; the vulnerability DB is cached under
# $$HOME/.cache/trivy. The image to scan is delivered to Trivy as a tar
# saved by `$(CONTAINER_ENGINE) save` and mounted via `--input`
scan: base
	@if ! $(CONTAINER_ENGINE) info >/dev/null 2>&1; then \
		echo "ERROR: $(CONTAINER_ENGINE) API is not reachable"; \
		if [ "$(CONTAINER_ENGINE)" = "podman" ]; then \
			echo "Hint: ensure Podman is running:"; \
			echo "  macOS / Windows:  podman machine start"; \
			echo "  Linux (rootless): systemctl --user start podman.socket"; \
			echo "  Linux (rootful):  sudo systemctl start podman.socket"; \
		fi; \
		exit 1; \
	fi
	@mkdir -p $${HOME}/.cache/trivy
	@TMP=$$(mktemp -d) && \
		trap 'rm -rf "$$TMP"' EXIT && \
		echo "Saving $(BASE_IMAGE) to $$TMP/image.tar" && \
		$(CONTAINER_ENGINE) save -o "$$TMP/image.tar" $(BASE_IMAGE) && \
		$(CONTAINER_ENGINE) run --rm \
			-v "$$TMP:/scan:ro" \
			-v "$${HOME}/.cache/trivy:/root/.cache/" \
			aquasec/trivy:$(TRIVY_VERSION) image \
				--input /scan/image.tar \
				--severity $(TRIVY_SEVERITY) \
				--ignore-unfixed \
				--exit-code 1 \
				--no-progress

# Minimum local check before `git push`. Mirrors the CI gates.
verify: smoke scan
	@echo "verify ok"

clean:
	$(COMPOSE) $(COMPOSE_ARGS) down -v 2>/dev/null || true
	-$(CONTAINER_ENGINE) rmi $(APP_IMAGE) $(BASE_IMAGE)
