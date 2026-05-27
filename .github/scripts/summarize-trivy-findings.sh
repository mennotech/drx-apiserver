#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${ARTIFACT_NAME:-}" ]]; then
  echo "ARTIFACT_NAME is required"
  exit 1
fi

json_file="trivy-${ARTIFACT_NAME}.json"
critical=$(jq '[.Results[]?.Vulnerabilities[]? | select((.Severity // "") | ascii_upcase == "CRITICAL")] | length' "$json_file")
high=$(jq '[.Results[]?.Vulnerabilities[]? | select((.Severity // "") | ascii_upcase == "HIGH")] | length' "$json_file")
medium=$(jq '[.Results[]?.Vulnerabilities[]? | select((.Severity // "") | ascii_upcase == "MEDIUM")] | length' "$json_file")
low=$(jq '[.Results[]?.Vulnerabilities[]? | select((.Severity // "") | ascii_upcase == "LOW")] | length' "$json_file")
unknown=$(jq '[.Results[]?.Vulnerabilities[]? | select(((.Severity // "UNKNOWN") | ascii_upcase) == "UNKNOWN")] | length' "$json_file")
total_all=$((critical + high + medium + low + unknown))
total_high_critical=$((critical + high))

detail_limit="${DETAIL_LIMIT:-30}"

support_lines_csv=$(jq -r 'join(", ")' <<< "${MATRIX_SUPPORT_LINES_JSON}")
image_tags_csv=$(jq -r 'join(", ")' <<< "${MATRIX_IMAGE_TAGS_JSON}")
release_tags_csv=$(jq -r 'join(", ")' <<< "${MATRIX_RELEASE_TAGS_JSON}")
sarif_scope="${SARIF_SEVERITY_SCOPE:-CRITICAL,HIGH}"
json_scope="${JSON_SEVERITY_SCOPE:-UNKNOWN,LOW,MEDIUM,HIGH,CRITICAL}"

{
  echo "### Trivy results for ${MATRIX_DIGEST_REF}"
  echo ""
  echo "- support lines: ${support_lines_csv}"
  echo "- image tags: ${image_tags_csv}"
  echo "- release tags: ${release_tags_csv}"
  echo "- sarif category: ${MATRIX_CATEGORY}"
  echo "- SARIF severities: ${sarif_scope}"
  echo "- JSON severities: ${json_scope}"
  echo ""
  echo "| severity | count |"
  echo "| --- | --- |"
  echo "| UNKNOWN | ${unknown} |"
  echo "| LOW | ${low} |"
  echo "| MEDIUM | ${medium} |"
  echo "| HIGH | ${high} |"
  echo "| CRITICAL | ${critical} |"
  echo "| TOTAL (all) | ${total_all} |"
  echo "| HIGH+CRITICAL | ${total_high_critical} |"

  if [[ "$total_all" -gt 0 ]]; then
    echo ""
    echo "Top findings (up to ${detail_limit}, sorted by severity):"
    echo ""
    echo "| severity | vulnerability | package | installed | fixed | target | title |"
    echo "| --- | --- | --- | --- | --- | --- | --- |"
    jq -r --argjson limit "$detail_limit" '
      [ .Results[]? as $r
        | ($r.Vulnerabilities // [])[]?
        | {
            severity: ((.Severity // "UNKNOWN") | ascii_upcase),
            vulnerability: (.VulnerabilityID // "-"),
            package: (.PkgName // "-"),
            installed: (.InstalledVersion // "-"),
            fixed: (if ((.FixedVersion // "") == "") then "-" else .FixedVersion end),
            target: (($r.Target // "-") | tostring | gsub("\\|"; "/")),
            title: (((.Title // .Description // "-") | tostring | gsub("[\\r\\n]+"; " ") | gsub("\\|"; "/")))
          }
        | . + {
            rank: (if .severity == "CRITICAL" then 5
                   elif .severity == "HIGH" then 4
                   elif .severity == "MEDIUM" then 3
                   elif .severity == "LOW" then 2
                   else 1 end)
          }
      ]
      | sort_by(-.rank, .vulnerability, .package, .installed)
      | .[:$limit]
      | .[]
      | "| \(.severity) | \(.vulnerability) | \(.package) | \(.installed) | \(.fixed) | \(.target) | \(.title) |"
    ' "$json_file"
  fi
} >> "$GITHUB_STEP_SUMMARY"