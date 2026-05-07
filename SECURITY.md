# Security Policy

Security is a first-class concern for `drx-apiserver` and the published
`drx-drupal-base` image. This document describes how to report
vulnerabilities and what to expect in response.

## Supported versions

Security fixes are issued for the version lines defined in
[RELEASES.md](RELEASES.md#support-window):

- **Latest minor (`X.Y`)** — receives all monthly patch releases and all
  emergency security releases.
- **Previous minor (`X.(Y-1)`)** — emergency security releases for
  **90 days** after the next minor is published, then end-of-life.
- **Previous major** — security-only patches for **180 days** after a
  new major is released.
- **Older lines** — unsupported. Upgrade to a supported version.

While the project is on the `0.x` line the runtime contract is not yet
stabilized; every `0.0.x` release should be treated as a preview. The
support window above formally activates at `1.0.0`.

## Reporting a vulnerability

**Do not file public GitHub issues for security problems.**

Report privately through GitHub Security Advisories:

- <https://github.com/mennotech/drx-apiserver/security/advisories/new>

Please include, where possible:

- Affected component (`base/`, `server/`, CI, docs).
- Affected version(s) — image tag(s) or commit SHA(s).
- Reproduction steps or proof of concept.
- Impact assessment (confidentiality / integrity / availability).
- Whether the issue is already public or under embargo elsewhere.

If the issue concerns an upstream dependency (Drupal core, a contrib
module, PHP, Apache, the upstream PHP base image), please also report it
to that project. We will coordinate with upstream advisories where
relevant.

## Response process

1. **Acknowledgement** — within **3 business days** of receipt.
2. **Triage** — initial severity assessment (CVSS v3.1) and a target
   remediation window. Typical targets:
   - Critical: patched release within **7 days**.
   - High: within **14 days**.
   - Medium / Low: rolled into the next monthly release.
3. **Fix** — developed in a private GitHub Security Advisory fork.
4. **Release** — emergency security release per
   [RELEASES.md → Emergency security releases](RELEASES.md#emergency-security-releases).
   Published tags include SBOM and SLSA build provenance.
5. **Disclosure** — the GitHub Security Advisory is published once the
   fixed release is available. Reporters are credited unless they
   request otherwise.

If a report turns out not to describe a security issue, we will say so
and, with your permission, convert it to a public issue or discussion.

## Scope

In scope:

- The published `drx-drupal-base` image and its build inputs in
  [base/](base/).
- The reference overlay in [server/](server/) when used as documented.
- The CI/CD configuration in [.github/](.github/).

Out of scope:

- Findings that require an attacker who already has root or the
  `www-data` shell inside the container.
- Findings in downstream consumer images that result from misconfigured
  environment variables, missing volume mounts, or removal of documented
  defaults.
- Vulnerabilities only reachable when `DRX_DISABLE_INIT=1` is set
  (operator opt-out of the bootstrap contract).

## Hardening reminders for consumers

- Pin `BASE_IMAGE` to an immutable `X.Y.Z` tag in production.
- Run with a read-only root filesystem; mount only the writable paths
  documented in [base/README.md](base/README.md#filesystem-contract).
- Provide `DRUPAL_ADMIN_PASS` and database credentials via your
  platform's secret store, never as image build args.
- Keep JSON:API in its read-only default unless you actively need write
  mode, and audit any `post-config-import.d/` hook that toggles it.
