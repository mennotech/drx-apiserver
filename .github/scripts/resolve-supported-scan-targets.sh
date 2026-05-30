#!/usr/bin/env bash
set -euo pipefail

required_vars=(GITHUB_REPOSITORY GITHUB_TOKEN IMAGE_REF GITHUB_OUTPUT GITHUB_STEP_SUMMARY)
for var_name in "${required_vars[@]}"; do
  if [[ -z "${!var_name:-}" ]]; then
    echo "::error::Required environment variable ${var_name} is not set."
    exit 2
  fi
done

# shellcheck disable=SC2153
# IMAGE_REF is a required environment input validated above.
base_image_ref="${IMAGE_REF}"

api_url="https://api.github.com/repos/${GITHUB_REPOSITORY}/releases?per_page=100"
releases_json=$(curl -fsSL \
  -H "Authorization: Bearer ${GITHUB_TOKEN}" \
  -H "Accept: application/vnd.github+json" \
  "$api_url")

parsed=$(jq -c '
  [ .[]
    | select(.draft | not)
    | .tag_name as $tag
    | ($tag | capture("^v(?<major>[0-9]+)\\.(?<minor>[0-9]+)\\.(?<patch>[0-9]+)(?:-rc(?<rc>[0-9]+))?$")?) as $m
    | select($m != null)
    | {
        release_tag: $tag,
        image_tag: ($tag | ltrimstr("v")),
        major: ($m.major | tonumber),
        minor: ($m.minor | tonumber),
        patch: ($m.patch | tonumber),
        rc: (if (($m.rc // "") == "") then 999999 else ($m.rc | tonumber) end),
        stable: ($tag | test("-rc") | not),
        line: "\($m.major).\($m.minor)"
      }
  ]
' <<< "$releases_json")

if [[ "$(jq 'length' <<< "$parsed")" -eq 0 ]]; then
  echo "::error::No SemVer release tags found (expected vX.Y.Z or vX.Y.Z-rcN)."
  exit 1
fi

stable=$(jq -c '[ .[] | select(.stable) ]' <<< "$parsed")
if [[ "$(jq 'length' <<< "$stable")" -gt 0 ]]; then
  mode="stable"
  pool="$stable"
else
  mode="pre-release-fallback"
  pool="$parsed"
fi

selected=$(jq -c '
  sort_by(.major, .minor, .patch, .rc)
  | reverse
  | reduce .[] as $item ([];
      if (map(.line) | index($item.line)) == null then
        . + [$item]
      else
        .
      end
    )
  | .[:2]
' <<< "$pool")

if [[ "$(jq 'length' <<< "$selected")" -eq 0 ]]; then
  echo "::error::No supported lines resolved from release data."
  exit 1
fi

resolved='[]'
skipped='[]'
while IFS= read -r row; do
  line=$(jq -r '.line' <<< "$row")
  image_tag=$(jq -r '.image_tag' <<< "$row")
  release_tag=$(jq -r '.release_tag' <<< "$row")
  image_ref="${base_image_ref}:${image_tag}"

  inspect_output=""
  if ! inspect_output=$(docker buildx imagetools inspect "$image_ref" 2>&1); then
    skipped=$(jq -c \
      --arg line "$line" \
      --arg image_tag "$image_tag" \
      --arg release_tag "$release_tag" \
      --arg image_ref "$image_ref" \
      --arg reason "$inspect_output" \
      '. + [{
        line: $line,
        image_tag: $image_tag,
        release_tag: $release_tag,
        image_ref: $image_ref,
        reason: $reason
      }]' \
      <<< "$skipped")
    echo "::warning::Skipping $image_ref: unable to inspect manifest."
    continue
  fi

  digest=$(awk '/^Digest:/ { print $2; exit }' <<< "$inspect_output")
  if [[ -z "$digest" ]]; then
    skipped=$(jq -c \
      --arg line "$line" \
      --arg image_tag "$image_tag" \
      --arg release_tag "$release_tag" \
      --arg image_ref "$image_ref" \
      --arg reason "Digest field not found in imagetools output." \
      '. + [{
        line: $line,
        image_tag: $image_tag,
        release_tag: $release_tag,
        image_ref: $image_ref,
        reason: $reason
      }]' \
      <<< "$skipped")
    echo "::warning::Skipping $image_ref: digest not found in inspect output."
    continue
  fi

  resolved=$(jq -c \
    --arg line "$line" \
    --arg image_tag "$image_tag" \
    --arg release_tag "$release_tag" \
    --arg image_ref "$image_ref" \
    --arg digest "$digest" \
    '. + [{
      line: $line,
      image_tag: $image_tag,
      release_tag: $release_tag,
      image_ref: $image_ref,
      digest: $digest
    }]' \
    <<< "$resolved")
done < <(jq -c '.[]' <<< "$selected")

if [[ "$(jq 'length' <<< "$resolved")" -eq 0 ]]; then
  echo "::error::No published images were resolvable from selected release tags."
  echo "::group::Selected release rows"
  jq '.' <<< "$selected"
  echo "::endgroup::"
  echo "::group::Skipped targets"
  jq '.' <<< "$skipped"
  echo "::endgroup::"
  exit 1
fi

matrix=$(jq -c --arg image "$base_image_ref" '
  sort_by(.digest)
  | group_by(.digest)
  | map({
      digest: .[0].digest,
      digest_ref: ($image + "@" + .[0].digest),
      image_tags: (map(.image_tag) | unique),
      release_tags: (map(.release_tag) | unique),
      support_lines: (map(.line) | unique),
      category: ("trivy-supported-" + ((map(.line) | unique | join("-")) | gsub("[^A-Za-z0-9_.-]"; "-"))),
      artifact_name: ((map(.image_tag) | unique | join("__")) | gsub("[^A-Za-z0-9_.-]"; "-"))
    })
' <<< "$resolved")

if [[ "$(jq 'length' <<< "$matrix")" -eq 0 ]]; then
  echo "::error::No scan matrix entries produced."
  exit 1
fi

generated_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
jq -n \
  --arg mode "$mode" \
  --arg image "$base_image_ref" \
  --arg generated_at "$generated_at" \
  --argjson selected "$selected" \
  --argjson resolved "$resolved" \
  --argjson skipped "$skipped" \
  --argjson matrix "$matrix" \
  '{
    mode: $mode,
    image: $image,
    generated_at: $generated_at,
    selected_release_lines: $selected,
    resolved_targets: $resolved,
    skipped_targets: $skipped,
    scan_matrix: $matrix
  }' > supported-scan-targets.json

{
  echo "mode=$mode"
  echo "matrix=$matrix"
} >> "$GITHUB_OUTPUT"

{
  echo "### Supported image scan targets"
  echo ""
  echo "- Selection mode: $mode"
  echo "- Image: $base_image_ref"
  echo ""
  echo "| support line | image tag | digest |"
  echo "| --- | --- | --- |"
  jq -r '.resolved_targets[] | "| \(.line) | \(.image_tag) | \(.digest) |"' supported-scan-targets.json

  skipped_count=$(jq '.skipped_targets | length' supported-scan-targets.json)
  if [[ "$skipped_count" -gt 0 ]]; then
    echo ""
    echo "Skipped unresolved targets: ${skipped_count}"
    jq -r '.skipped_targets[] | "- \(.image_ref): \(.reason | split("\n")[0])"' supported-scan-targets.json
  fi
} >> "$GITHUB_STEP_SUMMARY"