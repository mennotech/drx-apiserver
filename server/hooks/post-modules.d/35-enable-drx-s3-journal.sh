#!/bin/bash
# Reference-app hook: enable the drx_s3_journal module so end-user
# content changes through public:// and private:// are captured as
# immutable JSON objects under the journal/ prefix of the shared S3
# bucket. Safe to run repeatedly.
set -euo pipefail

drx::drush pm:enable --yes drx_s3_journal || \
    drx::warn "drx_s3_journal module not available; skipping"
