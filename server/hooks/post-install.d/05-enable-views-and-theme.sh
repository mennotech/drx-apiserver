#!/bin/bash
# Reference-app hook: prepare modules and theme that must exist BEFORE
# config import, so config payloads under server/config/ (e.g.
# views.view.notes.yml, field.field.node.note.field_due_date.yml) can
# resolve their module and field-type dependencies.
#
# Runs in the post-install.d phase, which is positioned between
# drx::modules::enable_base and drx::config_import::run in init.sh.
set -euo pipefail

# Field-type providers needed by the Notes content model:
#   - datetime  -> 'datetime' field type (field_due_date)
#   - options   -> 'list_string' field type (field_status, field_category)
#   - text      -> 'text_long' field type (field_body) [usually present
#                 but explicit is safer]
# Views is needed so views.view.notes imports cleanly.
drx::drush pm:enable --yes datetime options text views || \
    drx::warn "one or more pre-config-import modules failed to enable"

drx::drush theme:install --yes claro || \
    drx::warn "claro theme not available; skipping"

drx::drush config:set --yes system.theme default claro || \
    drx::warn "could not set Claro as the default theme"
