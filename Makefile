# Local development orchestration for drx-apiserver.
#
# `make base`   — build the reusable drx-drupal-base image locally
# `make app`    — build the reference app on top of it
# `make up`     — bring up the reference app via docker compose
# `make down`   — stop the reference app
# `make smoke`  — boot the base image and hit its healthcheck
# `make clean`  — remove build artifacts and the local image tags

BASE_IMAGE ?= drx-drupal-base:dev
APP_IMAGE  ?= drx-apiserver:dev
SMOKE_PORT ?= 8089
VERSION    ?= 0.0.0-dev
VCS_REF    := $(shell git rev-parse --short HEAD 2>/dev/null || echo unknown)
BUILD_DATE := $(shell date -u +%Y-%m-%dT%H:%M:%SZ)

.PHONY: base app up down smoke clean

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

clean:
	docker compose down -v 2>/dev/null || true
	-docker rmi $(APP_IMAGE) $(BASE_IMAGE)
