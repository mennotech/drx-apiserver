#!/bin/bash
# Hash-gated config import. Runs config:import only when the contents of
# DRUPAL_CONFIG_SYNC_DIR have changed since the last successful import.
# Set DRUPAL_CONFIG_IMPORT_MODE=full to require all dependencies (default
# 'partial' keeps the historical lenient behaviour for incremental sync
# directories shared by downstream projects).

drx::config_import::run() {
    local mode="${DRUPAL_CONFIG_IMPORT_MODE:-partial}"
    local dir="${DRUPAL_CONFIG_SYNC_DIR}"

    if [ ! -d "${dir}" ] || ! ls "${dir}"/*.yml >/dev/null 2>&1; then
        drx::log "No config files in ${dir}; skipping import"
        return 0
    fi

    local current stored=""
    current="$(find "${dir}" -name '*.yml' -exec md5sum {} \; | sort | md5sum | awk '{print $1}')"
    [ -f "${DRUPAL_CONFIG_HASH_FILE}" ] && stored="$(cat "${DRUPAL_CONFIG_HASH_FILE}")"

    if [ "${current}" = "${stored}" ]; then
        drx::log "Config hash unchanged; skipping import"
        return 0
    fi

    drx::log "Importing config from ${dir} (mode=${mode})"
    local args=( config:import --source="${dir}" --yes )
    [ "${mode}" = "partial" ] && args=( config:import --partial --source="${dir}" --yes )

    if drx::drush "${args[@]}"; then
        echo "${current}" > "${DRUPAL_CONFIG_HASH_FILE}"
        drx::log "Config import complete"
    else
        drx::warn "Config import failed; not updating hash"
        [ "${mode}" = "full" ] && drx::die "Config import failed in full mode"
    fi
}
