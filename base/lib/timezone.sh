#!/bin/bash
# Resolves and synchronizes the Drupal site timezone.
#
# Contract:
# - If DRX_TIMEZONE is set, it wins.
# - Otherwise, the lookup runs only on a fresh Drupal installation.
# - If lookup fails, UTC is used.
# - On later boots, the site config is updated only when DRX_TIMEZONE
#   differs from the current Drupal config value.

drx::timezone::_is_valid() {
    local timezone="$1"

    [ -n "${timezone}" ] || return 1
    [ -f "/usr/share/zoneinfo/${timezone}" ] && return 0
    [ -f "/usr/share/zoneinfo/posix/${timezone}" ] && return 0
    [ -f "/usr/share/zoneinfo/right/${timezone}" ] && return 0

    case "${timezone}" in
        UTC|Etc/UTC|Etc/GMT)
            return 0
            ;;
    esac

    return 1
}

drx::timezone::_lookup() {
    local candidate url

    for url in \
        https://ipinfo.io/timezone \
        https://ipapi.co/timezone/
    do
        candidate="$(curl -fsSL --max-time 4 "${url}" 2>/dev/null | tr -d '[:space:]' || true)"
        if drx::timezone::_is_valid "${candidate}"; then
            printf '%s' "${candidate}"
            return 0
        fi
    done

    printf 'UTC'
}

drx::timezone::_current() {
    drx::drush ev "print \\Drupal::config('system.date')->get('timezone.default');" 2>/dev/null || true
}

drx::timezone::_write() {
    local timezone="$1"

    drx::drush cset --yes system.date timezone.default "${timezone}" >/dev/null
}

drx::timezone::ensure() {
    local desired current source
    local fresh_install="${DRX_INSTALL_WAS_FRESH:-0}"

    if [ -n "${DRX_TIMEZONE}" ]; then
        if drx::timezone::_is_valid "${DRX_TIMEZONE}"; then
            desired="${DRX_TIMEZONE}"
        else
            drx::warn "DRX_TIMEZONE=${DRX_TIMEZONE} is not a recognized timezone; using UTC"
            desired="UTC"
        fi
        source="env"
    elif [ "${fresh_install}" = "1" ]; then
        drx::log "Detecting Drupal timezone from public IP for fresh install"
        desired="$(drx::timezone::_lookup)"
        source="lookup"
    else
        drx::log "Timezone: DRX_TIMEZONE not set; preserving existing Drupal site config"
        return 0
    fi

    current="$(drx::timezone::_current)"
    if [ "${current}" = "${desired}" ]; then
        drx::log "Drupal timezone already set to ${desired}"
        return 0
    fi

    drx::log "Setting Drupal timezone to ${desired} (${source})"
    drx::timezone::_write "${desired}" || drx::warn "Failed to update Drupal timezone to ${desired}"
}

# Reconciles the Drupal site timezone after config:import.
# Runs only when DRX_TIMEZONE is explicitly set, in case config import
# overwrote the value. Does not re-trigger the IP lookup.
drx::timezone::reconcile() {
    [ -n "${DRX_TIMEZONE}" ] || return 0
    local saved="${DRX_INSTALL_WAS_FRESH:-0}"
    DRX_INSTALL_WAS_FRESH=0
    drx::timezone::ensure
    DRX_INSTALL_WAS_FRESH="${saved}"
}