#!/bin/bash
# =============================================================================
# drx-apiserver base image: litestream integration.
#
# Provides functions for litestream-based SQLite backup/restore + writer
# lifecycle wrapping. Disabled by default; downstream activates by setting
# DRX_LITESTREAM_ENABLED=1 and providing a replica URL + credentials.
#
# Public API (functions sourced into bootstrap and exec phases):
#   drx::litestream::enabled        — 0/1 predicate for "litestream active?"
#   drx::litestream::write_config   — render /etc/litestream.yml from env
#                                     (no-op when an operator-supplied file
#                                     already exists at the target path)
#   drx::litestream::restore        — bootstrap restore from replica per
#                                     DRX_LITESTREAM_RESTORE_ON_BOOT policy
#   drx::litestream::exec_wrap      — wrap final exec line (Phase 3)
# =============================================================================

drx::litestream::enabled() {
    if [ "${DRX_LITESTREAM_ENABLED:-0}" != "1" ]; then
        return 1
    fi
    if ! command -v litestream >/dev/null 2>&1; then
        drx::warn "DRX_LITESTREAM_ENABLED=1 but litestream binary is missing; treating as disabled"
        return 1
    fi
    return 0
}

# Internal: does the SQLite DB file exist and contain at least one object?
drx::litestream::_db_populated() {
    [ -f "${DRUPAL_SQLITE_PATH}" ] || return 1
    local n
    n="$(sqlite3 "${DRUPAL_SQLITE_PATH}" \
        "SELECT count(*) FROM sqlite_master WHERE type IN ('table','index','view');" \
        2>/dev/null || echo 0)"
    [ "${n}" != "0" ]
}

# Render the litestream config from DRX_LITESTREAM_* env vars. If the
# target file already exists with content at boot, treat it as an
# operator-supplied override and leave it untouched.
drx::litestream::write_config() {
    drx::litestream::enabled || return 0

    local target="${DRX_LITESTREAM_CONFIG_FILE}"

    if [ -e "${target}" ] && [ -s "${target}" ]; then
        drx::log "litestream: using operator-supplied config at ${target}"
        return 0
    fi

    [ -n "${DRX_LITESTREAM_REPLICA_URL}" ] || drx::die \
        "DRX_LITESTREAM_ENABLED=1 but DRX_LITESTREAM_REPLICA_URL is empty."

    drx::log "litestream: writing config to ${target} (replica=${DRX_LITESTREAM_REPLICA_URL})"

    # Auto-enable path-style addressing when a custom endpoint is supplied
    # (MinIO and most self-hosted S3 backends require it). Operators can
    # force-disable via DRX_LITESTREAM_FORCE_PATH_STYLE=false.
    local force_path_style="${DRX_LITESTREAM_FORCE_PATH_STYLE}"
    if [ -z "${force_path_style}" ]; then
        if [ -n "${DRX_LITESTREAM_ENDPOINT}" ]; then
            force_path_style="true"
        else
            force_path_style="false"
        fi
    fi
    case "${force_path_style}" in
        1|true|TRUE|yes|YES)     force_path_style="true" ;;
        0|false|FALSE|no|NO|"")  force_path_style="false" ;;
    esac

    install -d -m 0755 "$(dirname "${target}")"
    {
        printf 'dbs:\n'
        printf '  - path: %s\n' "${DRUPAL_SQLITE_PATH}"
        printf '    replicas:\n'
        printf '      - name: drx\n'
        printf '        url: %s\n' "${DRX_LITESTREAM_REPLICA_URL}"
        if [ -n "${DRX_LITESTREAM_ENDPOINT}" ]; then
            printf '        endpoint: %s\n' "${DRX_LITESTREAM_ENDPOINT}"
        fi
        if [ -n "${DRX_LITESTREAM_REGION}" ]; then
            printf '        region: %s\n' "${DRX_LITESTREAM_REGION}"
        fi
        printf '        force-path-style: %s\n' "${force_path_style}"
        printf '        sync-interval: %s\n' "${DRX_LITESTREAM_SYNC_INTERVAL}"
    } > "${target}"
    chmod 0644 "${target}"
}

# Restore the SQLite database from the configured replica per the
# DRX_LITESTREAM_RESTORE_ON_BOOT policy. Must run BEFORE install::ensure
# so a freshly-booted writer adopts the backup instead of reinstalling.
drx::litestream::restore() {
    drx::litestream::enabled || return 0

    local policy="${DRX_LITESTREAM_RESTORE_ON_BOOT:-if-empty}"

    case "${policy}" in
        never)
            drx::log "litestream: restore policy=never; skipping"
            return 0
            ;;
        if-empty)
            if drx::litestream::_db_populated; then
                drx::log "litestream: restore policy=if-empty and local DB is populated; skipping"
                return 0
            fi
            ;;
        always)
            drx::log "litestream: restore policy=always; clearing local DB before restore"
            rm -f "${DRUPAL_SQLITE_PATH}" \
                  "${DRUPAL_SQLITE_PATH}-wal" \
                  "${DRUPAL_SQLITE_PATH}-shm" 2>/dev/null || true
            ;;
        *)
            drx::die "Invalid DRX_LITESTREAM_RESTORE_ON_BOOT='${policy}' (expected: if-empty|always|never)"
            ;;
    esac

    install -d -o www-data -g www-data -m 0770 "$(dirname "${DRUPAL_SQLITE_PATH}")"

    drx::log "litestream: attempting restore to ${DRUPAL_SQLITE_PATH}"
    # -if-replica-exists: clean no-op when the bucket has no backups yet
    # (first boot of a brand-new deployment). -if-db-not-exists guards
    # against clobbering a populated local DB in the if-empty branch.
    local restore_flags=(-if-replica-exists)
    if [ "${policy}" = "if-empty" ]; then
        restore_flags+=(-if-db-not-exists)
    fi

    if sudo -E -u www-data litestream restore \
            -config "${DRX_LITESTREAM_CONFIG_FILE}" \
            "${restore_flags[@]}" \
            "${DRUPAL_SQLITE_PATH}"; then
        if [ -f "${DRUPAL_SQLITE_PATH}" ]; then
            chown www-data:www-data "${DRUPAL_SQLITE_PATH}" 2>/dev/null || true
            drx::log "litestream: restore complete"
        else
            drx::log "litestream: no replica found yet (first boot); proceeding to install"
        fi
    else
        local rc=$?
        drx::die "litestream restore failed (exit ${rc})"
    fi
}

drx::litestream::exec_wrap() {
    # Phase 3 will replace this no-op with role-aware exec wrapping.
    exec "$@"
}
