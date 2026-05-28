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

# Map the shared S3 connection onto Litestream's existing env-var contract
# so litestream.sh keeps working unchanged. Operator-supplied DRX_LITESTREAM_*
# values still win — only unset variables are filled in here.
drx::s3::bridge_litestream() {
    drx::s3::required || return 0
    [ -n "${DRX_S3_BUCKET}" ] || return 0

    : "${DRX_LITESTREAM_ENABLED:=1}"
    : "${DRX_LITESTREAM_REPLICA_URL:=s3://${DRX_S3_BUCKET}/${DRX_S3_PREFIX_LITESTREAM}}"
    : "${DRX_LITESTREAM_ENDPOINT:=${DRX_S3_ENDPOINT}}"
    : "${DRX_LITESTREAM_REGION:=${DRX_S3_REGION}}"
    : "${DRX_LITESTREAM_FORCE_PATH_STYLE:=${DRX_S3_FORCE_PATH_STYLE}}"
    : "${LITESTREAM_ACCESS_KEY_ID:=${DRX_S3_ACCESS_KEY_ID}}"
    : "${LITESTREAM_SECRET_ACCESS_KEY:=${DRX_S3_SECRET_ACCESS_KEY}}"

    export DRX_LITESTREAM_ENABLED DRX_LITESTREAM_REPLICA_URL \
           DRX_LITESTREAM_ENDPOINT DRX_LITESTREAM_REGION \
           DRX_LITESTREAM_FORCE_PATH_STYLE \
           LITESTREAM_ACCESS_KEY_ID LITESTREAM_SECRET_ACCESS_KEY
}

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
