#!/bin/bash
# =============================================================================
# drx-apiserver base image: common helpers shared by all bootstrap modules.
# =============================================================================

# Constants -------------------------------------------------------------------
export DRUPAL_HTML_ROOT="${DRUPAL_HTML_ROOT:-/var/www/html}"
export DRUPAL_ROOT="${DRUPAL_HTML_ROOT}/web"
export DRUPAL_VENDOR="${DRUPAL_HTML_ROOT}/vendor"
export DRUSH="${DRUPAL_VENDOR}/bin/drush"
export DRUPAL_SITE_DIR="${DRUPAL_ROOT}/sites/default"
export DRUPAL_FILES_DIR="${DRUPAL_SITE_DIR}/files"
export DRUPAL_SETTINGS_PHP="${DRUPAL_SITE_DIR}/settings.php"
export DRUPAL_SERVICES_YML="${DRUPAL_SITE_DIR}/services.yml"
export DRUPAL_TRUSTED_HOSTS_PHP="${DRUPAL_SITE_DIR}/trusted-hosts.settings.php"

export DRUPAL_CONFIG_SYNC_DIR="${DRUPAL_CONFIG_SYNC_DIR:-${DRUPAL_HTML_ROOT}/config/sync}"
export DRUPAL_STATE_DIR="${DRUPAL_STATE_DIR:-/var/drupal-db}"
export DRUPAL_CONFIG_HASH_FILE="${DRUPAL_STATE_DIR}/.config_hash"
# Placeholder directory that satisfies Drupal's file_private_path requirement.
# Nothing is actually written here when s3fs takes over private://; the
# directory must simply exist and be writable by www-data.
export DRUPAL_PRIVATE_FILES_PATH="${DRUPAL_PRIVATE_FILES_PATH:-/var/drupal-private}"

# Database contract (sqlite-first; mysql/pgsql ready for future use).
export DRUPAL_DB_DRIVER="${DRUPAL_DB_DRIVER:-sqlite}"
export DRUPAL_SQLITE_PATH="${DRUPAL_SQLITE_PATH:-${DRUPAL_STATE_DIR}/db.sqlite}"

# Site identity / runtime URLs.
export DRUPAL_ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
export DRUPAL_ADMIN_PASS="${DRUPAL_ADMIN_PASS:-}"
export DRUPAL_SITE_NAME="${DRUPAL_SITE_NAME:-Drupal}"
export DRUPAL_INSTALL_PROFILE="${DRUPAL_INSTALL_PROFILE:-minimal}"
export BACKEND_URL="${BACKEND_URL:-http://localhost}"
export FRONTEND_URL="${FRONTEND_URL:-http://localhost:3000}"
export CORS_ALLOWED_ORIGINS="${CORS_ALLOWED_ORIGINS:-}"
export DRX_TIMEZONE="${DRX_TIMEZONE:-}"

# -----------------------------------------------------------------------------
# Shared S3 connection. Used by both Litestream (database replication) and
# the Drupal file storage backend (user file uploads). One bucket, three
# prefixes, one set of credentials.
#
# Layout inside the bucket:
#   ${DRX_S3_PREFIX_LITESTREAM}/   Litestream replica  (no public access)
#   ${DRX_S3_PREFIX_PRIVATE}/      Drupal private files (Drupal-gated)
#   ${DRX_S3_PREFIX_PUBLIC}/       Drupal public files  (anonymous read via
#                                  bucket policy on this prefix only)
#
# Security posture: private by default. Public access exists only because
# the bucket policy explicitly grants s3:GetObject on the public prefix;
# any other path is deny-by-default.
#
# DRX_S3_REQUIRED is the master switch:
#   1 (default) — production posture. Bootstrap validates env + connectivity
#                 and refuses to start when S3 is misconfigured or unreachable.
#   0           — CI/build escape hatch. Skips validation and connectivity
#                 probe so the image can boot without a live S3 backend.
# -----------------------------------------------------------------------------
export DRX_S3_REQUIRED="${DRX_S3_REQUIRED:-1}"
export DRX_S3_BUCKET="${DRX_S3_BUCKET:-}"
export DRX_S3_REGION="${DRX_S3_REGION:-us-east-1}"
export DRX_S3_ENDPOINT="${DRX_S3_ENDPOINT:-}"
export DRX_S3_FORCE_PATH_STYLE="${DRX_S3_FORCE_PATH_STYLE:-}"
export DRX_S3_ACCESS_KEY_ID="${DRX_S3_ACCESS_KEY_ID:-}"
export DRX_S3_SECRET_ACCESS_KEY="${DRX_S3_SECRET_ACCESS_KEY:-}"
export DRX_S3_PREFIX_LITESTREAM="${DRX_S3_PREFIX_LITESTREAM:-litestream}"
export DRX_S3_PREFIX_PRIVATE="${DRX_S3_PREFIX_PRIVATE:-private}"
export DRX_S3_PREFIX_PUBLIC="${DRX_S3_PREFIX_PUBLIC:-public}"

# Module/API contract. Secure-by-default: read-only JSON:API.
export DRUPAL_BASE_MODULES="${DRUPAL_BASE_MODULES:-config jsonapi serialization basic_auth rest}"
export DRUPAL_EXTRA_MODULES="${DRUPAL_EXTRA_MODULES:-}"
export DRUPAL_JSONAPI_READ_ONLY="${DRUPAL_JSONAPI_READ_ONLY:-1}"

# Trusted hosts: caller-provided regex patterns, comma-separated. Localhost
# and the BACKEND_URL host are always added.
export DRUPAL_TRUSTED_HOST_PATTERNS="${DRUPAL_TRUSTED_HOST_PATTERNS:-}"

# Litestream (SQLite backup/restore). Off by default unless the shared S3
# contract is active (DRX_S3_REQUIRED=1 and DRX_S3_BUCKET set), in which
# case lib/s3.sh::bridge_litestream enables it automatically. Set
# DRX_LITESTREAM_ENABLED=0 explicitly to disable replication even when S3
# is configured. When enabled, the bootstrap renders /etc/litestream.yml
# from these vars (unless DRX_LITESTREAM_CONFIG_FILE points at an
# operator-provided config), runs a restore-before-install on first boot,
# and wraps Apache with `litestream replicate --exec` for ongoing replication.
#
# Required when enabled: DRX_LITESTREAM_REPLICA_URL plus credentials
# in litestream-native env vars (LITESTREAM_ACCESS_KEY_ID,
# LITESTREAM_SECRET_ACCESS_KEY, or provider equivalents).
#
# NOTE: intentionally NOT defaulted to "0" here so that bridge_litestream
# can use := to set it to "1" when the shared S3 connection is present.
# The inline default in drx::litestream::enabled (:-0) handles the unset case.
export DRX_LITESTREAM_ENABLED
export DRX_LITESTREAM_REPLICA_URL="${DRX_LITESTREAM_REPLICA_URL:-}"
export DRX_LITESTREAM_SYNC_INTERVAL="${DRX_LITESTREAM_SYNC_INTERVAL:-1s}"
# Restore policy: if-empty (default) | always | never.
#   if-empty: restore only when the SQLite file is missing or has no tables
#   always:   restore on every boot (destructive; overwrites local DB)
#   never:    skip restore entirely (writer adopts existing local DB)
export DRX_LITESTREAM_RESTORE_ON_BOOT="${DRX_LITESTREAM_RESTORE_ON_BOOT:-if-empty}"
# Operator escape hatch: if set and the file exists, used verbatim instead
# of the generated config. The path must be readable inside the container.
export DRX_LITESTREAM_CONFIG_FILE="${DRX_LITESTREAM_CONFIG_FILE:-/etc/litestream.yml}"
# Point-in-time pinning for restore (optional, mutually exclusive; TXID
# wins if both are set). Typically supplied from a drx_litestream marker
# export to reproduce an exact state on a dev/test machine. Honoured
# regardless of restore policy when restore actually runs.
export DRX_LITESTREAM_RESTORE_TXID="${DRX_LITESTREAM_RESTORE_TXID:-}"
export DRX_LITESTREAM_RESTORE_TIMESTAMP="${DRX_LITESTREAM_RESTORE_TIMESTAMP:-}"
# Litestream control socket. The replicate daemon listens here for
# `litestream sync`, `litestream info`, etc. The orchestrator running
# inside Drupal uses this to flush pending WAL frames to S3 before
# reading the LTX TXID for application-consistent snapshots. Set to
# an empty string to disable the socket entirely (snapshots will fall
# back to polling sync-interval, but with no force-flush capability).
export DRX_LITESTREAM_CONTROL_SOCKET="${DRX_LITESTREAM_CONTROL_SOCKET:-/var/run/litestream.sock}"
export DRX_LITESTREAM_CONTROL_SOCKET_PERMS="${DRX_LITESTREAM_CONTROL_SOCKET_PERMS:-0666}"

# Logging ---------------------------------------------------------------------
drx::log()  { printf '[drx] %s\n' "$*" >&2; }
drx::warn() { printf '[drx] WARN: %s\n' "$*" >&2; }
drx::die()  { printf '[drx] ERROR: %s\n' "$*" >&2; exit 1; }

# Run as the www-data user; never run drush/composer as root in normal flows.
drx::as_www() {
    sudo -E -u www-data "$@"
}

drx::drush() {
    drx::as_www "${DRUSH}" --root="${DRUPAL_ROOT}" "$@"
}

# Hook runner: sources every regular file in $DRX_HOOKS_DIR/<phase>/ in
# lexical order inside an isolated subshell so hooks inherit bootstrap
# helpers (drx::drush, drx::log, ...) and environment without being able
# to leak set -e behaviour back into the orchestrator. A non-zero exit
# from any hook aborts bootstrap.
drx::run_hooks() {
    local phase="$1"
    local dir="${DRX_HOOKS_DIR}/${phase}"
    [ -d "${dir}" ] || return 0

    local hook
    while IFS= read -r -d '' hook; do
        [ -f "${hook}" ] || continue
        drx::log "Running hook ${phase}/$(basename "${hook}")"
        ( set -euo pipefail; . "${hook}" ) || \
            drx::die "Hook ${phase}/$(basename "${hook}") failed (exit $?)"
    done < <(find "${dir}" -maxdepth 1 -type f -print0 | sort -z)
}

# URL/host normalisation utilities reused by settings + services modules.
drx::normalize_hostname() {
    local raw="$1"
    raw="${raw#http://}"
    raw="${raw#https://}"
    raw="${raw%%/*}"
    raw="${raw%%\?*}"
    raw="${raw%%\#*}"
    raw="${raw%%:*}"
    [ -n "${raw}" ] || raw="localhost"
    printf '%s' "${raw}"
}

drx::normalize_origin() {
    local raw="$1"
    local default="$2"
    local scheme="http"
    if [ -z "${raw}" ]; then printf '%s' "${default}"; return; fi
    if   [[ "${raw}" == http://*  ]]; then scheme="http";  raw="${raw#http://}";
    elif [[ "${raw}" == https://* ]]; then scheme="https"; raw="${raw#https://}";
    fi
    raw="${raw%%/*}"; raw="${raw%%\?*}"; raw="${raw%%\#*}"
    [ -n "${raw}" ] || { printf '%s' "${default}"; return; }
    printf '%s://%s' "${scheme}" "${raw}"
}

drx::escape_regex() {
    printf '%s' "$1" | sed -e 's/[][\\/.^$*+?(){}|]/\\&/g'
}

# Finalize step is small enough to live with common.
drx::finalize::cache_rebuild() {
    drx::log "Rebuilding Drupal caches"
    drx::drush cache:rebuild >/dev/null
}
