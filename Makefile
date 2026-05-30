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
# `make smoke-stack` — boot the full compose stack (app + MinIO) and assert
#                     the seeded JSON:API endpoint returns the expected notes
# `make lint-drupal` — run Drupal + DrupalPractice coding standards on custom
#                       module code in server/modules/custom
# `make scan`   — run the same Trivy scan CI runs (HIGH/CRITICAL, ignore-unfixed)
# `make verify` — smoke + scan; the minimum check before `git push`
# `make dr-drill` — local disaster-recovery drill (DB + files restore from replica/S3)
# `make pit-drill` — local point-in-time drill (TXID-pinned restore from replica)
# `make snapshot-drill` — local application-consistent snapshot drill (drx_litestream)
# `make clean`  — remove build artifacts and the local image tags

BASE_IMAGE   ?= drx-apiserver:dev
APP_IMAGE    ?= drx-apiserver-demo:dev
SMOKE_PORT   ?= 8089
BASE_UP_PORT ?= 8087
BASE_UP_CONTAINER ?= drx-base
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
SMOKE_STACK_TIMEOUT      ?= 180
SHOW_ADMIN_PASS         ?= 0

.PHONY: base app build up up-build down up-base down-base smoke smoke-stack lint-drupal scan verify dr-drill pit-drill snapshot-drill clean

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
	@if [ "$(SHOW_ADMIN_PASS)" = "1" ]; then \
		if [ -n "$(DRUPAL_ADMIN_PASS)" ]; then \
			echo "up admin password (revealed): $(DRUPAL_ADMIN_PASS)"; \
		else \
			echo "up admin password: not set via make variable; compose may source it from .env"; \
		fi; \
	fi
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) up --no-build -d

up-build: app up

down:
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) down

up-base: base
	@$(CONTAINER_ENGINE) rm -f $(BASE_UP_CONTAINER) >/dev/null 2>&1 || true
	@ADMIN_PASS="$(DRUPAL_ADMIN_PASS)"; \
	if [ -z "$$ADMIN_PASS" ]; then \
		ADMIN_PASS="$$(openssl rand -base64 24 | tr -d '\n')"; \
	fi; \
	if [ "$(SHOW_ADMIN_PASS)" = "1" ]; then \
		echo "up-base admin password (revealed): $$ADMIN_PASS"; \
	else \
		echo "up-base admin password: set (hidden). Use SHOW_ADMIN_PASS=1 to reveal."; \
	fi; \
	export ADMIN_PASS; \
	$(CONTAINER_ENGINE) run -d --name $(BASE_UP_CONTAINER) \
		-e DRUPAL_ADMIN_PASS=$$ADMIN_PASS \
		-e DRX_S3_REQUIRED=0 \
		-p $(BASE_UP_PORT):80 $(BASE_IMAGE)

down-base:
	@$(CONTAINER_ENGINE) rm -f $(BASE_UP_CONTAINER) >/dev/null 2>&1 || true

# The healthcheck script is invoked directly via `$(CONTAINER_ENGINE) exec`
# rather than read from `.State.Health.Status`, so this target works the
# same under Docker and under rootless Podman (which does not run
# HEALTHCHECK timers automatically).
smoke: base
	@$(CONTAINER_ENGINE) rm -f drx-smoke >/dev/null 2>&1 || true
	@ADMIN_PASS="$(DRUPAL_ADMIN_PASS)"; \
	if [ -z "$$ADMIN_PASS" ]; then \
		ADMIN_PASS="$$(openssl rand -base64 24 | tr -d '\n')"; \
	fi; \
	if [ "$(SHOW_ADMIN_PASS)" = "1" ]; then \
		echo "smoke admin password (revealed): $$ADMIN_PASS"; \
	else \
		echo "smoke admin password: set (hidden). Use SHOW_ADMIN_PASS=1 to reveal."; \
	fi; \
	$(CONTAINER_ENGINE) run --rm -d --name drx-smoke \
		-e DRUPAL_ADMIN_PASS=$$ADMIN_PASS \
		-e DRX_S3_REQUIRED=0 \
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

# Run Drupal coding standards (Drupal + DrupalPractice) against project custom
# modules only. This target is intentionally containerized so contributors do
# not need host PHP/Composer installs.
lint-drupal:
	@mkdir -p "$${HOME}/.cache/composer"
	@$(CONTAINER_ENGINE) run --rm \
		-v "$$(pwd):/work" \
		-v "$${HOME}/.cache/composer:/tmp/composer-cache" \
		-e COMPOSER_CACHE_DIR=/tmp/composer-cache \
		-w /tmp composer:2 sh -lc '\
			set -eu; \
			mkdir -p /tmp/drupal-cs && cd /tmp/drupal-cs; \
			composer init --no-interaction --name drx/drupal-cs --type project >/dev/null; \
			composer config --no-interaction allow-plugins.dealerdirect/phpcodesniffer-composer-installer true >/dev/null; \
			composer require --no-interaction drupal/coder:^8.3 >/dev/null; \
			./vendor/bin/phpcs --standard=/work/phpcs.xml.dist /work/server/modules/custom'

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

# Full-stack end-to-end test against MinIO.
#
# Boots the full compose stack (drx-apiserver + minio + minio-init),
# waits for the backend healthcheck to report `healthy`, then asserts:
#   * GET /jsonapi/node/note returns exactly 3 seeded notes
#   * The bootstrap log shows a successful S3 probe and s3fs module enable
# Tears the stack down on success or failure. This is the recommended
# pre-push check for any change touching server/ or the bootstrap pipeline.
smoke-stack: base
	@set -e; \
	if [ -n "$(DRUPAL_ADMIN_PASS)" ]; then \
		DRUPAL_ADMIN_PASS="$(DRUPAL_ADMIN_PASS)"; \
	else \
		DRUPAL_ADMIN_PASS="$$(openssl rand -base64 24 | tr -d '\n')"; \
	fi; \
	export DRUPAL_ADMIN_PASS; \
	if [ "$(SHOW_ADMIN_PASS)" = "1" ]; then \
		echo "smoke-stack admin password (revealed): $$DRUPAL_ADMIN_PASS"; \
	else \
		echo "smoke-stack admin password: set (hidden). Use SHOW_ADMIN_PASS=1 to reveal."; \
	fi; \
	APP_IMAGE=$(APP_IMAGE) \
		$(COMPOSE) $(COMPOSE_ARGS) down -v >/dev/null 2>&1 || true; \
	echo "Building images..."; \
	APP_IMAGE=$(APP_IMAGE) DRX_BASE_IMAGE=$(BASE_IMAGE) \
		$(COMPOSE) $(COMPOSE_ARGS) build >/dev/null; \
	echo "Starting full stack..."; \
	APP_IMAGE=$(APP_IMAGE) \
		$(COMPOSE) $(COMPOSE_ARGS) up --no-build -d >/dev/null; \
	trap 'rc=$$?; if [ $$rc -ne 0 ]; then echo "--- drx-apiserver logs (tail) ---"; APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) logs --tail=120 drx-apiserver || true; fi; APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) down -v >/dev/null 2>&1 || true; exit $$rc' EXIT; \
	echo "Waiting up to $(SMOKE_STACK_TIMEOUT)s for backend health..."; \
	CID="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) ps -q drx-apiserver)"; \
	[ -n "$$CID" ] || { echo "drx-apiserver container not found"; exit 1; }; \
	for i in $$(seq 1 $(SMOKE_STACK_TIMEOUT)); do \
		if $(CONTAINER_ENGINE) exec $$CID /usr/local/bin/drx-healthcheck >/dev/null 2>&1; then \
			echo "backend healthy after $${i}s"; break; \
		fi; \
		if [ $$i -eq $(SMOKE_STACK_TIMEOUT) ]; then echo "backend not healthy in $(SMOKE_STACK_TIMEOUT)s"; exit 1; fi; \
		sleep 1; \
	done; \
	echo "Asserting S3 probe succeeded in bootstrap log..."; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) logs drx-apiserver 2>&1 | grep -qE 's3_probe: ok' \
		|| { echo "FAIL: no successful S3 probe in logs"; exit 1; }; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) logs drx-apiserver 2>&1 | grep -qE 's3: enabling s3fs module' \
		|| { echo "FAIL: s3fs module was not enabled"; exit 1; }; \
	echo "Asserting JSON:API returns seeded notes..."; \
	body="$$($(CONTAINER_ENGINE) exec $$CID curl -fsS http://127.0.0.1/jsonapi/node/note)"; \
	count="$$(printf '%s' "$$body" | grep -oE '"type":"node--note"' | wc -l | tr -d ' ')"; \
	if [ "$$count" -ne 3 ]; then \
		echo "FAIL: expected exactly 3 seeded notes, got $$count"; \
		printf '%s\n' "$$body" | head -c 400; echo; \
		exit 1; \
	fi; \
	echo "smoke-stack ok: backend healthy, S3 probe ok, s3fs enabled, $$count notes via JSON:API"

# Local disaster-recovery drill for the reference app stack:
# 1) boot app + minio, 2) write a DB marker + public file marker,
# 3) stop app gracefully to flush final litestream sync,
# 4) remove backend container (drops local writable layer),
# 5) boot app and verify both markers restored.
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
	file_name="dr-drill-$$marker.txt"; \
	file_body="dr-drill-file-$$marker"; \
	echo "Writing DB marker $$marker and file marker $$file_name"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web sql:query \
		"DELETE FROM key_value WHERE collection='drx_drill' AND name='marker'; INSERT INTO key_value (collection, name, value) VALUES ('drx_drill','marker','$$marker');" >/dev/null; \
	DRX_DRILL_FILE_NAME="$$file_name" DRX_DRILL_FILE_BODY="$$file_body" APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web php:eval "\Drupal::service('file.repository')->writeData(getenv('DRX_DRILL_FILE_BODY'), 'public://' . getenv('DRX_DRILL_FILE_NAME'), \Drupal\Core\File\FileExists::Replace); print 'ok';" >/dev/null; \
	pre_file="$$(DRX_DRILL_FILE_NAME="$$file_name" APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web php:eval "print file_get_contents('public://' . getenv('DRX_DRILL_FILE_NAME'));" 2>/dev/null)"; \
	if [ "$$pre_file" != "$$file_body" ]; then \
		echo "DR drill FAILED before restart: expected file body '$$file_body' got '$$pre_file'"; \
		exit 1; \
	fi; \
	echo "Waiting $(DR_DRILL_SYNC_WAIT)s for litestream sync"; \
	sleep $(DR_DRILL_SYNC_WAIT); \
	echo "Stopping backend gracefully"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) stop -t 15 drx-apiserver >/dev/null; \
	echo "Simulating local DB loss (ephemeral container layer)"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) rm -f -s -v drx-apiserver >/dev/null 2>&1 || true; \
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
	restored_file="$$(DRX_DRILL_FILE_NAME="$$file_name" APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web php:eval "print file_get_contents('public://' . getenv('DRX_DRILL_FILE_NAME'));" 2>/dev/null)"; \
	if [ "$$restored_file" != "$$file_body" ]; then \
		echo "DR drill FAILED: expected restored file body '$$file_body' got '$$restored_file'"; \
		exit 1; \
	fi; \
	echo "DR drill ok: restored DB marker $$restored and file marker $$file_name"

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
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver litestream sync -config /etc/litestream.yml -wait -timeout 30s /var/drupal-db/db.sqlite >/dev/null 2>&1 || true; \
	txid_a="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver litestream ltx -config /etc/litestream.yml -level all /var/drupal-db/db.sqlite | awk '/^[[:space:]]*[0-9a-fA-F]{16}[[:space:]]+[0-9a-fA-F]{16}/{print $$2}' | sort | tail -1)"; \
	if [ -z "$$txid_a" ]; then echo "failed to capture LTX TXID after marker A"; exit 1; fi; \
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
		-e DRX_S3_REQUIRED=1 \
		-e DRX_S3_BUCKET=drx-data-local \
		-e DRX_S3_REGION=us-east-1 \
		-e DRX_S3_ENDPOINT=http://minio:9000 \
		-e DRX_S3_ACCESS_KEY_ID=minioadmin \
		-e DRX_S3_SECRET_ACCESS_KEY=minioadmin \
		-e DRX_LITESTREAM_RESTORE_ON_BOOT=always \
		-e DRX_LITESTREAM_RESTORE_TXID=$$txid_a \
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

# Application-consistent snapshot drill:
# 1) boot app + minio,
# 2) call `drush drx:litestream:snapshot` (orchestrator quiesces + captures),
# 3) verify the marker row carries kind='consistent' + a TXID,
# 4) write a POST-snapshot marker B that should NOT appear in restore,
# 5) boot a sidecar pinned to the snapshot TXID,
# 6) assert the snapshot marker row is present and marker B is absent.
# Proves the maintenance/checkpoint/wait consistency boundary is real.
#
# Set SKIP_BUILD=1 to reuse already-built images (much faster on
# iterative runs that only touch hooks/, server/modules/, or the
# Makefile itself — i.e. anything that doesn't change the Dockerfile
# or composer.json).
snapshot-drill: $(if $(SKIP_BUILD),up,up-build)
	@echo "Starting snapshot drill (drx_litestream consistent capture)"
	@CID="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) ps -q drx-apiserver)"; \
	if [ -z "$$CID" ]; then echo "drx-apiserver container not found"; exit 1; fi; \
	echo "Waiting for backend health..."; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' $$CID 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "backend unhealthy"; $(CONTAINER_ENGINE) logs $$CID; exit 1; fi; \
		sleep 1; \
	done; \
	label="snap-$$(date -u +%Y%m%dT%H%M%SZ)"; \
	echo "Capturing consistent snapshot label=$$label"; \
	snap_log="$$(mktemp)"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web drx:litestream:snapshot --label=$$label >"$$snap_log" 2>&1 || true; \
	txid="$$(grep -Eo '^[0-9a-f]{16}$$' "$$snap_log" | tail -1)"; \
	if [ -z "$$txid" ]; then \
		echo "snapshot drill FAILED: no TXID returned"; \
		echo "--- drush output ---"; cat "$$snap_log"; echo "--- end ---"; \
		rm -f "$$snap_log"; exit 1; \
	fi; \
	rm -f "$$snap_log"; \
	echo "Captured snapshot TXID = $$txid"; \
	kind="$$(APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T drx-apiserver sqlite3 /var/drupal-db/db.sqlite \
		"SELECT kind FROM drx_litestream_marker WHERE label='$$label';")"; \
	if [ "$$kind" != "consistent" ]; then echo "snapshot drill FAILED: expected kind=consistent got '$$kind'"; exit 1; fi; \
	echo "Writing POST-snapshot marker B that must NOT survive restore"; \
	APP_IMAGE=$(APP_IMAGE) $(COMPOSE) $(COMPOSE_ARGS) exec -T -u www-data drx-apiserver /var/www/html/vendor/bin/drush --root=/var/www/html/web sql:query \
		"DELETE FROM key_value WHERE collection='drx_snap'; INSERT INTO key_value (collection, name, value) VALUES ('drx_snap','postsnap','B');" >/dev/null; \
	sleep $(DR_DRILL_SYNC_WAIT); \
	net="$(COMPOSE_PROJECT)_default"; \
	$(CONTAINER_ENGINE) rm -f drx-snap-pit >/dev/null 2>&1 || true; \
	echo "Booting sidecar pinned to snapshot TXID"; \
	$(CONTAINER_ENGINE) run -d --name drx-snap-pit --network $$net \
		-e DRUPAL_ADMIN_PASS=ignored \
		-e DRX_S3_REQUIRED=1 \
		-e DRX_S3_BUCKET=drx-data-local \
		-e DRX_S3_REGION=us-east-1 \
		-e DRX_S3_ENDPOINT=http://minio:9000 \
		-e DRX_S3_ACCESS_KEY_ID=minioadmin \
		-e DRX_S3_SECRET_ACCESS_KEY=minioadmin \
		-e DRX_LITESTREAM_RESTORE_ON_BOOT=always \
		-e DRX_LITESTREAM_RESTORE_TXID=$$txid \
		$(BASE_IMAGE) >/dev/null; \
	for i in $$(seq 1 $(DR_DRILL_HEALTH_TIMEOUT)); do \
		status="$$( $(CONTAINER_ENGINE) inspect --format='{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' drx-snap-pit 2>/dev/null || true )"; \
		if [ "$$status" = "healthy" ]; then echo "sidecar healthy after $${i}s"; break; fi; \
		if [ "$$status" = "unhealthy" ]; then echo "sidecar unhealthy"; $(CONTAINER_ENGINE) logs drx-snap-pit; $(CONTAINER_ENGINE) rm -f drx-snap-pit >/dev/null; exit 1; fi; \
		sleep 1; \
	done; \
	restored_label="$$($(CONTAINER_ENGINE) exec drx-snap-pit sqlite3 /var/drupal-db/db.sqlite \
		"SELECT label FROM drx_litestream_marker WHERE label='$$label';")"; \
	restored_post="$$($(CONTAINER_ENGINE) exec drx-snap-pit sqlite3 /var/drupal-db/db.sqlite \
		"SELECT value FROM key_value WHERE collection='drx_snap' AND name='postsnap';")"; \
	if [ "$$restored_label" != "$$label" ]; then \
		echo "snapshot drill FAILED: snapshot marker row missing in restore (expected '$$label' got '$$restored_label')"; \
		echo "--- sidecar marker dump ---"; \
		$(CONTAINER_ENGINE) exec drx-snap-pit sqlite3 /var/drupal-db/db.sqlite "SELECT id,label,kind,txid,verify_state FROM drx_litestream_marker;" || true; \
		echo "--- sidecar last 30 log lines ---"; \
		$(CONTAINER_ENGINE) logs --tail 30 drx-snap-pit || true; \
		$(CONTAINER_ENGINE) rm -f drx-snap-pit >/dev/null; \
		exit 1; \
	fi; \
	$(CONTAINER_ENGINE) rm -f drx-snap-pit >/dev/null; \
	if [ -n "$$restored_post" ]; then \
		echo "snapshot drill FAILED: post-snapshot data leaked into restore (got '$$restored_post')"; \
		exit 1; \
	fi; \
	echo "snapshot drill ok: marker row '$$label' present, post-snapshot mutation absent (TXID $$txid)"

clean:
	$(COMPOSE) $(COMPOSE_ARGS) down -v 2>/dev/null || true
	-$(CONTAINER_ENGINE) rmi $(APP_IMAGE) $(BASE_IMAGE)
