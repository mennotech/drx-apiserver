#!/bin/bash
# Generates services.yml with CORS configuration derived from FRONTEND_URL
# and the optional CORS_ALLOWED_ORIGINS override.

drx::services::write() {
    local default_origin origins=()
    default_origin="$(drx::normalize_origin "${FRONTEND_URL}" "http://localhost:3000")"

    if [ -n "${CORS_ALLOWED_ORIGINS}" ]; then
        IFS=',' read -ra _ao <<< "${CORS_ALLOWED_ORIGINS}"
        for o in "${_ao[@]}"; do
            o="$(echo "${o}" | xargs)"
            [ -n "${o}" ] && origins+=("${o}")
        done
    fi
    [ ${#origins[@]} -eq 0 ] && origins=("${default_origin}")

    drx::log "Generating services.yml (CORS origins: ${origins[*]})"

    {
        cat <<'YML'
parameters:
  session.storage.options:
    gc_probability: 1
    gc_divisor: 100
    gc_maxlifetime: 200000
    cookie_lifetime: 2000000

  cors.config:
    enabled: true
    allowedHeaders:
      - '*'
    allowedMethods:
      - 'GET'
      - 'POST'
      - 'PATCH'
      - 'PUT'
      - 'DELETE'
      - 'OPTIONS'
    allowedOrigins:
YML
        for o in "${origins[@]}"; do
            printf "      - '%s'\n" "${o}"
        done
        cat <<'YML'
    allowedOriginsPatterns: []
    exposedHeaders: false
    maxAge: false
    supportsCredentials: true
YML
    } > "${DRUPAL_SERVICES_YML}"

    chown www-data:www-data "${DRUPAL_SERVICES_YML}"
    chmod 0440 "${DRUPAL_SERVICES_YML}"
}
