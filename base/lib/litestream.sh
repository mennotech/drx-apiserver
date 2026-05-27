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
#   drx::litestream::restore        — bootstrap restore from replica (no-op if disabled)
#   drx::litestream::exec_wrap      — wrap final exec line; writer role uses
#                                     `litestream replicate --exec` so the
#                                     replicate daemon owns Apache and gets a
#                                     graceful checkpoint on shutdown.
#
# Subsequent phases will flesh out write_config / restore / exec_wrap;
# Phase 1 only establishes the scaffolding and a safe no-op default so
# the existing bootstrap path is unchanged.
# =============================================================================

# Defaults are set in common.sh (Phase 4). For Phase 1 we only need a hard
# guard so any premature call is a clean no-op when the binary is absent
# or the feature is disabled.

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

drx::litestream::write_config() {
    # Phase 2 implements config rendering.
    return 0
}

drx::litestream::restore() {
    # Phase 2 implements restore-before-install behaviour.
    return 0
}

drx::litestream::exec_wrap() {
    # Phase 3 will replace this no-op with role-aware exec wrapping.
    # For now, behave identically to plain `exec "$@"` so this helper can
    # be wired into init.sh without changing runtime behaviour.
    exec "$@"
}
