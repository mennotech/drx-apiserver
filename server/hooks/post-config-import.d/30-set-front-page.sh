#!/bin/bash
# Reference-app hook: point the site's front page at the Notes view that
# the views.view.notes config payload exposes at /notes. Idempotent —
# drush config:set is fine to re-run.
set -euo pipefail

drx::drush config:set --yes system.site page.front /notes || \
    drx::warn "could not set system.site:page.front"
