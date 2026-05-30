#!/bin/bash
# =============================================================================
# drx-apiserver base image: S3 connection guard.
#
# The image treats persistent storage as ephemeral compute pointed at an
# S3 bucket: Litestream replicates the SQLite database under one prefix,
# and the Drupal file system (private files only) is fronted by s3fs
# under another. Both share a single set of credentials.
#
# This module:
#   1. Validates the shared DRX_S3_* environment.
#   2. Probes real bucket connectivity and enforces bucket versioning
#      via SigV4 HEAD + GetBucketVersioning.
#   3. Bridges the shared S3 config into Litestream's native env vars so
#      operators only configure the connection in one place.
#
# All three steps no-op when DRX_S3_REQUIRED=0 (CI/build escape hatch).
# =============================================================================

drx::s3::required() {
    [ "${DRX_S3_REQUIRED:-1}" = "1" ]
}

# Fail fast when S3 is required but mandatory DRX_S3_* env vars are missing.
drx::s3::validate_env() {
    if ! drx::s3::required; then
        drx::log "s3: DRX_S3_REQUIRED=0; skipping env validation"
        return 0
    fi

    local missing=()
    [ -n "${DRX_S3_BUCKET}" ]            || missing+=(DRX_S3_BUCKET)
    [ -n "${DRX_S3_ACCESS_KEY_ID}" ]     || missing+=(DRX_S3_ACCESS_KEY_ID)
    [ -n "${DRX_S3_SECRET_ACCESS_KEY}" ] || missing+=(DRX_S3_SECRET_ACCESS_KEY)

    if [ ${#missing[@]} -gt 0 ]; then
        drx::die "S3 is required (DRX_S3_REQUIRED=1) but missing env: ${missing[*]}. Set DRX_S3_REQUIRED=0 to bypass (CI only)."
    fi
}

# Map the shared S3 connection onto Litestream's env-var contract.
# The image now consumes DRX_S3_* directly for endpoint / region / path-style
# and only bridges the replica URL + credentials here.
drx::s3::bridge_litestream() {
    # Litestream lifecycle is derived from S3 posture: when S3 is bypassed,
    # replication is disabled regardless of any user-provided env.
    DRX_LITESTREAM_ENABLED="0"
    export DRX_LITESTREAM_ENABLED

    drx::s3::required || return 0
    [ -n "${DRX_S3_BUCKET}" ] || return 0

    local expected_replica_url="s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_LITESTREAM}"

    DRX_LITESTREAM_ENABLED="1"
    DRX_LITESTREAM_REPLICA_URL="${expected_replica_url}"

    # DRX_S3_* is the source of truth. Always bridge those values onto
    # Litestream's native env contract so callers cannot accidentally
    # split credentials between two independent variable sets.
    LITESTREAM_ACCESS_KEY_ID="${DRX_S3_ACCESS_KEY_ID}"
    LITESTREAM_SECRET_ACCESS_KEY="${DRX_S3_SECRET_ACCESS_KEY}"

    export DRX_LITESTREAM_ENABLED DRX_LITESTREAM_REPLICA_URL \
           LITESTREAM_ACCESS_KEY_ID LITESTREAM_SECRET_ACCESS_KEY
}

# Probe S3 connectivity + bucket versioning via the PHP helper; die on failure.
drx::s3::probe() {
    if ! drx::s3::required; then
        drx::log "s3: DRX_S3_REQUIRED=0; skipping connectivity probe"
        return 0
    fi

    drx::log "s3: probing bucket=${DRX_S3_BUCKET} endpoint=${DRX_S3_ENDPOINT:-aws-default} (versioning required)"
    if ! php "${DRX_LIB_DIR}/s3_probe.php"; then
        drx::die "S3 probe failed (connectivity and versioning are required). Verify DRX_S3_* env, bucket permissions, and bucket versioning, or set DRX_S3_REQUIRED=0 to bypass (CI only)."
    fi
}

# Orchestrator: call from init.sh before storage::prepare.
drx::s3::ensure() {
    drx::s3::validate_env
    drx::s3::bridge_litestream
    drx::s3::probe
}

# Enable the s3fs module after install so Drupal's stream-wrapper takeover
# settings (declared in settings.php) have a backing implementation. No-op
# when S3 is bypassed.
drx::s3::enable_module() {
    drx::s3::required || return 0
    drx::log "s3: enabling s3fs module"
    if ! drx::drush pm:enable --yes s3fs >/dev/null 2>&1; then
        drx::warn "s3: could not enable s3fs module (may already be enabled or unavailable)"
    fi
}
