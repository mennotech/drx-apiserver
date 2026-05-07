#!/bin/bash
# Applies API-layer policy.
#
# JSON:API mode:
#   DRUPAL_JSONAPI_READ_ONLY=1  (default — secure)
#   DRUPAL_JSONAPI_READ_ONLY=0  (write mode; downstream opt-in)

drx::api::configure() {
    if drx::drush pm:list --status=enabled --field=name 2>/dev/null | grep -qx jsonapi; then
        drx::log "Setting jsonapi.settings:read_only=${DRUPAL_JSONAPI_READ_ONLY}"
        drx::drush cset --yes jsonapi.settings read_only \
            "${DRUPAL_JSONAPI_READ_ONLY}" || \
            drx::warn "Failed to set jsonapi read_only flag"
    fi
}
