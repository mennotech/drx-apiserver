#!/usr/bin/env bash
set -euo pipefail

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
while IFS= read -r row; do
  line=$(jq -r '.line' <<< "$row")
  image_tag=$(jq -r '.image_tag' <<< "$row")
  release_tag=$(jq -r '.release_tag' <<< "$row")
  image_ref="${IMAGE_REF}:${image_tag}"

  digest=$(docker buildx imagetools inspect "$image_ref" | awk '/^Digest:/ { print $2; exit }')
  if [[ -z "$digest" ]]; then
    echo "::error::Failed to resolve digest for $image_ref"
    exit 1
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

matrix=$(jq -c --arg image "$IMAGE_REF" '
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
  --arg image "$IMAGE_REF" \
  --arg generated_at "$generated_at" \
  --argjson selected "$selected" \
  --argjson resolved "$resolved" \
  --argjson matrix "$matrix" \
  '{
    mode: $mode,
    image: $image,
    generated_at: $generated_at,
    selected_release_lines: $selected,
    resolved_targets: $resolved,
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
  echo "- Image: $IMAGE_REF"
  echo ""
  echo "| support line | image tag | digest |"
  echo "| --- | --- | --- |"
  jq -r '.resolved_targets[] | "| \(.line) | \(.image_tag) | \(.digest) |"' supported-scan-targets.json
} >> "$GITHUB_STEP_SUMMARY"