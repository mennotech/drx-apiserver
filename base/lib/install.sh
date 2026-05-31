#!/bin/bash
# Detects whether Drupal is installed and runs site:install if not.

drx::install::_db_ready() {
    case "${DRUPAL_DB_DRIVER}" in
        sqlite)
            [ -f "${DRUPAL_SQLITE_PATH}" ] || return 1
            local n
            n="$(sqlite3 "${DRUPAL_SQLITE_PATH}" \
                "SELECT count(*) FROM sqlite_master WHERE type='table' AND name='users';" \
                2>/dev/null || echo 0)"
            [ "${n}" = "1" ]
            ;;
        *)
            # For external DBs, rely on drush's own check.
            drx::drush status --field=bootstrap 2>/dev/null | grep -qi 'successful'
            ;;
    esac
}

# Render the db-url string drush site:install expects for the active DB driver.
drx::install::_db_url() {
    case "${DRUPAL_DB_DRIVER}" in
        sqlite) printf 'sqlite:///%s' "${DRUPAL_SQLITE_PATH}" ;;
        mysql|pgsql)
            printf '%s://%s:%s@%s:%s/%s' \
                "${DRUPAL_DB_DRIVER}" \
                "${DRUPAL_DB_USER:-}" \
                "${DRUPAL_DB_PASS:-}" \
                "${DRUPAL_DB_HOST:-localhost}" \
                "${DRUPAL_DB_PORT:-}" \
                "${DRUPAL_DB_NAME:-}"
            ;;
    esac
}

# Idempotent installer: run site:install when the DB is empty, otherwise no-op.
# Sets DRX_INSTALL_WAS_FRESH=1 on a fresh install for downstream hooks.
drx::install::ensure() {
    # shellcheck disable=SC2034
    # This flag is consumed by other sourced drx:: functions (e.g. timezone).
    DRX_INSTALL_WAS_FRESH=0

    if drx::install::_db_ready; then
        drx::log "Drupal already installed"
        return 0
    fi

    [ -n "${DRUPAL_ADMIN_PASS}" ] || drx::die \
        "DRUPAL_ADMIN_PASS must be set before initial Drupal installation."

    drx::log "Installing Drupal (profile=${DRUPAL_INSTALL_PROFILE})"

    if [ "${DRUPAL_DB_DRIVER}" = "sqlite" ]; then
        local db_dir
        db_dir="$(dirname "${DRUPAL_SQLITE_PATH}")"
        install -d -o www-data -g www-data -m 0770 "${db_dir}"
    fi

    chmod 0440 "${DRUPAL_SETTINGS_PHP}" 2>/dev/null || true

    drx::drush site:install "${DRUPAL_INSTALL_PROFILE}" \
        --db-url="$(drx::install::_db_url)" \
        --account-name="${DRUPAL_ADMIN_USER}" \
        --account-pass="${DRUPAL_ADMIN_PASS}" \
        --site-name="${DRUPAL_SITE_NAME}" \
        --yes

    # shellcheck disable=SC2034
    DRX_INSTALL_WAS_FRESH=1
}
