#!/bin/bash
# Reference-app hook: enable optional modules used by the decoupled
# frontend that are not part of the standard install profile. Safe to run
# repeatedly; drush pm:enable is idempotent.
set -euo pipefail

drx::drush pm:enable --yes navigation || \
    drx::warn "navigation module not available; skipping"
