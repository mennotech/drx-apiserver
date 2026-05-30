# Changelog — drx-apiserver (repository)

This file tracks **repository-level** changes: CI/CD, documentation,
tooling, and developer-experience updates.

For **base image** runtime-contract release notes, see
[base/CHANGELOG.md](base/CHANGELOG.md). For release policy, cadence, and
tag semantics, see [RELEASES.md](RELEASES.md).

The format is based on [Keep a Changelog](https://keepachangelog.com/);
the project follows [Semantic Versioning](https://semver.org/) for the
base image runtime contract (this top-level changelog is not itself
versioned — entries are grouped by the date of the corresponding
`drx-apiserver` release).

---

## [Unreleased]

### Fixed

- [.github/workflows/drupal-coding-standards.yml](.github/workflows/drupal-coding-standards.yml)
  now also triggers on `pull_request` with the same paths filter, so
  PRs (including from forks) get a dedicated PHPCS check matching the
  changelog description.
- [base/.gitattributes](base/.gitattributes) no longer classifies
  `*.sh` as PHP for GitHub Linguist / diff highlighting; shell scripts
  are tagged `diff=bash linguist-language=shell`.
- `make lint-drupal` ([Makefile](Makefile)) and
  [phpcs.xml.dist](phpcs.xml.dist) now lint
  [base/modules](base/modules) (where `drx_litestream` and
  `drx_s3_journal` live after the recent move) in addition to
  [server/modules](server/modules); the previous configuration
  pointed at the now-empty `server/modules/contrib/` and was a no-op.
  [.github/workflows/drupal-coding-standards.yml](.github/workflows/drupal-coding-standards.yml)
  trigger paths and [AGENTS.md](AGENTS.md) `make lint-drupal`
  guidance were updated to match.
- [server/README.md](server/README.md) Litestream admin-UI section now
  links to the first-party module under
  [base/modules/drx_litestream/](base/modules/drx_litestream/) instead
  of the removed `server/modules/contrib/drx_litestream/` path.

### Changed

- Hardened [Makefile](Makefile) `make smoke-stack` admin-password handling:
  it now treats `DRUPAL_ADMIN_PASS` as the only input, generates a
  strong ephemeral password when it is not provided, and keeps the
  value hidden by default (opt-in reveal via
  `SHOW_ADMIN_PASS=1`).
- Added a global [Makefile](Makefile) `SHOW_ADMIN_PASS=1` toggle so
  up-oriented targets can reveal the active admin password on demand
  (`make up`, `make up-base`, `make smoke-stack`) while remaining
  hidden by default.
- Standardized local boot targets on `DRUPAL_ADMIN_PASS` as the single
  Drupal admin password variable: removed Makefile-specific
  `BASE_UP_ADMIN_PASS` and replaced hardcoded smoke passwords with
  generated ephemeral values when `DRUPAL_ADMIN_PASS` is unset.
- Updated [.github/workflows/smoke-stack.yml](.github/workflows/smoke-stack.yml)
  to stop hardcoding a static CI admin password; CI now uses the
  Makefile-generated ephemeral password unless explicitly overridden.
- Adopted Drupal coding standards for custom app code by adding
  [phpcs.xml.dist](phpcs.xml.dist) (Drupal + DrupalPractice rules) and a
  containerized [Makefile](Makefile) `make lint-drupal` target that runs
  PHPCS against [server/modules/contrib](server/modules/contrib) without
  requiring host PHP/Composer tooling; added
  [.github/workflows/drupal-coding-standards.yml](.github/workflows/drupal-coding-standards.yml)
  to enforce the same check on push and pull request changes.
- Added a containerized [Makefile](Makefile) `make lint-shell` target that
  runs ShellCheck for repository shell scripts under [base](base),
  [server](server), and [.github](.github).
- Split shell linting into path-scoped policy tiers documented in
  [SHELL_POLICY.md](SHELL_POLICY.md):
  [Makefile](Makefile) now provides `make lint-shell-core` for strict
  checks on [.github/scripts](.github/scripts) + [base](base) and
  `make lint-shell-hooks` for [server/hooks](server/hooks), with
  `make lint-shell` running both profiles.
- Added a shell documentation policy checker
  ([.github/scripts/lint-shell-docs.sh](.github/scripts/lint-shell-docs.sh))
  exposed via [Makefile](Makefile) `make lint-shell-docs`: enforces a
  shebang + purpose comment per file and requires preceding doc
  comments on `drx::*` public functions. Defaults to strict mode; pass
  `STRICT=0` to demote function-level gaps to warnings. Wired into
  [.github/workflows/shell-lint.yml](.github/workflows/shell-lint.yml)
  and documented in [SHELL_POLICY.md](SHELL_POLICY.md).

## [2026-05-29] (drx-apiserver v0.0.5-rc5)

### Added

#### S3-backed content-change journal (drx_s3_journal base module)
- New base module
  [drx_s3_journal](base/modules/drx_s3_journal/) that writes
  one immutable JSON object per Drupal file create/update/delete to
  `s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_JOURNAL}/YYYY/MM/DD/HH/<TS>_<event_id>_<op>_<scope>_<fid>.json`,
  scoped to the `public://` and `private://` streams only. The
  lexicographic key layout is the replay contract: listing the bucket
  from any hourly prefix yields events in chronological order.
- Strict-audit semantics: write failures throw, which aborts the
  surrounding Drupal request so no content mutation is confirmed
  without a durable journal record.
- Direct AWS SigV4 PUT via Guzzle (no Drupal stream wrappers, no
  s3fs roundtrip) to avoid the recursion that would result from
  journaling a journal write. Sends `If-None-Match: *` so retried
  event IDs become harmless 412s instead of silent overwrites.
- Drush helpers:
  `drush drx:s3-journal:prefix --since=<ts>` prints the S3 prefix to
  resume replay from; `drush drx:s3-journal:test` emits a synthetic
  event to verify credentials + bucket policy.
- The module no-ops silently when `DRX_S3_REQUIRED=0` (CI/smoke) and
  is enabled on boot via
  [server/hooks/post-modules.d/35-enable-drx-s3-journal.sh](server/hooks/post-modules.d/35-enable-drx-s3-journal.sh).

#### Notes app attachments field + seeded examples
- Added `field_attachments` (file, multi-value) to the reference
  `note` content type scaffold in [server/schema/notes.yml](server/schema/notes.yml)
  and the generated Drupal config payload under [server/config/](server/config/).
- Attachment uploads are routed to a dedicated
  `public://note-attachments/YYYY-MM/` directory layout.
- Updated note form/display config so attachments are editable and
  rendered in the default view mode.
- Updated the first-boot notes seed hook
  [server/hooks/post-config-import.d/20-seed-notes.sh](server/hooks/post-config-import.d/20-seed-notes.sh)
  to attach one sample `.txt` file to the first note and one sample
  `.pdf` file to the second note.

#### S3 contract defaults (prod vs dev)
- Clarified and documented the S3 bucket contract split:
  production keeps `DRX_S3_BUCKET` explicit and required when
  `DRX_S3_REQUIRED=1`, while the reference local overlay provides a
  dev-only fallback bucket (`drx-data-local`) for quick starts.
- Updated local defaults in [server/docker-compose.yml](server/docker-compose.yml)
  and [.env.example](.env.example) from `drx-backups` to
  `drx-data-local`, including the litestream replica URL.
- Updated [server/docker-compose.yml](server/docker-compose.yml)
  `minio-init` to enable bucket versioning for the local dev bucket on
  startup.
- Removed local Drupal file/DB named volumes from
  [server/docker-compose.yml](server/docker-compose.yml). The reference
  stack now persists only MinIO data; Drupal local storage is ephemeral
  and restored via Litestream/S3 on container recreate.
- Updated [base/README.md](base/README.md) and
  [server/README.md](server/README.md) to reflect the production-required
  bucket and local fallback naming.

#### Smoke-stack test
- Added `make smoke-stack` to [Makefile](Makefile): boots the full
  reference compose stack (drx-apiserver + MinIO + bucket initialiser)
  with the production S3 posture (no `DRX_S3_REQUIRED=0` escape hatch),
  waits for the backend healthcheck, and asserts the bootstrap log
  shows a successful S3 probe + `s3fs` module enable plus that
  `GET /jsonapi/node/note` returns the three seeded notes. Tears the
  stack down on success and failure.
- Added [.github/workflows/smoke-stack.yml](.github/workflows/smoke-stack.yml):
  runs `make smoke-stack` on every push and pull request that touches
  `base/`, `server/`, the `Makefile`, or the workflow itself.

#### Local DR drill workflow
- Added `make dr-drill` to [Makefile](Makefile), a local disaster-recovery
  verification target for the reference stack that writes a DB marker,
  stops the app gracefully, removes the local SQLite volume, boots the app,
  and asserts the marker is restored from Litestream/MinIO.
- Added `make pit-drill` to [Makefile](Makefile), a local point-in-time
  verification target that captures the replica TXID after marker A,
  overwrites the row with marker B, then boots a fresh sidecar container
  pinned via `DRX_LITESTREAM_RESTORE_TXID` and asserts the restored DB
  shows marker A.
- Updated [.env.example](.env.example) with local Litestream/MinIO defaults
  used by [server/docker-compose.yml](server/docker-compose.yml) so the DR
  drill and replication-health module work out of the box.
- Updated the local target table in [README.md](README.md) to include
  `make dr-drill` and `make pit-drill`.
- Documented the litestream runtime contract in
  [base/README.md](base/README.md#litestream-backup--restore-sqlite-only)
  and the admin / dev-restore workflow in
  [server/README.md](server/README.md#litestream-replication-admin-ui-and-dev-restore).

#### Application-consistent snapshots (drx_litestream base module)
- `drx_litestream` now captures **application-consistent** point-in-time
  markers via a new `SnapshotOrchestrator` service. The orchestrator
  acquires Drupal's core `cron` lock (the same lock `\Drupal\Core\Cron::run()`
  itself holds — every cron invocation short-circuits while the snapshot
  runs), enables maintenance mode, drains briefly, nudges the SQLite
  WAL forward with a non-blocking `PRAGMA wal_checkpoint(PASSIVE)`,
  waits for the Litestream replica to reach the post-checkpoint TXID,
  writes the marker row, and waits one more time so the marker UPDATE
  itself is on the replica. Maintenance mode and the cron lock are
  released in `finally`, and SIGINT/SIGTERM handlers run the same
  cleanup on hard termination.
- Marker schema bumped to v2 in
  [drx_litestream.install](base/modules/drx_litestream/drx_litestream.install)
  (`hook_update_9001`): new columns `kind` (`live`/`consistent`),
  `consistent_at`, `bucket`, `s3_endpoint`, `s3_region`, the three
  prefix columns, `base_image_ref`, `drupal_site_uuid`, and
  `verify_state`/`verify_error`/`verified_at`. Existing rows backfill
  to `kind='live'`.
- Exported marker JSON (schema `drx-litestream-marker/v2`) now includes
  a full `source` block (bucket + endpoint + region + prefixes + base
  image ref + site UUID) and a `consistent_at` wall-clock pin, making
  the marker a self-contained restore recipe. The dev_restore_hint
  documents the bucket-clone-at-`consistent_at` step required for full
  DB-plus-files point-in-time restore.
- New admin route `/admin/config/drx/litestream/markers/snapshot`
  (form: `SnapshotForm`) and a "Capture consistent snapshot" primary
  button on the markers list page, alongside the existing live capture.
- New drush command `drush drx:litestream:snapshot --label=<label>`
  (alias `drx-lit-snap`) that runs the orchestrator and prints the
  captured TXID on stdout. Uses Drush 12's static `create(ContainerInterface)`
  DI pattern; no `drush.services.yml` file is needed.
- Tunable env vars for the orchestrator (all optional):
  `DRX_LITESTREAM_SNAPSHOT_DRAIN_SECS` (default `3`),
  `DRX_LITESTREAM_SNAPSHOT_TIMEOUT` (replica catch-up, default `30`),
  `DRX_LITESTREAM_SNAPSHOT_LOCK_TTL` (cron lock TTL, default `900`),
  `DRX_LITESTREAM_SNAPSHOT_LOCK_WAIT` (cron-busy wait, default `10`).
- Added `make snapshot-drill` to [Makefile](Makefile): captures a
  consistent snapshot via the new drush command, writes post-snapshot
  data that must NOT survive restore, boots a sidecar pinned to the
  snapshot TXID, and asserts both (a) the snapshot marker row is
  present and (b) the post-snapshot mutation is absent. Proves the
  consistency boundary is real end-to-end.
- Hardened the orchestrator against Apache pile-up: WAL checkpoints
  are now `PASSIVE` (not `TRUNCATE`) and the orchestrator's PDO sets
  `busy_timeout=5000`, so a foreign-connection checkpoint no longer
  takes a RESERVED lock that starves every Apache worker trying to
  render the maintenance page. The redundant post-update checkpoint
  was removed; Litestream's native sync loop ships the marker UPDATE
  and `waitForReplica` already polls until it lands.
- Added `pcntl_signal` handlers for SIGINT and SIGTERM in the
  orchestrator so an interrupted snapshot (Ctrl-C, `docker stop`,
  killed `docker compose exec` host process) releases the cron
  semaphore row and clears maintenance mode instead of leaking a
  stale lock that blocks both subsequent snapshots and Drupal cron
  for 15 minutes. PHP's default shutdown-function path only runs on
  SIGINT, not SIGTERM; explicit handlers close the gap.

### Fixed

#### Wait for minio to stabilize before probing

- Updated [server/docker-compose.yml](server/docker-compose.yml) so the
  reference app waits for `minio-init` to finish before bootstrapping,
  eliminating the local `make smoke-stack` race where the S3 probe could
  run before MinIO bucket setup was ready.

#### Restore-TXID correctness in operator tooling
- `drx_litestream` "live marker" form
  ([MarkerForm.php](base/modules/drx_litestream/src/Form/MarkerForm.php))
  now records the replica's latest LTX-space TXID (via
  `LitestreamStatus::getReplicaLatestTxid()`) instead of the
  WAL-local counter from `litestream status`. The previous value was
  not a valid argument to `litestream restore -txid` and could
  produce markers whose exported restore hint failed.
- `LitestreamStatus::getHealthSnapshot()`
  ([LitestreamStatus.php](base/modules/drx_litestream/src/Service/LitestreamStatus.php))
  no longer compares the local WAL TXID against the replica's LTX
  TXID. The two values live in different namespaces, so the prior
  "replica appears behind local TXID" warning was a false positive.
- `make pit-drill` ([Makefile](Makefile)) captures the pin TXID via
  `litestream ltx -level all` (max `max_txid`) plus an explicit
  `litestream sync -wait`, so the drill exercises the same restore
  contract the orchestrator and the markers UI rely on.
- `drx_litestream` snapshot orchestrator
  ([SnapshotOrchestrator.php](base/modules/drx_litestream/src/Service/SnapshotOrchestrator.php))
  default `DRX_LITESTREAM_SNAPSHOT_LOCK_TTL` is now `900` seconds,
  matching the documented default. Previously the code used `120`s,
  which could let the cron lock expire mid-snapshot on slower hosts.

#### S3 / dev-stack consistency
- The s3fs settings block
  ([base/lib/settings.sh](base/lib/settings.sh)) now honours
  `DRX_S3_FORCE_PATH_STYLE` for `use_path_style_endpoint`, matching
  the existing semantics in
  [base/lib/litestream.sh](base/lib/litestream.sh) and
  [base/lib/s3_probe.php](base/lib/s3_probe.php). Previously s3fs
  always derived path-style from `DRX_S3_ENDPOINT` alone, which could
  diverge from the bootstrap probe / Litestream config.
- Pinned the MinIO + mc images in
  [server/docker-compose.yml](server/docker-compose.yml) to explicit
  release tags instead of the floating `:latest` tag, so local dev and
  `make smoke-stack` are reproducible and not subject to drift when
  MinIO publishes a new release.
- Reference-app seed hook
  ([server/hooks/post-config-import.d/20-seed-notes.sh](server/hooks/post-config-import.d/20-seed-notes.sh))
  now calls `base64_decode(..., TRUE)` so the existing `=== FALSE`
  guard actually catches malformed input instead of being dead code.
- Litestream credential wiring now treats `DRX_S3_ACCESS_KEY_ID` /
  `DRX_S3_SECRET_ACCESS_KEY` as the authoritative source during
  bootstrap ([base/lib/s3.sh](base/lib/s3.sh)); `make pit-drill` and
  `make snapshot-drill` sidecar restores now pass `DRX_S3_*` credentials
  directly from the shared S3 contract.
- Litestream replica destination is likewise derived from the shared
  S3 contract instead of being passed separately in local examples and
  drill sidecars.

---

## [2026-05-27] (drx-apiserver v0.0.4-rc4)

### Changed

#### Local dev image orchestration
- Updated [Makefile](Makefile) target semantics so `make up` no longer rebuilds images by default; it now only starts the stack from the currently available image.
- Added `make up-build` as an explicit convenience target that rebuilds the app image (`make app`) and then starts the stack.
- Updated `make up` to use Compose `up --no-build -d`, so it never triggers an implicit build and only launches an already-built app image.
- Aligned app-image tagging between [Makefile](Makefile) and [server/docker-compose.yml](server/docker-compose.yml): Compose now uses `image: ${APP_IMAGE:-drx-apiserver-demo:dev}` and Makefile exports `APP_IMAGE` for `app`, `up`, and `down`.
- Removed the prior `drx-apiserver:dev` app-image tag collision with the base-image default, which could cause the reference overlay (`server/config`, hooks, modules) to be skipped at runtime when the wrong local image was started.
- Moved the reference Compose file from repo root to [server/docker-compose.yml](server/docker-compose.yml), and made [Makefile](Makefile) call Compose with explicit `-f`, `--project-directory`, and project name arguments so local orchestration remains root-driven and predictable.
- Fixed [server/docker-compose.yml](server/docker-compose.yml) build paths for root-driven Compose invocation: build context now points to `./server`, ensuring Compose resolves [server/Dockerfile](server/Dockerfile) and includes `server/config`, hooks, and modules in the app image.
- Added `make build` as an alias for `make app` and kept `make app` as the primary app build target.
- Added `make up-base` and `make down-base` to run or stop only the pure base image locally without bringing up the reference overlay stack.

#### Supported-line security scan maintainability
- Refactored [.github/workflows/supported-image-security-scan.yml](.github/workflows/supported-image-security-scan.yml) to move long inline Bash logic into dedicated, testable scripts under `.github/scripts/`:
  - `.github/scripts/resolve-supported-scan-targets.sh`
  - `.github/scripts/write-trivy-scan-metadata.sh`
  - `.github/scripts/summarize-trivy-findings.sh`
- Added `actions/checkout@v4` to both jobs so repository-hosted CI scripts are available at runtime.
- Hardened `.github/scripts/resolve-supported-scan-targets.sh` to validate required CI environment variables, capture `docker buildx imagetools inspect` failures as structured skipped-target diagnostics, and emit actionable grouped debug output when no targets can be resolved.
- Expanded `.github/scripts/summarize-trivy-findings.sh` to print a full severity breakdown (`UNKNOWN`/`LOW`/`MEDIUM`/`HIGH`/`CRITICAL`) and a readable top-findings table (vulnerability ID, package, installed/fixed version, target, title) in the job summary.
- Split supported-line scan severities by artifact: SARIF upload remains `CRITICAL,HIGH` for high-signal code-scanning alerts, while JSON artifacts now include `UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL` for full downstream automation visibility.
- Fixed a summary-script jq failure when counting `UNKNOWN` vulnerabilities in Trivy JSON.
- Switched SARIF limiting to Trivy's built-in `limit-severities-for-sarif` input and removed the custom post-processing filter step.

---

## [2026-05-27] (drx-apiserver v0.0.3-rc3)

### Added

#### Supported-line daily security scan
- Added [.github/workflows/supported-image-security-scan.yml](.github/workflows/supported-image-security-scan.yml), a daily/manual workflow that:
  - resolves the latest patch in the current and previous supported minor
    lines,
  - resolves those tags to immutable image digests,
  - deduplicates by digest,
  - runs non-gating Trivy scans (`CRITICAL,HIGH`, `ignore-unfixed=true`),
  - uploads SARIF to GitHub code scanning,
  - uploads JSON/SARIF/metadata artifacts for downstream automation.
- Added [SUPPORT.md](SUPPORT.md) to define supported image lines and
  document where continuous-scan outputs are published.

### Changed

#### Image naming and publish model
- Canonical published image name is now `drx-apiserver`
  (`ghcr.io/mennotech/drx-apiserver`). Workflows, local defaults, and
  operator-facing docs were updated accordingly.
- Release/policy docs now explicitly state that `server/` is a
  non-published reference overlay used for local development and
  proof-of-concept flows.

#### Base image publish behavior
- `.github/workflows/base-image.yml` now publishes `drx-apiserver` to
  GHCR only on GitHub Release events. Pushes to `main` still run CI and
  smoke validation, but they no longer publish a branch-derived image
  tag.
- [RELEASES.md](RELEASES.md) and [base/README.md](base/README.md) were
  updated to remove the `main`/`edge` publication path from the tag
  policy.


## [2026-05-26] (drx-drupal-base v0.0.2-rc2)

### Added

#### Drupal-version informational tags
- `.github/workflows/base-image.yml` now extracts the resolved
  `drupal/core` version from `base/composer.lock` at build time and
  publishes a parallel family of informational tags alongside the
  existing contract-version tags:
  - `drupal-A.B.C` (immutable, published on every release including
    `-rcN` pre-releases),
  - `drupal-A.B` and `drupal-A` (floating, published on non-prerelease
    releases only).
  No `drupal-*` tags are pushed for `main` builds; the bundled Drupal
  version is exposed instead via the new
  `org.mennotech.drx.drupal.version` OCI label, which is set on every
  image regardless of trigger.
- `RELEASES.md → Tag policy` documents the two parallel tag families
  (contract-version and Drupal-version) and the recommended pinning
  strategies for each.

#### Podman compatibility
- `Makefile` now honours `CONTAINER_ENGINE` (default `docker`) and
  `COMPOSE` (default `$(CONTAINER_ENGINE) compose`), so all local
  targets — `base`, `app`, `up`, `down`, `smoke`, `scan`, `verify`,
  `clean` — can be driven through Docker or rootless/rootful Podman
  without editing the file. Typical invocation:
  `make verify CONTAINER_ENGINE=podman`.
- `make scan` is socket-free by design: instead of mounting a Docker /
  Podman API socket into the Trivy container, it exports the local
  image with `$(CONTAINER_ENGINE) save -o` into a host tempdir,
  mounts the tempdir read-only into Trivy, and points
  `--input /scan/image.tar` at the tarball. This sidesteps the
  rootless-socket, `podman machine` (macOS / Windows), and userns
  permission edge cases entirely — the scan path is identical across
  engines and platforms.
- `make scan` performs an engine-reachability preflight
  (`$(CONTAINER_ENGINE) info`) and emits actionable hints
  (`podman machine start`, `systemctl --user start podman.socket`)
  when the engine is not running, instead of letting the underlying
  command fail with an opaque error.
- `make smoke` invokes the healthcheck script via
  `$(CONTAINER_ENGINE) exec` rather than relying on
  `.State.Health.Status`, so it works identically under Docker and
  rootless Podman (which does not run `HEALTHCHECK` timers
  automatically).
- [README.md](README.md) updated with the Podman invocation pattern.
- CI continues to run the Docker path only; Podman support is
  contributor-side and not yet exercised by the publish pipeline.

#### Reference Notes application
- [server/](server/) gained a working "Notes" reference content model
  that ships end-to-end on first boot, demonstrating how a downstream
  consumer of `drx-drupal-base` can deliver a JSON:API-ready data
  model with no manual post-install steps:
  - [server/schema/notes.yml](server/schema/notes.yml): source-of-truth
    `drx-schema` YAML defining a `note` bundle with `body`, `status`
    (`draft|published|archived`), `pinned`, `due_date`, `note_tags`
    (multi-value string), and `category` fields, grouped into Content
    and Tags-and-Metadata sections.
  - [server/config/](server/config/): generated Drupal config scaffold
    (node type, field storages, field instances, form display, view
    display) committed alongside the schema so the runtime image does
    not depend on PowerShell or the generator. Regeneration steps are
    documented in [server/README.md](server/README.md#regenerating-config-from-the-schema).
  - [server/hooks/post-config-import.d/10-jsonapi-write-mode.sh](server/hooks/post-config-import.d/10-jsonapi-write-mode.sh):
    project opt-in to JSON:API write mode (the base image remains
    read-only by default).
  - [server/hooks/post-config-import.d/20-seed-notes.sh](server/hooks/post-config-import.d/20-seed-notes.sh):
    idempotent seed hook that creates three example Note nodes the
    first time the site boots and records a state marker so subsequent
    boots are no-ops.
  - [server/hooks/post-modules.d/10-enable-navigation.sh](server/hooks/post-modules.d/10-enable-navigation.sh):
    enables the experimental Navigation module so the example admin UI
    is usable for evaluators.
  - [server/hooks/post-install.d/05-enable-views-and-theme.sh](server/hooks/post-install.d/05-enable-views-and-theme.sh):
    enables `views`, `datetime`, `options`, `text` (so the imported
    field types resolve) and installs + activates the Claro admin
    theme before config import runs.
  - [server/hooks/post-modules.d/20-enable-views-ui.sh](server/hooks/post-modules.d/20-enable-views-ui.sh):
    enables the `views_ui` module so the seeded view is editable from
    the admin UI.
  - [server/hooks/post-config-import.d/30-set-front-page.sh](server/hooks/post-config-import.d/30-set-front-page.sh):
    points `system.site.page.front` at `/notes` so the front page
    serves the Notes listing on first boot.
  - [server/config/views.view.notes.yml](server/config/views.view.notes.yml):
    Notes listing view (path `/notes`, title + created + body fields,
    filters `status=1` + `bundle=note`, sorted by `field_pinned` DESC
    then `created` DESC, 20 per page).
- [server/README.md](server/README.md) describes the schema → config →
  hooks pipeline, lists the field set, and documents the regeneration
  procedure.

### Changed

#### CI diagnostics
- `.github/workflows/base-image.yml` now includes a post-scan step that
  parses `trivy.sarif` and prints a concise findings summary to the job
  log (`ruleId | level | message`). This keeps SARIF upload/code-scanning
  behavior unchanged while making failed Trivy runs easier to debug
  directly from the Actions log output.
- Trivy scanning in CI is split into two explicit phases: a gating
  table scan (`CRITICAL,HIGH`, `ignore-unfixed`, `exit-code: 1`) to match
  local `make scan` behavior, followed by a non-gating SARIF generation
  step (`exit-code: 0`) used only for code-scanning upload and log
  diagnostics.

#### Base image release hardening / determinism
- Refreshed the pinned upstream PHP base image digest in
  [base/Dockerfile](base/Dockerfile) from
  `php:8.3.30-apache-bookworm` to
  `php:8.3.31-apache-bookworm@sha256:7a981a5d14208d35dc4b43c4c0f60e24a4fec9c80509cfe8046ed6598d250793`.
- Reworked runtime package patching to avoid non-deterministic blanket
  upgrades and use explicit, temporary exact-version overrides via
  `DRX_APT_SECURITY_OVERRIDES`, with a single source of truth in
  [base/Dockerfile](base/Dockerfile).
- Kept local and CI builds aligned by relying on the Dockerfile default
  for temporary overrides rather than duplicating override values in
  [Makefile](Makefile) and
  [.github/workflows/base-image.yml](.github/workflows/base-image.yml).

---

## [2026-05-07] (drx-drupal-base v0.0.1-rc1)

Initial repository-level state for the first public preview of
`drx-drupal-base`. Captures everything introduced since the initial
commit.

### Added

#### Documentation
- Top-level [README.md](README.md) describing repository layout, quick
  start (consumer + local dev), release model, and security posture.
- Top-level [RELEASES.md](RELEASES.md) documenting versioning, tag
  policy, monthly cadence, emergency security release process, support
  window, and the maintainer release procedure.
- Top-level [CHANGELOG.md](CHANGELOG.md) (this file) for repository-level
  changes, separated from the base image runtime changelog.
- [SECURITY.md](SECURITY.md) describing supported versions, private
  reporting via GitHub Security Advisories, response SLAs, and scope.
- [AGENTS.md](AGENTS.md) with guidance for AI coding agents working in
  this repository (artifact boundaries, changelog routing, build/test
  expectations, out-of-scope actions).

#### CI / CD
- [.github/workflows/base-image.yml](.github/workflows/base-image.yml):
  build, smoke, scan, and publish pipeline for `drx-drupal-base`.
  - Triggers: push to `main` (paths-filtered), pull requests touching
    `base/**`, and `release: published` events.
  - Local-load build for `linux/amd64` followed by an in-CI smoke boot
    against the healthcheck.
  - Trivy vulnerability scan
    (`severity: CRITICAL,HIGH`, `ignore-unfixed: true`,
    `exit-code: '1'`) with SARIF upload to the repository's Code
    Scanning tab.
  - Multi-arch publish (`linux/amd64`, `linux/arm64`) to GHCR with
    SBOM (`sbom: true`) and SLSA build provenance
    (`provenance: mode=max`).
  - Semver-aware tag derivation via `docker/metadata-action`:
    `X.Y.Z` / `X.Y` / `X` / `latest` for stable releases,
    `X.Y.Z-rcN` (no floating tags) for pre-releases, `edge` for
    `main`, and `pr-NNN` for pull requests.
  - Required workflow permissions declared: `contents: read`,
    `packages: write`, `id-token: write`, `security-events: write`.

#### Build orchestration
- [Makefile](Makefile) with local targets: `base`, `app`, `up`, `down`,
  `smoke`, `scan`, `verify`, `clean`. Overridable variables:
  `BASE_IMAGE`, `APP_IMAGE`, `SMOKE_PORT`, `VERSION`, `VCS_REF`,
  `BUILD_DATE`, `TRIVY_VERSION`, `TRIVY_SEVERITY`.
- `make smoke` boots the base image and waits for the container's
  healthcheck to report `healthy` via `docker inspect`, with
  fail-fast on early container exit.
- `make scan` runs the **same** Trivy gates CI runs
  (`severity: CRITICAL,HIGH`, `ignore-unfixed`, `exit-code: 1`)
  against the locally-built base image via the official
  `aquasec/trivy` OCI image. No host install required; vulnerability
  DB cached under `~/.cache/trivy`. `TRIVY_VERSION` /
  `TRIVY_SEVERITY` keep the local gate in lock-step with the workflow
  so they cannot drift.
- `make verify` is the recommended pre-push gate; chains `smoke` + `scan`.
- [server/docker-compose.yml](server/docker-compose.yml) recast as a **documented
  consumer example** that layers the reference overlay on top of
  `${DRX_BASE_IMAGE:-drx-drupal-base:dev}`; no longer the product
  definition.

#### Reference overlay
- [server/](server/) reduced to a thin downstream example:
  - `server/Dockerfile` is `FROM ${BASE_IMAGE}` plus `COPY` of
    `modules/`, `config/`, and `hooks/` only.
  - Project-specific behaviour (JSON:API write-mode opt-in, optional
    module enablement) implemented as
    `hooks/post-config-import.d/10-jsonapi-write-mode.sh` and
    `hooks/post-modules.d/10-enable-navigation.sh`.

### Changed

- Repository restructured into a **two-layer product** so downstream
  Drupal 10 projects can consume a hardened base image without
  inheriting any one project's modules, branding, or platform
  assumptions.
- `Makefile` `smoke` target: configurable `SMOKE_PORT`, health-driven
  wait via `docker inspect` (instead of curling the app), and
  fail-fast when the container exits early.
- `.github/workflows/base-image.yml`: set `hide-progress: true` on the
  Trivy step so DB-download progress bars no longer flood CI logs.

### Fixed

- `.github/workflows/base-image.yml`: pin Trivy action to
  `aquasecurity/trivy-action@v0.36.0`. The originally-committed pin
  (`@0.24.0`) does not exist upstream and caused every workflow run
  to fail at "Set up job"; the upstream project also requires the
  `v`-prefixed tag scheme after their supply-chain advisory.

### Removed

- Legacy `server/init.sh`, `server/composer.json`, and
  `server/composer.lock`. The bootstrap library and Composer manifest
  now live in [base/](base/); downstream overlays no longer ship
  their own bootstrap script.

---

## Conventions

- Entries here describe changes to the **repository as a project**:
  workflows under `.github/`, `Makefile`, `server/docker-compose.yml`,
  top-level docs, repository tooling, contributor process.
- Entries describing the **runtime behaviour of the published image**
  (env vars, hooks, on-disk layout, Drupal/PHP/Apache versions, CVE
  fixes) belong in [base/CHANGELOG.md](base/CHANGELOG.md), not here.
- Group entries under standard Keep a Changelog sections:
  `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- When a `drx-apiserver` release is cut, move the current
  `[Unreleased]` block under a new `[YYYY-MM-DD] (drx-apiserver vX.Y.Z)`
  heading so this changelog stays aligned with image releases without
  claiming a separate version of its own.
