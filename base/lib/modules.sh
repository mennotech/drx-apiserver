#!/bin/bash
# Module enablement.
#   drx::modules::enable_base   — runs BEFORE config import; enables modules
#                                 listed in DRUPAL_BASE_MODULES.
#   drx::modules::enable_extras — runs AFTER config import; enables modules
#                                 listed in DRUPAL_EXTRA_MODULES (typically
#                                 modules whose install hooks reference
#                                 entities created by config/sync).

drx::modules::_enable_list() {
    local label="$1"; shift
    local modules=("$@")
    [ ${#modules[@]} -gt 0 ] || return 0
    drx::log "Enabling ${label} modules: ${modules[*]}"
    drx::drush pm:enable --yes "${modules[@]}" || \
        drx::warn "One or more ${label} modules failed to enable"
}

drx::modules::enable_base() {
    # shellcheck disable=SC2206
    local mods=( ${DRUPAL_BASE_MODULES} )
    drx::modules::_enable_list base "${mods[@]}"
}

drx::modules::enable_extras() {
    # shellcheck disable=SC2206
    local mods=( ${DRUPAL_EXTRA_MODULES} )
    drx::modules::_enable_list extra "${mods[@]}"
}
