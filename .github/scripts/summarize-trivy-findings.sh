#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${ARTIFACT_NAME:-}" ]]; then
  echo "ARTIFACT_NAME is required"
  exit 1
fi

json_file="trivy-${ARTIFACT_NAME}.json"
critical=$(jq '[.Results[]?.Vulnerabilities[]? | select(.Severity == "CRITICAL")] | length' "$json_file")
high=$(jq '[.Results[]?.Vulnerabilities[]? | select(.Severity == "HIGH")] | length' "$json_file")
total=$((critical + high))

support_lines_csv=$(jq -r 'join(", ")' <<< "${MATRIX_SUPPORT_LINES_JSON}")
image_tags_csv=$(jq -r 'join(", ")' <<< "${MATRIX_IMAGE_TAGS_JSON}")
release_tags_csv=$(jq -r 'join(", ")' <<< "${MATRIX_RELEASE_TAGS_JSON}")

{
  echo "### Trivy results for ${MATRIX_DIGEST_REF}"
  echo ""
  echo "- support lines: ${support_lines_csv}"
  echo "- image tags: ${image_tags_csv}"
  echo "- release tags: ${release_tags_csv}"
  echo "- sarif category: ${MATRIX_CATEGORY}"
  echo ""
  echo "| severity | count |"
  echo "| --- | --- |"
  echo "| CRITICAL | ${critical} |"
  echo "| HIGH | ${high} |"
  echo "| TOTAL | ${total} |"
} >> "$GITHUB_STEP_SUMMARY"