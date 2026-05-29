# drx-apiserver (base image)

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
| `edge`        | n/a        | Not published by this workflow.                    |

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
| `DRUPAL_INSTALL_PROFILE`  | `minimal`    | Any profile present in the image is valid. `minimal` keeps the base neutral; set `standard` to opt into Drupal's default Article/Tags/comment scaffolding. |
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
| `DRUPAL_BASE_MODULES`          | `config jsonapi serialization basic_auth rest`   | Enabled before config import. `config` is required for `drush config:import --partial`. |
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

### Site timezone

| Variable | Default | Notes |
| -------- | ------- | ----- |
| `DRX_TIMEZONE` | _(unset)_ | Sets the Drupal site timezone. If unset during a fresh install, the bootstrap tries a public-IP lookup once and falls back to `UTC`. On later boots, the site config is only updated when this env var differs from the current Drupal config. |

### Shared S3 storage (mandatory by default)

The image treats S3 as the source of truth for user content and (when
Litestream is enabled) the database replica. The container filesystem is
treated as ephemeral. One bucket and one credential pair are shared
between Litestream and Drupal's file backend (`drupal/s3fs`); four
prefixes separate concerns inside the bucket:

| Prefix                                | Contents                       | Visibility                                              |
| ------------------------------------- | ------------------------------ | ------------------------------------------------------- |
| `${DRX_S3_PREFIX_LITESTREAM}/`        | Litestream SQLite replica      | Private. Never exposed.                                  |
| `${DRX_S3_PREFIX_PRIVATE}/`           | Drupal **private://** files    | Private. Streamed through Drupal access checks.          |
| `${DRX_S3_PREFIX_PUBLIC}/`            | Drupal **public://** files     | Anonymous read, via an explicit bucket policy on this prefix only. |
| `${DRX_S3_PREFIX_JOURNAL}/`           | Immutable file-change journal  | Private. Used by `drx_s3_journal` when present in downstream overlays. |

Security posture is **private by default**. Public access exists only
because the bucket policy explicitly grants `s3:GetObject` on the public
prefix; any other path is deny-by-default. Field-level privacy is still
honoured — file/image fields marked private upload into the private
prefix and require a Drupal-issued URL to read.

Bucket versioning is required when `DRX_S3_REQUIRED=1`. Bootstrap now
checks versioning at startup and fails fast if the bucket is un-versioned,
because Litestream-based DR workflows depend on object version history.

`DRX_S3_REQUIRED=1` (default) blocks boot unless the env contract is
populated and a SigV4-signed `HEAD bucket` probe succeeds. Set
`DRX_S3_REQUIRED=0` only for CI smoke tests where no live S3 is
available; in that mode `drupal/s3fs` is left disabled and uploads fall
back to the local filesystem.

Production posture: set `DRX_S3_BUCKET` explicitly (for example via your
deployment environment or IaC). The base image intentionally does not
default this variable when S3 is required. The reference local compose
overlay provides a dev-only fallback of `drx-data-local`.

| Variable                          | Default       | Notes                                                                                 |
| --------------------------------- | ------------- | ------------------------------------------------------------------------------------- |
| `DRX_S3_REQUIRED`                 | `1`           | Master switch. `0` disables validation, probe, and `s3fs` module enable.              |
| `DRX_S3_BUCKET`                   | _(unset)_     | **Required when enabled.** Single bucket shared by all four prefixes.                 |
| `DRX_S3_REGION`                   | `us-east-1`   | S3 region.                                                                            |
| `DRX_S3_ENDPOINT`                 | _(unset)_     | Optional. Custom endpoint for MinIO / S3-compatible stores.                           |
| `DRX_S3_PUBLIC_HOST`              | _(unset)_     | Optional. Browser-facing `host[:port]` for public file URLs (s3fs CNAME). Must use a hostname different from the Drupal site host — Drupal's file URL generator compares hosts without ports, so `localhost:9000` against a site on `localhost:8088` is still treated as local and rewritten to an internal path. Use a distinct dev alias like `minio.127.0.0.1.nip.io:9000` or a hosts-file entry. |
| `DRX_S3_FORCE_PATH_STYLE`         | _(auto)_      | Auto `true` when an endpoint is set, otherwise `false`. Override with `true`/`false`. |
| `DRX_S3_ACCESS_KEY_ID`            | _(unset)_     | **Required when enabled.** Canonical S3 access key used by both s3fs and Litestream. |
| `DRX_S3_SECRET_ACCESS_KEY`        | _(unset)_     | **Required when enabled.**                                                            |
| `DRX_S3_PREFIX_LITESTREAM`        | `litestream`  | Prefix for Litestream replicas.                                                       |
| `DRX_S3_PREFIX_PRIVATE`           | `private`     | Prefix for Drupal private files.                                                      |
| `DRX_S3_PREFIX_PUBLIC`            | `public`      | Prefix for Drupal public files (must be matched by the bucket policy below).          |
| `DRX_S3_PREFIX_JOURNAL`           | `journal/v1`  | Prefix for immutable file-change journal events (used by downstream `drx_s3_journal`). |

#### Bucket policy (AWS S3)

The bucket must use **Bucket owner enforced** ownership (ACLs disabled).
Leave "Block public bucket policies" **off** in Block Public Access, then
attach this policy (replacing `<bucket>` and `<DRX_S3_PREFIX_PUBLIC>`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowPublicReadOnPublicPrefix",
      "Effect": "Allow",
      "Principal": "*",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::<bucket>/<DRX_S3_PREFIX_PUBLIC>/*"
    }
  ]
}
```

For local MinIO, the equivalent is:

```sh
mc version enable local/<bucket>
mc anonymous set download local/<bucket>/<DRX_S3_PREFIX_PUBLIC>
```

### Litestream backup / restore (SQLite only)

The image bundles the pinned `litestream` binary at
`/usr/local/bin/litestream` and integrates it into bootstrap. The
feature is **off by default**; opt in by setting
`DRX_LITESTREAM_ENABLED=1`.
When the shared S3 contract above is populated, `DRX_LITESTREAM_*`
endpoint/region/path-style are taken from the `DRX_S3_*` values and
Litestream credentials are always bridged from
`DRX_S3_ACCESS_KEY_ID` / `DRX_S3_SECRET_ACCESS_KEY`, so one set of
vars covers both replication and file storage. The replica URL is
derived from `s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_LITESTREAM}`.

When enabled, the bootstrap:

1. Renders `/etc/litestream.yml` from the `DRX_LITESTREAM_*` env vars
   (or leaves an operator-supplied file alone if it already exists).
2. Runs `litestream restore` before Drupal install detection, honouring
   `DRX_LITESTREAM_RESTORE_ON_BOOT` and any point-in-time pin.
3. Wraps the final `exec` line as
   `litestream replicate -config /etc/litestream.yml -exec "<CMD>"`,
   so the process tree becomes
   `tini → drx-init → litestream → <CMD>` (typically Apache).
   Litestream forwards signals and performs a final WAL checkpoint plus
   replica sync on graceful shutdown (SIGTERM).

Only the SQLite driver is replicated; `DRUPAL_DB_DRIVER=mysql|pgsql`
ignores these settings.

| Variable                              | Default              | Notes                                                                                 |
| ------------------------------------- | -------------------- | ------------------------------------------------------------------------------------- |
| `DRX_LITESTREAM_ENABLED`              | `0`                  | Master switch. `1` enables render + restore + replicate wrapping.                     |
| `DRX_LITESTREAM_SYNC_INTERVAL`        | `1s`                 | Replica sync cadence passed to the generated config.                                  |
| `DRX_LITESTREAM_RESTORE_ON_BOOT`      | `if-empty`           | One of `if-empty` (restore only when local DB is missing), `always`, `never`.         |
| `DRX_LITESTREAM_CONFIG_FILE`          | `/etc/litestream.yml`| If the file already exists at boot, it is treated as an operator override.            |
| `DRX_LITESTREAM_RESTORE_TXID`         | _(unset)_            | Optional hex TXID to pin the restore at (e.g. taken from a marker export).            |
| `DRX_LITESTREAM_RESTORE_TIMESTAMP`    | _(unset)_            | Optional RFC3339 timestamp. Mutually exclusive with `_RESTORE_TXID`; TXID wins.       |
| `DRX_LITESTREAM_CLEAR_MAINTENANCE`    | `1`                  | After a successful restore, clear `system.maintenance_mode` from the restored DB before Apache starts. Set to `0` to leave whatever value was in the snapshot in place (useful when restoring deliberately into maintenance). |
| `DRX_LITESTREAM_CONTROL_SOCKET`       | `/var/run/litestream.sock` | Path of the litestream daemon's control socket. Used by snapshot tooling running as a non-root user inside the container to call `litestream sync` / `litestream info`. Set to an empty string to disable the socket. |
| `DRX_LITESTREAM_CONTROL_SOCKET_PERMS` | `0666`               | File mode on the control socket. `0666` lets the orchestrator (running as `www-data`) connect without extra chown plumbing; the socket only exposes intra-container RPCs so this is acceptable. |

The image does not source cloud-provider credential helpers; provide
`DRX_S3_ACCESS_KEY_ID` and `DRX_S3_SECRET_ACCESS_KEY` directly via your
deployment platform.

The base image only provides the runtime contract. Operator-facing UI
(replication health dashboard, point-in-time marker capture and export)
lives in a downstream module — see the reference implementation in
[server/modules/custom/drx_litestream](../server/modules/custom/drx_litestream).

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
ARG BASE_IMAGE=ghcr.io/mennotech/drx-apiserver:0.1.0
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

### Targeted apt security overrides (build-time)

The runtime stage avoids blanket `apt-get upgrade` so builds remain
deterministic relative to the pinned upstream base digest. For urgent CVE
patches that are not yet present in that digest, maintainers can pass
temporary exact-version overrides at build time:

```bash
docker build \
  --build-arg DRX_APT_SECURITY_OVERRIDES="openssl=3.0.20-1~deb12u1 apache2=2.4.67-1~deb12u2" \
  -t drx-apiserver:dev ./base
```

Rules:

- Use `name=version` pairs only.
- Keep overrides temporary; remove them once the pinned upstream digest
  includes the fix.
- Prefer patching only the packages tied to active security findings.

Report security issues privately to the maintainer; do not file public
issues for embargoed CVEs.
