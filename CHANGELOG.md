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
`drx-drupal-base` release).

---

## [Unreleased]

### Changed

#### CI diagnostics
- `.github/workflows/base-image.yml` now includes a post-scan step that
  parses `trivy.sarif` and prints a concise findings summary to the job
  log (`ruleId | level | message`). This keeps SARIF upload/code-scanning
  behavior unchanged while making failed Trivy runs easier to debug
  directly from the Actions log output.

## [2026-05-26] (drx-drupal-base v0.0.2-rc2)

### Changed

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

## [2026-05-26] (drx-drupal-base v0.0.1-rc2)

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
- Field `tags` in [server/schema/notes.yml](server/schema/notes.yml)
  renamed to `note_tags` (generates `field_note_tags`) for clarity and
  to defensively avoid any future collision with profiles that pre-create
  a `field_tags` storage.

---

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
- [docker-compose.yml](docker-compose.yml) recast as a **documented
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
  workflows under `.github/`, `Makefile`, `docker-compose.yml`,
  top-level docs, repository tooling, contributor process.
- Entries describing the **runtime behaviour of the published image**
  (env vars, hooks, on-disk layout, Drupal/PHP/Apache versions, CVE
  fixes) belong in [base/CHANGELOG.md](base/CHANGELOG.md), not here.
- Group entries under standard Keep a Changelog sections:
  `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed`, `Security`.
- When a `drx-drupal-base` release is cut, move the current
  `[Unreleased]` block under a new `[YYYY-MM-DD] (drx-drupal-base vX.Y.Z)`
  heading so this changelog stays aligned with image releases without
  claiming a separate version of its own.
