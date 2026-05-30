# Shell Script Path Policy

This repository uses a two-tier shell policy to balance reliability with practical hook authoring.

## Tier 1: Core Scripts (strict profile)

Paths:

- `.github/scripts/**/*.sh`
- `base/*.sh`
- `base/lib/*.sh`

Purpose:

- CI orchestration
- base-image bootstrap and runtime plumbing

Lint target:

- `make lint-shell-core`

ShellCheck profile:

- Uses `-x` and fails on all reported severities
- Excludes only dynamic-source resolution checks:
  - `SC1090`
  - `SC1091`

Rationale: core scripts use variable-driven sourcing patterns that are valid at runtime but hard for static analysis to resolve.

## Tier 2: Hook Scripts (hook profile)

Paths:

- `server/hooks/**/*.sh`

Purpose:

- Reference-app lifecycle hooks
- glue scripts for module enable/config/import actions

Lint target:

- `make lint-shell-hooks`

ShellCheck profile:

- Uses `-x` and fails on all reported severities
- Excludes embedded-literal warning:
  - `SC2016`

Rationale: some hooks intentionally pass single-quoted literals to subordinate interpreters (for example `drush php:eval` payloads), where shell expansion is not desired.

## Aggregate Target

Use `make lint-shell` to run both profiles.

## Policy Expectations

- New scripts must be placed in the correct tier path.
- If a script needs an exception not covered by tier defaults, prefer a local `# shellcheck disable=...` with a short justification.
- Keep the exception lists small and path-scoped; avoid global blanket disables.
