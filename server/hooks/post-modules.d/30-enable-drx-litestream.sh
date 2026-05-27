#!/bin/bash
# Reference-app hook: enable the drx_litestream module so operators can
# view replication health and capture point-in-time markers from the
# Drupal admin UI. Safe to run repeatedly.
set -euo pipefail

drx::drush pm:enable --yes drx_litestream || \
    drx::warn "drx_litestream module not available; skipping"
