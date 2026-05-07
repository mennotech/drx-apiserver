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

_none_

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
