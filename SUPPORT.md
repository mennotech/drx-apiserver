# Support Policy

This document defines which `drx-drupal-base` image lines are operationally
supported and how continuous vulnerability monitoring is scoped.

For security reporting and coordinated disclosure, see
[SECURITY.md](SECURITY.md). For release cadence and tag policy, see
[RELEASES.md](RELEASES.md).

---

## Supported image lines

For day-to-day operations, support is tracked at the minor-line level:

- **Current minor line**: latest patch in the newest `X.Y` line.
- **Previous minor line**: latest patch in the prior `X.Y` line.

Older lines are out of operational support unless explicitly called out in
release notes.

While the project remains on `0.x`, release candidates may be the only
available tags in a line. In that case, monitoring falls back to the latest
available `-rc` tag(s) until stable `vX.Y.Z` releases exist.

---

## Continuous vulnerability monitoring

Workflow: [.github/workflows/supported-image-security-scan.yml](.github/workflows/supported-image-security-scan.yml)

Schedule:

- Daily (`cron`)
- Manual (`workflow_dispatch`)

Process:

1. Resolve release tags from GitHub Releases.
2. Select latest patch for current and previous minor lines.
3. Resolve selected tags to image digests.
4. Deduplicate by digest.
5. Run Trivy (`CRITICAL,HIGH`, `ignore-unfixed=true`) for each digest.
6. Upload SARIF to GitHub code scanning.
7. Upload JSON/SARIF/metadata as workflow artifacts.

This keeps scan scope bounded and avoids unscalable full-history rescans.

---

## Where results live

- **GitHub Security / Code scanning**: alert surface for uploaded SARIF.
- **GitHub Actions run summary**: per-run human-readable severity counts.
- **GitHub Actions artifacts** (30-day retention):
  - `supported-scan-targets.json` (resolved targets and digests)
  - `trivy-*.sarif` (code scanning upload payload)
  - `trivy-*.json` (machine-readable vulnerability results)
  - `trivy-*-metadata.json` (digest, tags, support-line mapping)

For external alerting automation, consume the JSON + metadata artifacts
from completed workflow runs.