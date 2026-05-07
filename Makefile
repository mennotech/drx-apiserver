# Local development orchestration for drx-apiserver.
#
# `make base`   — build the reusable drx-drupal-base image locally
# `make app`    — build the reference app on top of it
# `make up`     — bring up the reference app via docker compose
# `make down`   — stop the reference app
# `make smoke`  — boot the base image and hit its healthcheck
# `make scan`   — run the same Trivy scan CI runs (HIGH/CRITICAL, ignore-unfixed)
# `make verify` — smoke + scan; the minimum check before `git push`
# `make clean`  — remove build artifacts and the local image tags

BASE_IMAGE   ?= drx-drupal-base:dev
APP_IMAGE    ?= drx-apiserver:dev
SMOKE_PORT   ?= 8089
VERSION      ?= 0.0.0-dev
VCS_REF      := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
BUILD_DATE   := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)

# Trivy invocation must stay in lock-step with .github/workflows/base-image.yml.
TRIVY_VERSION  ?= 0.70.0
TRIVY_SEVERITY ?= CRITICAL,HIGH

.PHONY: base app up down smoke scan verify clean

base:
	docker build \
		--tag $(BASE_IMAGE) \
		--build-arg DRX_BASE_VERSION=$(VERSION) \
		--build-arg DRX_BASE_VCS_REF=$(VCS_REF) \
		--build-arg DRX_BASE_BUILD_DATE=$(BUILD_DATE) \
		./base

app: base
	DRX_BASE_IMAGE=$(BASE_IMAGE) docker compose build

up: app
	docker compose up -d

down:
	docker compose down

smoke: base
	@docker rm -f drx-smoke >/dev/null 2>&1 || true
	docker run --rm -d --name drx-smoke \
		-e DRUPAL_ADMIN_PASS=smoke-password \
		-p $(SMOKE_PORT):80 $(BASE_IMAGE)
	@echo "Waiting for healthcheck..."
	@for i in $$(seq 1 60); do \
		status=$$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' drx-smoke 2>/dev/null || echo missing); \
		if [ "$$status" = "healthy" ]; then \
			echo "ok after $${i}s"; docker rm -f drx-smoke >/dev/null; exit 0; fi; \
		if [ "$$status" = "exited" ] || [ "$$status" = "dead" ] || [ "$$status" = "missing" ]; then \
			echo "smoke failed early (container status: $$status)"; docker logs drx-smoke || true; docker rm -f drx-smoke >/dev/null 2>&1 || true; exit 1; fi; \
		sleep 2; done; \
	echo "smoke failed"; docker logs drx-smoke || true; docker rm -f drx-smoke >/dev/null 2>&1 || true; exit 1

# Run Trivy with the same gating policy as CI:
#   - severity: CRITICAL,HIGH
#   - ignore-unfixed: true       (only fail on issues with an upstream fix)
#   - exit-code: 1 on findings
# Trivy is run via its official OCI image so contributors don't need to install
# the binary; the image and DB are cached in $$HOME/.cache/trivy.
scan: base
	@mkdir -p $${HOME}/.cache/trivy
	docker run --rm \
		-v /var/run/docker.sock:/var/run/docker.sock \
		-v $${HOME}/.cache/trivy:/root/.cache/ \
		aquasec/trivy:$(TRIVY_VERSION) image \
			--severity $(TRIVY_SEVERITY) \
			--ignore-unfixed \
			--exit-code 1 \
			--no-progress \
			$(BASE_IMAGE)

# Minimum local check before `git push`. Mirrors the CI gates.
verify: smoke scan
	@echo "verify ok"

clean:
	docker compose down -v 2>/dev/null || true
	-docker rmi $(APP_IMAGE) $(BASE_IMAGE)
