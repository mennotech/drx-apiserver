# drx-drupal-base

A reusable, production-oriented Drupal 10 base image for projects that use
Drupal as the data + auth + security backend behind a decoupled frontend.

This image is deliberately neutral: no project-specific modules, branding,
config payload, or platform-specific deployment behaviour is baked in.
Downstream projects extend it through documented extension points.

---

## What the image gives you

- Drupal 10.3 + Drush 12 installed under `/var/www/html` via Composer.
- Apache 2.4 with `rewrite`, `headers`, `expires` enabled and hardened
  defaults (`ServerTokens Prod`, `TraceEnable Off`, no `WWW-Authenticate`).
- PHP 8.3 with extensions required by Drupal core (`gd`, `intl`, `opcache`,
  `pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`, `xml`, `zip`).
- A composable bootstrap orchestrator at `/usr/local/bin/drx-init` that
  generates `settings.php`, `services.yml`, and trusted-host patterns from
  runtime environment variables on every boot, installs Drupal on first
  run, runs hash-gated `config:import`, and applies API policy.
- Lifecycle hook directories under `/etc/drx/hooks/<phase>.d/` for
  downstream extension without modifying the image internals.
- A `tini`-managed PID 1 and a built-in HTTP healthcheck.

## What the image does **not** do

- It does not pick a database for your project. SQLite is the default for
  zero-config dev; MySQL/PostgreSQL drivers are present and supported via
  `DRUPAL_DB_DRIVER`, but the image does not run any external DB.
- It does not enable JSON:API write mode by default. Decoupled write APIs
  must be opted into per project (`DRUPAL_JSONAPI_READ_ONLY=0`).
- It does not assume a deployment platform. Fly.io, Kubernetes, Compose,
  Nomad, etc. are all supported through the same env contract; example
  overlays live with downstream projects, not in this base image.
- It does not bundle any project's custom modules, content types, or
  config sync payload.

---

## Tags and versioning

The image is published with the following tag conventions:

| Tag           | Mutability | Use                                                |
| ------------- | ---------- | -------------------------------------------------- |
| `X.Y.Z`       | immutable  | Pin in production. Recommended for downstream use. |
| `X.Y`         | floating   | Latest patch within a minor.                       |
| `X`           | floating   | Latest minor within a major.                       |
| `latest`      | floating   | Latest stable. Avoid in production.                |
| `X.Y.Z-rcN`   | immutable  | Release candidate.                                 |
| `edge`        | floating   | Tip of `main`. Not for production.                 |

Versioning follows SemVer with respect to the **runtime contract** (env
vars, hook lifecycle, on-disk layout, supported DB drivers, default
behaviours), not internal implementation details. Drupal core minor and
patch updates flow through as base-image patch or minor releases unless
they break the documented contract.

Each tagged release publishes:

- The image itself.
- An SBOM and provenance attestation (target: SLSA build L2).
- A short changelog focused on contract changes, Drupal/PHP updates, and
  CVE-relevant fixes.

---

## Runtime environment contract

All variables are optional except where marked.

### Site identity

| Variable                  | Default      | Notes                                                  |
| ------------------------- | ------------ | ------------------------------------------------------ |
| `DRUPAL_ADMIN_USER`       | `admin`      |                                                        |
| `DRUPAL_ADMIN_PASS`       | _(unset)_    | **Required** before first install.                     |
| `DRUPAL_SITE_NAME`        | `Drupal`     | Used only at first install.                            |
| `DRUPAL_INSTALL_PROFILE`  | `standard`   | Any profile present in the image is valid.             |
| `DRUPAL_HOSTNAME`         | _(derived)_  | Overrides Apache `ServerName` and trusted-host derivation. |

### Public URLs and CORS

| Variable                | Default                  | Notes                                              |
| ----------------------- | ------------------------ | -------------------------------------------------- |
| `BACKEND_URL`           | `http://localhost`       | Drives ServerName + trusted host pattern.          |
| `FRONTEND_URL`          | `http://localhost:3000`  | Default CORS allowed origin if no override.        |
| `CORS_ALLOWED_ORIGINS`  | _(unset)_                | Comma-separated list. Overrides `FRONTEND_URL`.    |
| `DRUPAL_TRUSTED_HOST_PATTERNS` | _(unset)_         | Comma-separated regex strings appended to defaults.|

### Database

| Variable             | Default                     | Notes                                                          |
| -------------------- | --------------------------- | -------------------------------------------------------------- |
| `DRUPAL_DB_DRIVER`   | `sqlite`                    | One of `sqlite`, `mysql`, `pgsql`.                             |
| `DRUPAL_SQLITE_PATH` | `/var/drupal-db/db.sqlite`  | SQLite only. Path must be on a writable volume.                |
| `DRUPAL_DB_HOST`     | _(unset)_                   | Required for `mysql`/`pgsql`.                                  |
| `DRUPAL_DB_PORT`     | _(unset)_                   | Optional.                                                      |
| `DRUPAL_DB_NAME`     | _(unset)_                   | Required for `mysql`/`pgsql`.                                  |
| `DRUPAL_DB_USER`     | _(unset)_                   | Required for `mysql`/`pgsql`.                                  |
| `DRUPAL_DB_PASS`     | _(unset)_                   | Required for `mysql`/`pgsql`. Read from secret store.          |

### Files / state

| Variable               | Default                | Notes                                                                  |
| ---------------------- | ---------------------- | ---------------------------------------------------------------------- |
| `DRUPAL_FILES_TARGET`  | _(unset)_              | If set, `sites/default/files` becomes a symlink to this path.          |
| `DRUPAL_STATE_DIR`     | `/var/drupal-db`       | Holds SQLite DB and the config-import hash file.                       |

### Modules and API policy

| Variable                       | Default                                          | Notes                                                                     |
| ------------------------------ | ------------------------------------------------ | ------------------------------------------------------------------------- |
| `DRUPAL_BASE_MODULES`          | `jsonapi serialization basic_auth rest`          | Enabled before config import.                                             |
| `DRUPAL_EXTRA_MODULES`         | _(empty)_                                        | Enabled after config import. Use for modules with config dependencies.    |
| `DRUPAL_CONFIG_SYNC_DIR`       | `/var/www/html/config/sync`                      | Ignored if directory empty.                                               |
| `DRUPAL_CONFIG_IMPORT_MODE`    | `partial`                                        | Set to `full` to fail boot on missing dependencies.                       |
| `DRUPAL_JSONAPI_READ_ONLY`     | `1`                                              | Set to `0` to enable JSON:API write mode (project opt-in).                |

### PHP runtime tuning

The image ships with production-tuned defaults
(`memory_limit=256M`, `opcache.validate_timestamps=0`, `expose_php=Off`,
strict secure session cookies). Downstream projects override these by
dropping additional `.ini` files into `/usr/local/etc/php/conf.d/` from
their own Dockerfile, e.g.:

```dockerfile
RUN echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/99-overrides.ini
```

### Operational toggles

| Variable             | Default | Notes                                                       |
| -------------------- | ------- | ----------------------------------------------------------- |
| `DRX_DISABLE_INIT`   | `0`     | Set to `1` to skip bootstrap (CLI / shell containers).      |
| `DRX_HEALTHCHECK_PORT` | `80`  | Healthcheck target port.                                    |
| `DRX_HEALTHCHECK_PATH` | `/user/login_status?_format=json` | Healthcheck target path.        |

---

## Filesystem contract

| Path                                       | Purpose                          | Writable? |
| ------------------------------------------ | -------------------------------- | --------- |
| `/var/www/html`                            | Drupal application root.         | No (R/O safe). |
| `/var/www/html/web/sites/default/files`    | Drupal public files.             | **Yes** — mount a volume. |
| `/var/drupal-db` (`DRUPAL_STATE_DIR`)      | SQLite DB + config import hash.  | **Yes** — mount a volume. |
| `/var/www/html/config/sync`                | Config sync source.              | No. |
| `/etc/drx/hooks/<phase>.d/`                | Downstream lifecycle hooks.      | No. |
| `/usr/local/lib/drx/`                      | Bootstrap library modules.       | No. |
| `/tmp` and `/var/log/apache2`              | Runtime ephemeral.               | Yes (tmpfs in production recommended). |

The image is intended to run with a read-only root filesystem if writable
volumes/tmpfs cover the paths marked **Yes**.

---

## Lifecycle hooks

Place shell scripts in `/etc/drx/hooks/<phase>.d/` to extend behaviour.
They are **sourced** in lexical order inside isolated subshells, so they
inherit the bootstrap environment and helpers (`drx::drush`, `drx::log`,
`drx::warn`) defined in `${DRX_LIB_DIR}/common.sh`. Hook files do not
need to be executable.

```
pre-bootstrap.d/        # Before storage / settings / install.
post-install.d/         # After Drupal site install or verification.
post-config-import.d/   # After config:import succeeds.
post-modules.d/         # After base + extra module enablement.
post-bootstrap.d/       # Just before Apache starts.
```

Example downstream hook (`/etc/drx/hooks/post-config-import.d/40-myapp.sh`):

```bash
#!/bin/bash
set -euo pipefail
# Enable an app-specific module that depends on imported content types.
drx::drush pm:enable --yes myapp_module
```

A non-zero exit from a hook aborts bootstrap.

---

## Extending the image

```dockerfile
ARG BASE_IMAGE=ghcr.io/mennotech/drx-drupal-base:0.1.0
FROM ${BASE_IMAGE}

# Custom modules.
COPY --chown=www-data:www-data modules/   /var/www/html/web/modules/custom/

# Project config sync payload.
COPY --chown=www-data:www-data config/    /var/www/html/config/

# Lifecycle hooks. Scripts must be executable.
COPY --chmod=0755 hooks/                  /etc/drx/hooks/
```

Downstream projects should:

1. Pin `BASE_IMAGE` to an immutable `X.Y.Z` tag in production.
2. Track the base image's changelog for contract changes before bumping.
3. Keep deployment platform specifics (Fly.io secrets, K8s manifests,
   Compose files) in their own repo, not inside the base image.

---

## Security posture

- Apache and PHP-FPM run as `www-data`; `drush` and `composer` are always
  invoked via `sudo -u www-data` from the bootstrap orchestrator.
- `settings.php`, `services.yml`, and `trusted-hosts.settings.php` are
  written `0440 www-data:www-data` after generation.
- JSON:API defaults to read-only.
- HTTP `Server` and `WWW-Authenticate` headers are suppressed.
- The image is built from a pinned PHP base image digest; releases include
  SBOM + provenance.

Report security issues privately to the maintainer; do not file public
issues for embargoed CVEs.
