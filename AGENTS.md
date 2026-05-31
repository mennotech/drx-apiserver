# AGENTS.md

Guidance for AI coding agents working in this repository. Human
contributors should read [README.md](README.md) and [RELEASES.md](RELEASES.md)
first; this file complements those, it does not replace them.

## What this repository ships

One published artifact plus one non-published reference overlay:

| Directory   | Artifact                                | Track                                         |
| ----------- | --------------------------------------- | --------------------------------------------- |
| [base/](base/)   | `drx-apiserver` base image (published to GHCR) | Primary, versioned. SemVer on runtime contract. |
| [server/](server/) | Reference overlay consuming the base    | Not published; used for local dev and proof-of-concept flows. |

The base image is **deliberately neutral**. Do not add project-specific
modules, branding, content types, config payload, deployment platform
glue, or environment defaults to `base/`. That belongs in `server/` or
in downstream consumer repos.

## Authoritative sources

When in doubt, these files are the source of truth:

- Runtime contract (env vars, hooks, paths, DB drivers, defaults):
  [base/README.md](base/README.md).
- Image runtime release notes: [base/CHANGELOG.md](base/CHANGELOG.md).
- Repository release policy, tag matrix, cadence: [RELEASES.md](RELEASES.md).
- Repo-level changelog (CI, docs, tooling): [CHANGELOG.md](CHANGELOG.md).
- Security policy and reporting: [SECURITY.md](SECURITY.md).
- Build orchestration: [Makefile](Makefile),
  [.github/workflows/base-image.yml](.github/workflows/base-image.yml).

If a change you are making contradicts one of these, update both the
code **and** the relevant doc in the same change. Do not let them drift.

## Boundaries to respect

- **Runtime contract changes** (env vars, hook phases, on-disk paths,
  default behaviours, supported DB drivers) require a corresponding
  entry under `[Unreleased]` in [base/CHANGELOG.md](base/CHANGELOG.md).
  These drive SemVer bumps.
- **Repository operational changes** (CI workflow, Makefile,
  docker-compose, top-level docs, contributor process) require an entry
  under `[Unreleased]` in [CHANGELOG.md](CHANGELOG.md). These do not
  drive SemVer.
- Do not put runtime-contract notes in the top-level changelog, or
  repo-operational notes in the base changelog. The split is the
  feature.
- Do not add project-specific modules or hooks under `base/`. The
  reference example for that pattern lives in `server/`.

## Build, test, smoke

Local commands (see [Makefile](Makefile)):

- `make base` — build `drx-apiserver:dev` from [base/](base/).
- `make smoke` — boot the base image and wait for the healthcheck to
  report `healthy`. This is the minimum local check before pushing
  changes to `base/`.
- `make smoke-stack` — boot the full compose stack (app + MinIO),
  wait for healthcheck, and assert the seeded JSON:API endpoint
  returns the expected notes. Recommended pre-push check for any
  change under [server/](server/) or the bootstrap pipeline (compose,
  hooks, overlay modules, seeded config).
- `make lint-drupal` — run Drupal + DrupalPractice coding standards
  against [base/modules](base/modules) (first-party Drupal modules
  shipped with the image) and [server/modules](server/modules)
  (reference-overlay modules). Use `make lint-drupal-fix` to
  auto-correct fixable findings.
- `make lint-shell` — run the tiered ShellCheck policy (core + hooks)
  defined in [SHELL_POLICY.md](SHELL_POLICY.md).
- `make lint-shell-docs` — enforce the shell documentation policy
  (file-level + `drx::*` function-level docs). Strict by default; pass
  `STRICT=0` to demote function-level gaps to warnings while staging
  cleanup work.
- `make build` / `make up` / `make down` — build and run the reference
  overlay via [server/docker-compose.yml](server/docker-compose.yml).
- `make clean` — remove build artifacts and local image tags.

Override `VERSION`, `BASE_IMAGE`, `APP_IMAGE`, or `SMOKE_PORT` on the
command line as needed. The smoke target inspects container health via
`docker inspect`; a non-running container fails fast rather than waiting
out the timeout.

Before opening a PR:

- Run `make lint-shell` and `make lint-shell-docs` whenever you touch
  any shell script under [.github/scripts](.github/scripts), [base](base),
  or [server/hooks](server/hooks).
- Run `make lint-drupal` whenever you touch PHP under
  [base/modules](base/modules) or [server/modules](server/modules).
- Run `make smoke` whenever you touch anything under [base/](base/) or
  the bootstrap library, and confirm it reports `ok after Ns`.
- Run `make smoke-stack` whenever you touch anything under
  [server/](server/) or the bootstrap pipeline (compose, hooks,
  overlay modules, seeded config); confirm it reports the seeded
  JSON:API notes count and tears the stack down cleanly.

CI runs the same checks; failing them locally first keeps the feedback
loop short.

## CI expectations

The publish pipeline ([.github/workflows/base-image.yml](.github/workflows/base-image.yml)):

1. Builds the image with `linux/amd64` first, locally, for scanning.
2. Boots a smoke container against the local image.
3. Runs Trivy with `severity: CRITICAL,HIGH`, `ignore-unfixed: true`,
   `exit-code: '1'`. A failing scan blocks the release.
4. On non-PR events, pushes a multi-arch image
   (`linux/amd64,linux/arm64`) with SBOM and SLSA provenance.

If you introduce a new system dependency in `base/Dockerfile`, expect
Trivy to flag it. Prefer minimal runtime packages and pinned upstream
base image digests.

## Versioning rules

- The base image follows SemVer **with respect to its runtime contract**,
  not its internal implementation. Drupal/PHP/Apache patch updates flow
  through as patch or minor releases unless they break the contract.
- Tags published per the workflow tag matrix in
  [RELEASES.md](RELEASES.md#tag-policy). Do not invent ad hoc tag
  schemes.
- Pre-release tags (`vX.Y.Z-rcN`) publish only their literal version;
  no floating tags move. Use them for non-trivial contract changes.

## When making changes

A useful default workflow for an agent:

1. Read the relevant section of [base/README.md](base/README.md) (for
   image changes) or the relevant workflow/Makefile (for repo changes).
2. Make the smallest change that satisfies the request.
3. Update the appropriate `Unreleased` changelog block:
   - Image behaviour → [base/CHANGELOG.md](base/CHANGELOG.md).
   - Repo operations → [CHANGELOG.md](CHANGELOG.md).
4. Run `make smoke` for any change under `base/` or to the bootstrap
   library, and `make smoke-stack` for any change under `server/` or
   the bootstrap pipeline (compose, hooks, overlay modules, seeded
   config).
5. Run the relevant lint targets before opening a PR:
   - `make lint-shell` and `make lint-shell-docs` for any change to
     shell scripts under [.github/scripts](.github/scripts), [base](base),
     or [server/hooks](server/hooks).
   - `make lint-drupal` for any change to PHP under
    [base/modules](base/modules) or [server/modules](server/modules).
6. Do not bump version numbers, create git tags, or draft GitHub
   Releases. Releases are a maintainer action; see
   [RELEASES.md → Release procedure](RELEASES.md#release-procedure-maintainers).

## Out of scope for agents

- Publishing to registries.
- Creating, moving, or deleting Git tags.
- Editing GitHub repository settings or workflow permissions.
- Filing or modifying GitHub Security Advisories. Report findings to a
  human maintainer via the channel in [SECURITY.md](SECURITY.md) instead.
