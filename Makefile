# Local development orchestration for drx-apiserver.
#
# `make base`   — build the reusable drx-apiserver base image locally
# `make app`    — build the reference app on top of it
# `make build`  — alias of `make app`
# `make up`     — bring up the reference app via docker compose (no rebuild)
# `make up-build` — rebuild app image, then bring it up
# `make down`   — stop the reference app
# `make up-base` — run only the base image locally
# `make down-base` — stop the base-only local container
# `make smoke`  — boot the base image and hit its healthcheck
# `make scan`   — run the same Trivy scan CI runs (HIGH/CRITICAL, ignore-unfixed)
# `make verify` — smoke + scan; the minimum check before `git push`
# `make dr-drill` — local disaster-recovery drill (backup + restore from replica)
# `make pit-drill` — local point-in-time drill (TXID-pinned restore from replica)
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
DR_DRILL_HEALTH_TIMEOUT ?= 90
DR_DRILL_SYNC_WAIT      ?= 3

.PHONY: base app build up up-build down up-base down-base smoke scan verify dr-drill pit-drill clean

base:
	$(CONTAINER_ENGINE) build \
		--tag $(BASE_IMAGE) \
		--build-arg DRX_BASE_VERSION=$(VERSION) \
		--build-arg DRX_BASE_VCS_REF=$(VCS_REF) \
		--build-arg DRX_BASE_BUILD_DATE=$(BUILD_DATE) \
		./base

app: base
	APP_IMAGE=$(APP_IMAGE) DRX_BASE_IMAGE=$(BASE_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) build

build: app

up:
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) up --no-build -d

up-build: app up

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

# Local disaster-recovery drill for the reference app stack:
# 1) boot app + minio, 2) write a DB marker,
# 3) stop app gracefully to flush final litestream sync,
# 4) delete local SQLite volume, 5) boot app and verify marker restored.
dr-drill: up-build
	@echo "Starting DR drill (single-machine litestream restore test)"
	@CID="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) ps -q drx-apiserver)"; \
	if [ -z "$$CID" ]; then echo "drx-apiserver container not found"; exit 1; fi; \
	echo "Waiting for backend health..."; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$CID 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "backend unhealthy"; $(CONTAINER_ENGINE) logs $$CID; exit 1; fi; \
		sleep 1; \
	done; \
	marker="drill-$$(date -u +%Y%m%dT%H%M%SZ)"; \
	echo "Writing marker $$marker"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web sql:query \
		"DELETE FROM key_value WHERE collection='drx_drill' AND name='marker'; INSERT INTO key_value (collection, name, value) VALUES ('drx_drill','marker','$$marker');" >/dev/null; \
	echo "Waiting $(DR_DRILL_SYNC_WAIT)s for litestream sync"; \
	sleep $(DR_DRILL_SYNC_WAIT); \
	echo "Stopping backend gracefully"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) stop -t 15 drx-apiserver >/dev/null; \
	echo "Simulating local DB loss"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) rm -f -s -v drx-apiserver >/dev/null 2>&1 || true; \
	$(CONTAINER_ENGINE) volume rm $(COMPOSE_PROJECT)_backend_drupal_db >/dev/null; \
	echo "Recreating backend and restoring from replica"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) up --no-build -d drx-apiserver >/dev/null; \
	CID="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) ps -q drx-apiserver)"; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$CID 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "restored backend healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "restored backend unhealthy"; $(CONTAINER_ENGINE) logs $$CID; exit 1; fi; \
		sleep 1; \
	done; \
	restored="$$(printf "SELECT value FROM key_value WHERE collection='drx_drill' AND name='marker';\n" | APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T drx-apiserver sqlite3 /var/drupal-db/db.sqlite)"; \
	if [ "$$restored" != "$$marker" ]; then \
		echo "DR drill FAILED: expected '$$marker' got '$$restored'"; \
		exit 1; \
	fi; \
	echo "DR drill ok: restored marker $$restored"

# Local point-in-time restore drill:
# 1) boot app + minio, 2) write marker A and capture replica TXID,
# 3) overwrite with marker B, 4) wait for litestream to ship both,
# 5) boot a fresh sidecar container pinned to TXID A and assert it sees A.
# Validates the DRX_LITESTREAM_RESTORE_TXID code path end-to-end.
pit-drill: up-build
	@echo "Starting PIT drill (TXID-pinned litestream restore)"
	@CID="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) ps -q drx-apiserver)"; \
	if [ -z "$$CID" ]; then echo "drx-apiserver container not found"; exit 1; fi; \
	echo "Waiting for backend health..."; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$CID 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "backend unhealthy"; $(CONTAINER_ENGINE) logs $$CID; exit 1; fi; \
		sleep 1; \
	done; \
	echo "Writing marker A"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web sql:query \
		"DELETE FROM key_value WHERE collection='drx_pit'; INSERT INTO key_value (collection, name, value) VALUES ('drx_pit','pin','A');" >/dev/null; \
	sleep $(DR_DRILL_SYNC_WAIT); \
	txid_a="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver litestream status -config /etc/litestream.yml | awk 'NR>1{print $$3}' | tail -1)"; \
	if [ -z "$$txid_a" ]; then echo "failed to capture TXID after marker A"; exit 1; fi; \
	echo "Captured TXID A = $$txid_a"; \
	echo "Overwriting with marker B"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web sql:query \
		"UPDATE key_value SET value='B' WHERE collection='drx_pit' AND name='pin';" >/dev/null; \
	echo "Waiting $(DR_DRILL_SYNC_WAIT)s for litestream to ship both txids"; \
	sleep $(DR_DRILL_SYNC_WAIT); \
	net="$(COMPOSE_PROJECT)_default"; \
	$(CONTAINER_ENGINE) rm -f drx-pit >/dev/null 2>&1 || true; \
	echo "Booting sidecar container pinned to TXID A"; \
	$(CONTAINER_ENGINE) run -d --name drx-pit --network $$net \
		-e DRUPAL_ADMIN_PASS=ignored \
		-e DRX_LITESTREAM_ENABLED=1 \
		-e DRX_LITESTREAM_REPLICA_URL=s3://drx-backups/server \
		-e DRX_LITESTREAM_ENDPOINT=http://minio:9000 \
		-e DRX_LITESTREAM_RESTORE_ON_BOOT=always \
		-e DRX_LITESTREAM_RESTORE_TXID=$$txid_a \
		-e LITESTREAM_ACCESS_KEY_ID=minioadmin \
		-e LITESTREAM_SECRET_ACCESS_KEY=minioadmin \
		$(BASE_IMAGE) >/dev/null; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' drx-pit 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "sidecar healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "sidecar unhealthy"; $(CONTAINER_ENGINE) logs drx-pit; $(CONTAINER_ENGINE) rm -f drx-pit >/dev/null; exit 1; fi; \
		sleep 1; \
	done; \
	restored="$$($(CONTAINER_ENGINE) exec drx-pit sqlite3 /var/drupal-db/db.sqlite \
		"SELECT value FROM key_value WHERE collection='drx_pit' AND name='pin';")"; \
	$(CONTAINER_ENGINE) rm -f drx-pit >/dev/null; \
	if [ "$$restored" != "A" ]; then \
		echo "PIT drill FAILED: expected 'A' got '$$restored'"; \
		exit 1; \
	fi; \
	echo "PIT drill ok: TXID $$txid_a restored marker A"

clean:
	$(COMPOSE) $(COMPOSE_ARGS) down -v 2>/dev/null || true
	-$(CONTAINER_ENGINE) rmi $(APP_IMAGE) $(BASE_IMAGE)
