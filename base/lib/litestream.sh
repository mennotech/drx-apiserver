#!/bin/bash
# =============================================================================
# drx-apiserver base image: litestream integration.
#
# Provides functions for litestream-based SQLite backup/restore + writer
# lifecycle wrapping. Enablement is derived by lib/s3.sh from the shared
# DRX_S3_* contract (on when S3 is required, off when S3 is bypassed).
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
        drx::warn "litestream expected but binary is missing; treating as disabled"
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
        "litestream is enabled but DRX_LITESTREAM_REPLICA_URL is empty."

    drx::log "litestream: writing config to ${target} (replica=${DRX_LITESTREAM_REPLICA_URL})"

    # Auto-enable path-style addressing when a custom endpoint is supplied
    # (MinIO and most self-hosted S3 backends require it). Operators can
    # override with DRX_S3_FORCE_PATH_STYLE=true|false.
    local force_path_style="${DRX_S3_FORCE_PATH_STYLE}"
    if [ -z "${force_path_style}" ]; then
        if [ -n "${DRX_S3_ENDPOINT}" ]; then
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
        if [ -n "${DRX_S3_ENDPOINT}" ]; then
            printf '        endpoint: %s\n' "${DRX_S3_ENDPOINT}"
        fi
        if [ -n "${DRX_S3_REGION}" ]; then
            printf '        region: %s\n' "${DRX_S3_REGION}"
        fi
        printf '        force-path-style: %s\n' "${force_path_style}"
        printf '        sync-interval: %s\n' "${DRX_LITESTREAM_SYNC_INTERVAL}"
        # Control socket. Lets the orchestrator (running as www-data
        # via drush) ask the daemon to flush in-flight WAL frames as a
        # new LTX file on demand, which is required to obtain a stable
        # LTX-space TXID for application-consistent snapshots.
        # Default mode 0666 because: (a) the daemon runs as root and
        # the orchestrator runs as www-data, so 0660 would need extra
        # chown plumbing, and (b) the socket only exposes flush /
        # status RPCs; an attacker with shell access inside the
        # container could already do worse via other means.
        if [ -n "${DRX_LITESTREAM_CONTROL_SOCKET}" ]; then
            printf 'socket:\n'
            printf '  enabled: true\n'
            printf '  path: %s\n' "${DRX_LITESTREAM_CONTROL_SOCKET}"
            printf '  permissions: %s\n' "${DRX_LITESTREAM_CONTROL_SOCKET_PERMS:-0666}"
        fi
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

    # Optional point-in-time pin. When DRX_LITESTREAM_RESTORE_TXID is set
    # (e.g. taken from a drx_litestream marker export), restore up to and
    # including that transaction instead of the latest. Mutually exclusive
    # with DRX_LITESTREAM_RESTORE_TIMESTAMP; TXID wins if both are set.
    if [ -n "${DRX_LITESTREAM_RESTORE_TXID:-}" ]; then
        drx::log "litestream: pinning restore to txid=${DRX_LITESTREAM_RESTORE_TXID}"
        restore_flags+=(-txid "${DRX_LITESTREAM_RESTORE_TXID}")
    elif [ -n "${DRX_LITESTREAM_RESTORE_TIMESTAMP:-}" ]; then
        drx::log "litestream: pinning restore to timestamp=${DRX_LITESTREAM_RESTORE_TIMESTAMP}"
        restore_flags+=(-timestamp "${DRX_LITESTREAM_RESTORE_TIMESTAMP}")
    fi

    if sudo -E -u www-data litestream restore \
            -config "${DRX_LITESTREAM_CONFIG_FILE}" \
            "${restore_flags[@]}" \
            "${DRUPAL_SQLITE_PATH}"; then
        if [ -f "${DRUPAL_SQLITE_PATH}" ]; then
            chown www-data:www-data "${DRUPAL_SQLITE_PATH}" 2>/dev/null || true
            drx::log "litestream: restore complete"
            drx::litestream::_clear_maintenance_after_restore
        else
            drx::log "litestream: no replica found yet (first boot); proceeding to install"
        fi
    else
        local rc=$?
        drx::die "litestream restore failed (exit ${rc})"
    fi
}

# Application-consistent snapshots are captured while Drupal is in
# maintenance mode, so the snapshot's `key_value` row for
# `system.maintenance_mode` is `b:1;` at the moment of capture. If we
# leave that as-is, the freshly-restored site comes up serving 503 to
# every request and the docker healthcheck never goes green.
#
# Clear the flag directly via sqlite3 before Apache starts. This runs
# only on the restore path (i.e. only when a real restore actually
# placed a DB file), and is a no-op when the maintenance_mode key is
# absent or already false. Set DRX_LITESTREAM_CLEAR_MAINTENANCE=0 to
# opt out (e.g. if the operator intentionally wants the restored
# instance to come up in maintenance for manual inspection first).
drx::litestream::_clear_maintenance_after_restore() {
    if [ "${DRX_LITESTREAM_CLEAR_MAINTENANCE:-1}" != "1" ]; then
        drx::log "litestream: leaving system.maintenance_mode untouched (DRX_LITESTREAM_CLEAR_MAINTENANCE=0)"
        return 0
    fi
    [ -f "${DRUPAL_SQLITE_PATH}" ] || return 0
    if ! command -v sqlite3 >/dev/null 2>&1; then
        drx::warn "litestream: sqlite3 not available; cannot clear maintenance_mode after restore"
        return 0
    fi

    local current
    current="$(sqlite3 "${DRUPAL_SQLITE_PATH}" \
        "SELECT value FROM key_value WHERE collection='state' AND name='system.maintenance_mode';" \
        2>/dev/null || true)"

    if [ -z "${current}" ]; then
        return 0
    fi
    if [ "${current}" = "b:0;" ]; then
        return 0
    fi

    drx::log "litestream: clearing system.maintenance_mode from restored snapshot"
    sqlite3 "${DRUPAL_SQLITE_PATH}" \
        "DELETE FROM key_value WHERE collection='state' AND name='system.maintenance_mode';" \
        >/dev/null 2>&1 || drx::warn "litestream: failed to clear maintenance_mode (will require manual drush sset)"
}

drx::litestream::exec_wrap() {
    if ! drx::litestream::enabled; then
        exec "$@"
    fi

    # litestream's -exec flag takes a single shell command string. Quote
    # each argument so embedded whitespace / shell metacharacters survive.
    local cmd=""
    local arg
    for arg in "$@"; do
        cmd+="$(printf '%q ' "$arg")"
    done

    drx::log "litestream: handing off via 'litestream replicate -exec' (cmd: $*)"
    # tini (PID 1) -> drx-init -> litestream -> apache2-foreground.
    # litestream forwards signals to the child and performs a final WAL
    # checkpoint + replica sync on SIGTERM before exiting, which is the
    # whole point of wrapping Apache this way.
    exec litestream replicate \
        -config "${DRX_LITESTREAM_CONFIG_FILE}" \
        -exec "${cmd}"
}
