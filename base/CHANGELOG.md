# Changelog — drx-drupal-base

All notable changes to the reusable base image are documented here.
The base image follows semantic versioning with respect to its **runtime
contract**: env vars, hook lifecycle, on-disk paths, supported DB drivers,
and default behaviours. Drupal core minor and patch updates flow through
as base-image patch or minor releases unless they break this contract.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.0.1-rc1] - 2026-05-07

First public preview of `drx-drupal-base`. The runtime contract is
**not yet stabilized**; treat any `0.0.x` release as a preview. Floating
tags (`X.Y`, `X`, `latest`) are not moved by pre-releases — pin to
`0.0.1-rc1` explicitly to consume this build.

### Added

#### Image baseline
- Reusable, production-oriented Drupal 10 base image
  (`drx-drupal-base`), distributed via GHCR.
- Two-stage `base/Dockerfile` (builder + runtime) on a **pinned PHP
  base image digest** (`php:8.3-apache-bookworm`), built multi-arch
  (`linux/amd64`, `linux/arm64`).
- Drupal 10.3 + Drush 12 installed under `/var/www/html` via Composer
  (`base/composer.json`).
- Apache 2.4 with `rewrite`, `headers`, and `expires` modules enabled.
- PHP 8.3 with the extensions required by Drupal core: `gd`, `intl`,
  `opcache`, `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `xml`, `zip`.
- `tini` as PID 1 for clean signal handling.
- OCI image labels: `org.opencontainers.image.{title,description,
  source,licenses,version,revision,created}`.

#### Bootstrap orchestrator
- Composable bootstrap entrypoint at `/usr/local/bin/drx-init`.
- Bootstrap decomposed into a library under
  `${DRX_LIB_DIR}=/usr/local/lib/drx/`:
  `common.sh`, `storage.sh`, `settings.sh`, `services.sh`, `install.sh`,
  `modules.sh`, `config_import.sh`, `api.sh`.
- Helpers exposed to hooks: `drx::drush`, `drx::log`, `drx::warn`.
- `DRX_DISABLE_INIT=1` short-circuits the bootstrap for CLI / shell
  containers.
- First-run install, hash-gated `config:import`
  (`DRUPAL_CONFIG_IMPORT_MODE=partial|full`), base + extra module
  enablement (`DRUPAL_BASE_MODULES`, `DRUPAL_EXTRA_MODULES`), and API
  policy application on every boot.

#### Runtime contract
- Site identity env vars: `DRUPAL_ADMIN_USER`, `DRUPAL_ADMIN_PASS`
  (required before first install), `DRUPAL_SITE_NAME`,
  `DRUPAL_INSTALL_PROFILE`, `DRUPAL_HOSTNAME`.
- Public-URL / CORS env vars: `BACKEND_URL`, `FRONTEND_URL`,
  `CORS_ALLOWED_ORIGINS`, `DRUPAL_TRUSTED_HOST_PATTERNS`.
- Multi-driver database contract via `DRUPAL_DB_DRIVER`
  (`sqlite` | `mysql` | `pgsql`) with `DRUPAL_DB_HOST`,
  `DRUPAL_DB_PORT`, `DRUPAL_DB_NAME`, `DRUPAL_DB_USER`,
  `DRUPAL_DB_PASS`, and `DRUPAL_SQLITE_PATH`. SQLite is the
  zero-config default.
- Files / state env vars: `DRUPAL_FILES_TARGET` (symlink target for
  `sites/default/files`), `DRUPAL_STATE_DIR`
  (default `/var/drupal-db`).
- Modules / API policy env vars: `DRUPAL_BASE_MODULES`,
  `DRUPAL_EXTRA_MODULES`, `DRUPAL_CONFIG_SYNC_DIR`,
  `DRUPAL_CONFIG_IMPORT_MODE`, `DRUPAL_JSONAPI_READ_ONLY`.
- Operational toggles: `DRX_DISABLE_INIT`, `DRX_HEALTHCHECK_PORT`,
  `DRX_HEALTHCHECK_PATH`.
- Trusted-host pattern generation derived from `BACKEND_URL` +
  `DRUPAL_TRUSTED_HOST_PATTERNS`.
- Built-in HTTP healthcheck at `/usr/local/bin/drx-healthcheck`.

#### Lifecycle hooks
- Hook directories under `/etc/drx/hooks/<phase>.d/` for downstream
  extension without modifying image internals:
  `pre-bootstrap.d/`, `post-install.d/`, `post-config-import.d/`,
  `post-modules.d/`, `post-bootstrap.d/`.
- Hook scripts are **sourced** in lexical order inside isolated
  subshells so they inherit bootstrap env and helpers; non-zero exit
  aborts bootstrap.

#### Hardening
- Apache: `ServerTokens Prod`, `ServerSignature Off`, `TraceEnable Off`,
  suppressed `WWW-Authenticate` header.
- PHP: production-tuned defaults (`memory_limit=256M`,
  `opcache.validate_timestamps=0`, `expose_php=Off`, strict secure
  session cookies).
- Generated configuration files (`settings.php`, `services.yml`,
  `trusted-hosts.settings.php`) written `0440 www-data:www-data`.
- `drush` and `composer` always invoked via `sudo -u www-data` from
  the bootstrap orchestrator.
- JSON:API defaults to **read-only**; downstream projects opt into
  write mode via `DRUPAL_JSONAPI_READ_ONLY=0` or a
  `post-config-import.d/` hook.

### Changed
- Drupal install no longer assumes Fly.io; storage strategy is driven by
  `DRUPAL_FILES_TARGET` and is platform-neutral.
- JSON:API defaults to **read-only**. Downstream projects opt into write
  mode via `DRUPAL_JSONAPI_READ_ONLY=0` or a `post-config-import.d/` hook.
- Apache hardened: `ServerTokens Prod`, `ServerSignature Off`,
  `TraceEnable Off`.

### Fixed
- Builder stage now installs `libpq-dev` so the `pdo_pgsql` PHP
  extension compiles successfully (`libpq5` alone at runtime is
  insufficient at build time).
