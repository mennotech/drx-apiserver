#!/bin/bash
# Storage preparation: ensure the state directory and files directory exist
# with correct ownership before Drupal is installed or queried.

drx::storage::prepare() {
    drx::log "Preparing state and files directories"

    install -d -o www-data -g www-data -m 0770 "${DRUPAL_STATE_DIR}"

    # Files directory: if a parent symlink is provided via DRUPAL_FILES_TARGET
    # (typical for orchestrators that mount a persistent volume elsewhere),
    # symlink files/ to that target. Otherwise treat files/ as a real dir.
    if [ -n "${DRUPAL_FILES_TARGET:-}" ]; then
        drx::log "Symlinking files/ -> ${DRUPAL_FILES_TARGET}"
        mkdir -p "${DRUPAL_FILES_TARGET}"
        chown -R www-data:www-data "${DRUPAL_FILES_TARGET}"
        chmod 0770 "${DRUPAL_FILES_TARGET}"
        if [ ! -L "${DRUPAL_FILES_DIR}" ]; then
            rm -rf "${DRUPAL_FILES_DIR}"
            ln -s "${DRUPAL_FILES_TARGET}" "${DRUPAL_FILES_DIR}"
        fi
        chown -h www-data:www-data "${DRUPAL_FILES_DIR}"
    else
        install -d -o www-data -g www-data -m 0770 "${DRUPAL_FILES_DIR}"
    fi

    # Apache ServerName: derived from BACKEND_URL but overridable.
    local server_name
    server_name="$(drx::normalize_hostname "${DRUPAL_HOSTNAME:-${BACKEND_URL}}")"
    echo "ServerName ${server_name}" > /etc/apache2/conf-available/servername.conf
    a2enconf servername >/dev/null 2>&1 || true
}
