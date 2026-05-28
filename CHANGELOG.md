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

### Added

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

#### Full-stack test
- Added `make stack-test` to [Makefile](Makefile): boots the full
  reference compose stack (drx-apiserver + MinIO + bucket initialiser)
  with the production S3 posture (no `DRX_S3_REQUIRED=0` escape hatch),
  waits for the backend healthcheck, and asserts the bootstrap log
  shows a successful S3 probe + `s3fs` module enable plus that
  `GET /jsonapi/node/note` returns the three seeded notes. Tears the
  stack down on success and failure.
- Added [.github/workflows/stack-test.yml](.github/workflows/stack-test.yml):
  runs `make stack-test` on every push and pull request that touches
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
