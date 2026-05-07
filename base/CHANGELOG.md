# Changelog — drx-drupal-base

All notable changes to the reusable base image are documented here.
The base image follows semantic versioning with respect to its **runtime
contract**: env vars, hook lifecycle, on-disk paths, supported DB drivers,
and default behaviours. Drupal core minor and patch updates flow through
as base-image patch or minor releases unless they break this contract.

The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

### Added
- Reusable, hardened Drupal 10 base image (`drx-drupal-base`).
- Composable bootstrap orchestrator at `/usr/local/bin/drx-init`.
- Lifecycle hook directories under `/etc/drx/hooks/<phase>.d/`.
- Multi-driver database contract (`sqlite`, `mysql`, `pgsql`).
- Trusted-host generation from `BACKEND_URL` + `DRUPAL_TRUSTED_HOST_PATTERNS`.
- Built-in HTTP healthcheck at `/usr/local/bin/drx-healthcheck`.
- `tini` PID 1 for clean signal handling.
- OCI image labels for source, version, revision, and build date.
- Reference downstream overlay in `server/` consuming the base via
  `BASE_IMAGE` build arg.

### Changed
- Drupal install no longer assumes Fly.io; storage strategy is driven by
  `DRUPAL_FILES_TARGET` and is platform-neutral.
- JSON:API defaults to **read-only**. Downstream projects opt into write
  mode via `DRUPAL_JSONAPI_READ_ONLY=0` or a `post-config-import.d/` hook.
- Apache hardened: `ServerTokens Prod`, `ServerSignature Off`,
  `TraceEnable Off`.

### Removed
- Project-specific `newschool_payments` enablement from the bootstrap
  path. Equivalent behaviour now lives in downstream hooks.
