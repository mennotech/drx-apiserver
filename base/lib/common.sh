#!/bin/bash
# =============================================================================
# drx-drupal-base : common helpers shared by all bootstrap modules.
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

# Database contract (sqlite-first; mysql/pgsql ready for future use).
export DRUPAL_DB_DRIVER="${DRUPAL_DB_DRIVER:-sqlite}"
export DRUPAL_SQLITE_PATH="${DRUPAL_SQLITE_PATH:-${DRUPAL_STATE_DIR}/db.sqlite}"

# Site identity / runtime URLs.
export DRUPAL_ADMIN_USER="${DRUPAL_ADMIN_USER:-admin}"
export DRUPAL_ADMIN_PASS="${DRUPAL_ADMIN_PASS:-}"
export DRUPAL_SITE_NAME="${DRUPAL_SITE_NAME:-Drupal}"
export DRUPAL_INSTALL_PROFILE="${DRUPAL_INSTALL_PROFILE:-standard}"
export BACKEND_URL="${BACKEND_URL:-http://localhost}"
export FRONTEND_URL="${FRONTEND_URL:-http://localhost:3000}"
export CORS_ALLOWED_ORIGINS="${CORS_ALLOWED_ORIGINS:-}"

# Module/API contract. Secure-by-default: read-only JSON:API.
export DRUPAL_BASE_MODULES="${DRUPAL_BASE_MODULES:-jsonapi serialization basic_auth rest}"
export DRUPAL_EXTRA_MODULES="${DRUPAL_EXTRA_MODULES:-}"
export DRUPAL_JSONAPI_READ_ONLY="${DRUPAL_JSONAPI_READ_ONLY:-1}"

# Trusted hosts: caller-provided regex patterns, comma-separated. Localhost
# and the BACKEND_URL host are always added.
export DRUPAL_TRUSTED_HOST_PATTERNS="${DRUPAL_TRUSTED_HOST_PATTERNS:-}"

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
