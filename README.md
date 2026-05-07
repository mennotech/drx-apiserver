# drx-apiserver

Reusable, production-oriented Drupal 10 base image (`drx-drupal-base`) for
projects that use Drupal as the data, auth, and security backend behind a
decoupled frontend — plus a reference application overlay (`server/`) that
shows how to consume it.

The base image is published to the GitHub Container Registry (GHCR) at:

```
ghcr.io/mennotech/drx-drupal-base
```

| Document                              | Audience                                |
| ------------------------------------- | --------------------------------------- |
| [base/README.md](base/README.md)      | Image consumers — runtime contract, env vars, hooks, security posture. |
| [base/CHANGELOG.md](base/CHANGELOG.md)| Image consumers — runtime contract release notes (Keep a Changelog). |
| [RELEASES.md](RELEASES.md)            | Operators — published versions, tag policy, cadence, upgrade guidance. |
| [CHANGELOG.md](CHANGELOG.md)          | Contributors — repository-level changes (CI, docs, tooling). |

---

## Repository layout

```
base/      Reusable drx-drupal-base image (published to GHCR).
server/    Reference downstream overlay (separate publishable artifact).
.github/   CI/CD workflows (build, scan, multi-arch publish, SBOM, provenance).
Makefile   Local build, smoke, and compose orchestration.
```

The base image is **deliberately neutral**: no project-specific modules,
branding, config payload, or platform-specific deployment behaviour is
baked in. Downstream projects extend it through documented extension
points described in [base/README.md](base/README.md).

---

## Quick start (consumers)

Pin to an immutable tag in production:

```dockerfile
ARG BASE_IMAGE=ghcr.io/mennotech/drx-drupal-base:0.1.0
FROM ${BASE_IMAGE}

# Custom modules, config sync payload, lifecycle hooks.
COPY --chown=www-data:www-data modules/ /var/www/html/web/modules/custom/
COPY --chown=www-data:www-data config/  /var/www/html/config/
COPY --chmod=0755 hooks/                /etc/drx/hooks/
```

Run the published image directly:

```bash
docker run --rm -p 8088:80 \
  -e DRUPAL_ADMIN_PASS=change-me \
  ghcr.io/mennotech/drx-drupal-base:0.1.0
```

Full runtime environment contract (env vars, filesystem layout, hook
phases, healthcheck, security posture) is documented in
[base/README.md](base/README.md).

---

## Quick start (local development)

Local orchestration is via [Makefile](Makefile):

| Target       | Purpose                                                 |
| ------------ | ------------------------------------------------------- |
| `make base`  | Build `drx-drupal-base:dev` locally from [base/](base/).|
| `make app`   | Build the reference overlay from [server/](server/).   |
| `make up`    | Bring up the reference app via Docker Compose.          |
| `make down`  | Stop the reference app.                                 |
| `make smoke` | Boot the base image and wait for healthcheck `healthy`. |
| `make clean` | Remove build artifacts and local image tags.            |

Override `VERSION`, `BASE_IMAGE`, `APP_IMAGE`, or `SMOKE_PORT` on the
command line if needed, e.g. `make base VERSION=0.2.0-dev`.

The reference app expects `DRUPAL_ADMIN_PASS` to be set (in `.env` or the
shell). See [docker-compose.yml](docker-compose.yml) for the full set of
overridable variables.

---

## Release model

- **Cadence**: scheduled monthly patch release on the **first Tuesday** of
  each month, plus **ad hoc emergency security releases** when a relevant
  CVE is disclosed in Drupal core, PHP, Apache, or the upstream PHP base
  image. See [RELEASES.md](RELEASES.md) for the full policy.
- **Versioning**: Semantic Versioning applied to the **runtime contract**
  (env vars, hook lifecycle, on-disk layout, supported DB drivers,
  default behaviours), not internal implementation details.
- **Tags published** (per [.github/workflows/base-image.yml](.github/workflows/base-image.yml)):
  - Stable release `vX.Y.Z` &rarr; `X.Y.Z`, `X.Y`, `X`, `latest`
  - Release candidate `vX.Y.Z-rcN` &rarr; `X.Y.Z-rcN` only
  - Push to `main` &rarr; `edge`
- **Supply chain**: every published tag includes an SBOM and SLSA build
  provenance attestation. Images are scanned with Trivy
  (`CRITICAL`, `HIGH`, fail-on-fixed) before publish.

---

## Security

- **Reporting**: report suspected vulnerabilities privately via
  [GitHub Security Advisories](https://github.com/mennotech/drx-apiserver/security/advisories/new).
  Do not file public issues for embargoed CVEs.
- **Disclosure model**: maintainers acknowledge reports, coordinate a fix
  in a private advisory, ship an emergency security release, and then
  publish the advisory. See [RELEASES.md](RELEASES.md#emergency-security-releases).
- **Posture**: Apache and PHP run as `www-data`; JSON:API defaults to
  read-only; sensitive files written `0440 www-data:www-data`; HTTP
  `Server` and `WWW-Authenticate` headers are suppressed. Full details in
  [base/README.md](base/README.md#security-posture).

---

## Contributing

1. Branch from `main`.
2. Keep base-image runtime-contract changes documented in
   [base/CHANGELOG.md](base/CHANGELOG.md) under `[Unreleased]`.
3. Keep repository-level changes (CI, docs, tooling, Makefile) documented
   in [CHANGELOG.md](CHANGELOG.md) under `[Unreleased]`.
4. CI must pass: smoke boot, Trivy scan, multi-arch build.
5. For release procedure, see [RELEASES.md#release-procedure](RELEASES.md#release-procedure).

---

## License

- Repository sources: MIT (see [LICENSE](LICENSE)).
- Published `drx-drupal-base` image: contains Drupal core and is therefore
  distributed under **GPL-2.0-or-later** as reflected in the image's
  `org.opencontainers.image.licenses` label. Derived/extended images are
  likewise GPL-2.0-or-later.
