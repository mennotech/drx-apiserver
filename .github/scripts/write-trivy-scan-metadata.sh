#!/usr/bin/env bash
# Purpose: emit the JSON metadata sidecar that accompanies the Trivy scan
# artifact (digest, image/release tags, support lines, category).
set -euo pipefail

if [[ -z "${ARTIFACT_NAME:-}" ]]; then
  echo "ARTIFACT_NAME is required"
  exit 1
fi

jq -n \
  --arg digest_ref "${MATRIX_DIGEST_REF}" \
  --arg digest "${MATRIX_DIGEST}" \
  --argjson image_tags "${MATRIX_IMAGE_TAGS_JSON}" \
  --argjson release_tags "${MATRIX_RELEASE_TAGS_JSON}" \
  --argjson support_lines "${MATRIX_SUPPORT_LINES_JSON}" \
  --arg category "${MATRIX_CATEGORY}" \
  '{
    digest_ref: $digest_ref,
    digest: $digest,
    image_tags: $image_tags,
    release_tags: $release_tags,
    support_lines: $support_lines,
    sarif_category: $category
  }' > "trivy-${ARTIFACT_NAME}-metadata.json"