#!/bin/bash
# Reference-app hook: enable the Views UI admin module so editors can
# create and adjust views from /admin/structure/views. Views itself is
# enabled earlier (post-install.d/05) so config import can resolve
# views.view.* dependencies; views_ui is admin-only and safe to enable
# after extras.
set -euo pipefail

drx::drush pm:enable --yes views_ui || \
    drx::warn "views_ui module not available; skipping"
