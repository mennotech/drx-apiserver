# Changelog — drx-apiserver base image

All notable changes to the reusable base image are documented here.
The base image follows semantic versioning with respect to its **runtime
contract**: env vars, hook lifecycle, on-disk paths, supported DB drivers,
and default behaviours. Drupal core minor and patch updates flow through
as base-image patch or minor releases unless they break this contract.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- New Drush commands in `drx_litestream`: `drx:litestream:status`,
  `drx:litestream:info`, `drx:litestream:sync`, `drx:litestream:markers`,
  `drx:litestream:marker-show`, and `drx:litestream:marker-delete` for
  inspecting replication health, the runtime contract, and snapshot
  markers from the CLI (in addition to the existing
  `drx:litestream:snapshot`).
- New Drush commands in `drx_s3_journal`: `drx:s3-journal:status` and
  `drx:s3-journal:key-preview` for inspecting the journal pipeline and
  previewing object keys without writing to S3 (alongside the existing
  `drx:s3-journal:prefix` and `drx:s3-journal:test`).
- `Drupal\drx_s3_journal\Service\Journal::getJournalPrefix()` and
  `previewKey()` helpers to support the new commands without
  duplicating prefix/key construction logic.

### Fixed
- `Drupal\drx_litestream\Service\LitestreamStatus::isEnabled()` and
  `getReplicaUrl()` now derive from the shared `DRX_S3_*` contract
  when the bootstrap-internal `DRX_LITESTREAM_*` env vars are not
  visible (e.g. when Drush is invoked via `docker exec`, which
  receives the container-spec env rather than the runtime env exported
  by `init.sh`).
- `LitestreamStatus::getLocalStatus()` and `getReplicaLatestTxid()`
  now wrap their `litestream status` / `litestream ltx` shell-outs in
  a 10-second `timeout` so the admin dashboard and the new
  `drx:litestream:status` Drush command never block on a slow or empty
  replica.
- `LitestreamStatus::forceReplicaSync()` now passes `-timeout` as an
  integer number of seconds (the format `litestream sync` expects)
  instead of a duration string, fixing the `parse error` that
  prevented both `drx:litestream:sync` and the snapshot orchestrator's
  replica-flush step from succeeding.
- SigV4 canonical request construction in `drx_litestream`
  (`RemoteReplica`, `SnapshotManifestWriter`) and `drx_s3_journal`
  (`JournalWriter`) now inserts the required newline between
  `CanonicalHeaders` and `SignedHeaders`, fixing
  `403 SignatureDoesNotMatch` on the remote-size probe surfaced at
  `/admin/config/drx/litestream` and on journal/manifest writes.
- First-party base modules (`drx_litestream`, `drx_s3_journal`) are now
  copied into the runtime image at `/var/www/html/web/modules/base/`,
  so downstream `pm:enable` hooks no longer no-op against an absent
  modules tree.
- `DRX_S3_PUBLIC_HOST` is now exported in `base/lib/common.sh`
  alongside the rest of the shared `DRX_S3_*` contract, matching its
  documented role in `base/README.md` and its consumers in
  `base/lib/settings.sh`.

### Changed
- Corrected the `base/lib/s3.sh` header comment to reflect that s3fs
  fronts both `public://` and `private://` stream wrappers, not only
  private files.
- Documentation in `base/README.md` and `base/Dockerfile` updated to
  reflect that the base image now ships first-party Drupal modules
  (`drx_litestream`, `drx_s3_journal`) at `/var/www/html/web/modules/base/`,
  and the downstream "Extending the image" example now copies project
  modules into `/var/www/html/web/modules/custom/` to avoid colliding
  with the base-owned path.

## [0.0.5-rc5] - 2026-05-29

### Security
- Litestream delivery is now built from source at the pinned
  `v0.5.11` tag with an explicit patched gRPC dependency
  (`google.golang.org/grpc` `v1.79.3`) and a current Go toolchain,
  replacing the prebuilt upstream binary artifact. This addresses
  Trivy-reported HIGH/CRITICAL vulnerabilities in the embedded
  `gobinary` dependency graph while preserving the base image runtime
  contract.

### Added
- Shared S3 storage contract. A single bucket and credential pair are
  now shared between Litestream (database replica), Drupal's file
  backend (`drupal/s3fs`), and overlay journal workflows, with four
  prefixes inside the bucket:
  `${DRX_S3_PREFIX_LITESTREAM}/`, `${DRX_S3_PREFIX_PRIVATE}/`, and
  `${DRX_S3_PREFIX_PUBLIC}/`, plus `${DRX_S3_PREFIX_JOURNAL}/`. The
  public prefix is the **only** path
  that is anonymously readable, and only when the bucket policy
  explicitly grants `s3:GetObject` on `<bucket>/<public-prefix>/*`;
  everything else is private and Drupal-gated.
- `drupal/s3fs` `^3.0` (resolved to 3.10.0) added to base composer
  requirements, with `aws/aws-sdk-php` pulled in transitively. The
  module is enabled automatically during base-module bootstrap when S3
  is required, and `settings.php` is rendered with
  `use_s3_for_public=TRUE` and `use_s3_for_private=TRUE` so the
  standard `public://` and `private://` stream wrappers transparently
  resolve under the shared bucket.
- Mandatory S3 bootstrap. New `lib/s3.sh` validates the `DRX_S3_*` env
  contract, derives Litestream connection settings from the shared vars,
  and
  runs a SigV4-signed probe (`HEAD <bucket>` plus
  `GET <bucket>?versioning=`)
  (`lib/s3_probe.php`, dependency-free PHP) before storage and Litestream
  initialisation. Probe failures abort boot. Buckets must have
  versioning enabled when `DRX_S3_REQUIRED=1`.
- `DRX_TIMEZONE` support for the Drupal site timezone. On a fresh install, if the env var is unset, the bootstrap now attempts a one-time public-IP lookup and falls back to `UTC`; the resolved value is persisted to Drupal site config. On later boots, the site timezone is reconciled only when `DRX_TIMEZONE` differs from the active Drupal config.
- Scaffolding for SQLite backup/restore via Litestream. The pinned
  `litestream` binary (v0.5.11) is now bundled in the runtime image at
  `/usr/local/bin/litestream`, and a new bootstrap library
  `lib/litestream.sh` is sourced by `init.sh`.
- Litestream restore-on-boot path. When the shared S3 contract is
  active, the
  bootstrap renders `/etc/litestream.yml` from env vars and runs
  `litestream restore` against the configured replica before Drupal
  install detection. A populated local DB short-circuits the restore
  under the default `if-empty` policy, so a brand-new deployment falls
  through to the normal install path when the bucket has no backups
  yet. Restore policy is controlled by `DRX_LITESTREAM_RESTORE_ON_BOOT`
  (`if-empty` (default) | `always` | `never`). Replication itself
  (writer lifecycle wrapping) lands in a subsequent release.

### Runtime contract (new env vars)
- `DRX_S3_REQUIRED` (default `1`; `0` is a CI-only escape hatch that
  skips env validation, the connectivity probe, and the `s3fs` module
  enable).
- `DRX_S3_BUCKET`, `DRX_S3_REGION` (default `us-east-1`),
  `DRX_S3_ENDPOINT`, `DRX_S3_PUBLIC_HOST`,
  `DRX_S3_FORCE_PATH_STYLE` (auto),
  `DRX_S3_ACCESS_KEY_ID`, `DRX_S3_SECRET_ACCESS_KEY` — required when
  `DRX_S3_REQUIRED=1`; shared by Litestream and `drupal/s3fs`.
- `DRX_S3_PREFIX_LITESTREAM` (default `litestream`),
  `DRX_S3_PREFIX_PRIVATE` (default `private`),
  `DRX_S3_PREFIX_PUBLIC` (default `public`),
  `DRX_S3_PREFIX_JOURNAL` (default `journal/v1`) — four-prefix layout
  inside the shared bucket. The public prefix is the only path that
  may be exposed anonymously, and only via an explicit bucket policy.
- `DRX_TIMEZONE` (optional; sets the Drupal site timezone. If unset on a fresh install, the bootstrap attempts a one-time public-IP lookup and falls back to `UTC`).
- `DRX_LITESTREAM_SYNC_INTERVAL` (default `1s`).
- `DRX_LITESTREAM_RESTORE_ON_BOOT` (default `if-empty`).
- `DRX_LITESTREAM_CONFIG_FILE` (default `/etc/litestream.yml`; if the
  file already exists at boot it is treated as an operator-supplied
  override and the auto-render is skipped).
- `DRX_LITESTREAM_RESTORE_TXID` (optional; pins restore to a specific
  hex TXID, e.g. taken from a `drx_litestream` marker export).
- `DRX_LITESTREAM_RESTORE_TIMESTAMP` (optional; RFC3339 timestamp to
  restore at). Mutually exclusive with `_RESTORE_TXID`; TXID wins.
- `DRX_LITESTREAM_CLEAR_MAINTENANCE` (default `1`). After a successful
  restore the bootstrap deletes the `system.maintenance_mode` row from
  the restored DB's `key_value` table before Apache starts. Application-
  consistent snapshots are captured *while* Drupal is in maintenance
  mode, so without this clear-on-restore step a sidecar booted from a
  consistent snapshot would come up serving 503 to every request and
  its docker healthcheck would never go green. Set to `0` to preserve
  whatever maintenance-mode value the snapshot contained (e.g. when
  restoring deliberately into maintenance for manual inspection).
- `DRX_LITESTREAM_CONTROL_SOCKET` (default `/var/run/litestream.sock`)
  and `DRX_LITESTREAM_CONTROL_SOCKET_PERMS` (default `0666`). When set,
  the generated litestream config enables a `socket:` block so the
  replicate daemon listens for `litestream sync` / `litestream info`
  RPCs. Required for application-consistent snapshot tooling to obtain
  stable LTX-space TXIDs by force-flushing pending WAL frames on
  demand instead of waiting for `sync-interval`. Set the path to an
  empty string to disable the socket entirely.
- Litestream credentials are sourced from
  `DRX_S3_ACCESS_KEY_ID` / `DRX_S3_SECRET_ACCESS_KEY`. The replica URL
  in the shared S3 contract is `s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_LITESTREAM}`.

### Changed
- When the shared S3 contract is active, the bootstrap final exec is
  wrapped by `litestream replicate -config /etc/litestream.yml -exec
  "<CMD>"` instead of plain `exec "$@"`. The resulting process tree is
  `tini → drx-init → litestream → <CMD>` (typically Apache). Litestream
  forwards signals to the wrapped process and performs a final WAL
  checkpoint + replica sync on graceful shutdown (SIGTERM). When the
  feature is disabled the exec path is unchanged.
- Litestream credentials are now always derived from
  `DRX_S3_ACCESS_KEY_ID` / `DRX_S3_SECRET_ACCESS_KEY` when S3 is
  required. Shared S3 credentials remain the only supported source of
  truth during bootstrap, preventing split-credential configurations
  between s3fs and replica writes.
- The shared S3 contract is now also authoritative for the Litestream
  replica destination when S3 is required. Bootstrap derives it from
  `DRX_S3_BUCKET` + `DRX_S3_PREFIX_LITESTREAM` to prevent
  endpoint/path drift.

## [0.0.3-rc3] - 2026-05-27

### Changed
- Canonical published image name changed from `drx-drupal-base` to
  `drx-apiserver` (`ghcr.io/mennotech/drx-apiserver`). The image title,
  bootstrap log banner, generated-settings comments, and other operator
  facing text were updated to match the new distribution name.

## [0.0.2-rc2] - 2026-05-26

### Security
- Runtime package patching is now deterministic by default: the
  `apt-get upgrade` blanket update was removed from the runtime image
  build, and a targeted, temporary override mechanism was added via the
  `DRX_APT_SECURITY_OVERRIDES` build arg in `base/Dockerfile`. Overrides
  must be explicit `name=version` pairs (for example,
  `openssl=3.0.20-1~deb12u1 apache2=2.4.67-1~deb12u2`) and are applied
  with `apt-get install --only-upgrade`. This allows maintainers to patch
  specific CVEs ahead of an upstream base digest refresh, then remove the
  override once the pinned parent image includes the fix.
- Refreshed the pinned upstream PHP base image from
  `php:8.3.30-apache-bookworm` to
  `php:8.3.31-apache-bookworm@sha256:7a981a5d14208d35dc4b43c4c0f60e24a4fec9c80509cfe8046ed6598d250793`.
- Set a temporary default value for `DRX_APT_SECURITY_OVERRIDES`
  directly in `base/Dockerfile` (single source of truth) to apply
  exact-version upgrades for `libgnutls30` and Kerberos runtime
  libraries (`libgssapi-krb5-2`, `libk5crypto3`, `libkrb5-3`,
  `libkrb5support0`) until the pinned upstream base digest includes
  those fixed package versions.

## [0.0.1-rc2] - 2026-05-26

### Fixed
- Bootstrap no longer crash-loops when `drush config:import --partial`
  reports a non-zero exit. `drx::config_import::run` previously ended
  with a `[ "$mode" = "full" ] && drx::die …` short-circuit, which
  surfaced as a non-zero return whenever `mode != full` and tripped
  `set -e` in `init.sh`, aborting bootstrap before `exec "$@"` and
  causing the container to exit and restart in a tight loop. The
  function now uses an explicit `if`/`then`/`fi` block and always
  returns `0` in partial mode; the existing `drx::warn` log line
  remains the failure signal.

### Changed
- `DRUPAL_BASE_MODULES` now defaults to
  `config jsonapi serialization basic_auth rest` (was
  `jsonapi serialization basic_auth rest`). Drupal core's `config`
  module is required by `drush config:import --partial`, which the
  default config-import mode uses; without it, the import would log
  `Enable the config module in order to use the --partial option.` and
  silently drop site config. Consumers overriding `DRUPAL_BASE_MODULES`
  should add `config` to their list (or set
  `DRUPAL_CONFIG_IMPORT_MODE=full`, which does not depend on the
  module).

### Security
- Patched `twig/twig` from `v3.22.2` to `v3.26.0` to remediate
  `CVE-2026-46633` (CRITICAL — PHP code injection via `{% use %}`
  template name) and `CVE-2026-46640` (HIGH — arbitrary PHP execution
  via `_self.(<string>)` macro-reference compilation). The bump
  required updating `drupal/core-recommended` from `10.6.8` to
  `10.6.9` (which widens its `twig/twig` constraint from `~3.22.0` to
  `^3.26.0`); a direct `twig/twig: ^3.26` require was added to
  `base/composer.json` to lock in the floor. `config.policy.audit.ignore`
  was added for `PKSA-dwsq-ppd2-mb1x` (a transitive
  `symfony/polyfill-intl-idn` advisory) so the lock can resolve;
  Trivy remains the authoritative security gate.

### Changed
- **BREAKING:** `DRUPAL_INSTALL_PROFILE` now defaults to `minimal` (was
  `standard`). The `standard` profile pre-creates an Article content
  type, a Tags taxonomy, and a `field_tags` field storage of type
  `entity_reference` on `node`, all of which are foreign to an
  API-first base image and collide with downstream overlays that try
  to define their own `field_tags`. Minimal installs leave field-name
  space, content types, and taxonomies entirely to the consumer.
  Consumers that rely on `standard`'s scaffolding must set
  `DRUPAL_INSTALL_PROFILE=standard` explicitly. Existing sites already
  installed against `standard` are unaffected — the profile is only
  consulted on first install.

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

### Security
- Runtime stage runs `apt-get upgrade` against the upstream
  `php:8.3-apache-bookworm` image to pull current Debian security
  updates at build time (notably for `apache2*` packages).
- Runtime stage purges `linux-libc-dev` after PHP extension copy
  completes; kernel headers are a build-time artifact of the upstream
  PHP image and are never executed at runtime, but were the source of
  ~90 unfixed/late-fixed kernel CVE alerts in the published image's
  vulnerability scan. Removing them eliminates that noise without
  touching anything reachable in the runtime contract.
