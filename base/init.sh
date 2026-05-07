#!/bin/bash
# =============================================================================
# drx-init — Drupal 10 base image bootstrap orchestrator.
#
# Loads decomposed bootstrap modules from $DRX_LIB_DIR and runs lifecycle
# hooks from $DRX_HOOKS_DIR. Downstream images extend behavior by dropping
# scripts into the appropriate hook directory; they should not edit this
# file or the lib/ modules.
#
# Set DRX_DISABLE_INIT=1 to skip bootstrap entirely (useful for shell/CLI
# containers reusing the base image).
# =============================================================================
set -euo pipefail

DRX_LIB_DIR="${DRX_LIB_DIR:-/usr/local/lib/drx}"
DRX_HOOKS_DIR="${DRX_HOOKS_DIR:-/etc/drx/hooks}"

# shellcheck source=lib/common.sh
. "${DRX_LIB_DIR}/common.sh"

if [ "${DRX_DISABLE_INIT:-0}" = "1" ]; then
    drx::log "DRX_DISABLE_INIT=1; skipping bootstrap and execing CMD."
    exec "$@"
fi

drx::log "drx-drupal-base ${DRX_BASE_VERSION:-unknown} starting bootstrap"

# shellcheck source=lib/storage.sh
. "${DRX_LIB_DIR}/storage.sh"
# shellcheck source=lib/settings.sh
. "${DRX_LIB_DIR}/settings.sh"
# shellcheck source=lib/services.sh
. "${DRX_LIB_DIR}/services.sh"
# shellcheck source=lib/install.sh
. "${DRX_LIB_DIR}/install.sh"
# shellcheck source=lib/modules.sh
. "${DRX_LIB_DIR}/modules.sh"
# shellcheck source=lib/config_import.sh
. "${DRX_LIB_DIR}/config_import.sh"
# shellcheck source=lib/api.sh
. "${DRX_LIB_DIR}/api.sh"

drx::run_hooks pre-bootstrap.d

drx::storage::prepare
drx::settings::write
drx::services::write
drx::install::ensure
drx::modules::enable_base
drx::run_hooks post-install.d
drx::config_import::run
drx::run_hooks post-config-import.d
drx::modules::enable_extras
drx::run_hooks post-modules.d
drx::api::configure
drx::finalize::cache_rebuild

drx::run_hooks post-bootstrap.d

drx::log "Bootstrap complete; handing off to: $*"
exec "$@"
