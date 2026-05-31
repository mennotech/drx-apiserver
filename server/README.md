# drx-apiserver — reference overlay

This directory is the **reference consumer** of the `drx-apiserver` base image. It
demonstrates the recommended downstream pattern: take an immutable base
image, layer in project-specific modules / config / hooks, and let the
base bootstrap handle install and config import.

It is also a small **proof of concept**: out of the box it ships a
`note` content type and a few seeded example nodes that are
immediately reachable through JSON:API. See
[`schema/notes.yml`](schema/notes.yml) for the source of truth, and
[`config/`](config/) for the generated Drupal scaffold.

For build / run instructions see the repository [README](../README.md)
and [Makefile](../Makefile). For the runtime contract the base provides
(env vars, hook phases, paths), see [base/README.md](../base/README.md).

## Layout

| Path | Purpose |
| ---- | ------- |
| [Dockerfile](Dockerfile) | Layers this overlay on top of `${BASE_IMAGE}`. |
| [schema/](schema/) | Source-of-truth `drx-schema` YAML. Not consumed at runtime. |
| [config/](config/) | Generated Drupal config sync payload (committed). |
| [modules/contrib/](modules/contrib/) | Drop-in directory for project contrib modules. Empty placeholder today. |
| [hooks/post-install.d/](hooks/post-install.d/) | Hooks that run after Drupal install and before config import. |
| [hooks/post-config-import.d/](hooks/post-config-import.d/) | Hooks that run after the base imports `config/`. |
| [hooks/post-modules.d/](hooks/post-modules.d/) | Hooks that run after module enablement. |

### Hooks shipped here

- `post-install.d/05-enable-views-and-theme.sh` — enables field-type
  provider modules (`datetime`, `options`, `text`, `views`) and the Claro
  admin theme before config import, so config payloads can resolve all
  module and field-type dependencies.
- `post-modules.d/10-enable-navigation.sh` — enables the Navigation module.
- `post-modules.d/20-enable-views-ui.sh` — enables the Views UI admin
  module so editors can manage views from `/admin/structure/views`.
- `post-config-import.d/10-jsonapi-write-mode.sh` — flips JSON:API to
  read/write. The base image keeps JSON:API read-only by default; this
  is the documented per-project opt-in.
- `post-config-import.d/20-seed-notes.sh` — creates a few example
  `note` nodes on first boot. Guarded by the `drx_apiserver.notes_seeded`
  state key, so it runs exactly once per site.
- `post-config-import.d/30-set-front-page.sh` — sets the site front
  page to `/notes` (the Notes view path).

## The content model: `note`

A single content type with these fields (in addition to the built-in
node title):

| Field | Type | Notes |
| ----- | ---- | ----- |
| `field_body` | long text | Required. |
| `field_status` | list (string) | `draft` / `published` / `archived`. Defaults to `draft`. |
| `field_pinned` | boolean | Optional. |
| `field_due_date` | date | Optional. |
| `field_note_tags` | text, multi-value | Free-form tags. |
| `field_category` | list (string) | `personal`, `work`, `project`, `idea`, `reference`, `other`. |

Once the container is healthy, the seed hook produces three notes
visible at `GET /jsonapi/node/note`.

## Regenerating `config/` from the schema

The Drupal YAML under [config/](config/) is **generated** from
[schema/notes.yml](schema/notes.yml) by
[`drx-schema`](https://github.com/mennotech/drx-schema). We commit the
output rather than running the generator at image-build time, so:

- the diff is reviewable in PRs,
- the runtime image does not need PowerShell,
- consumers can fork this repo without learning the generator first.

When you change the schema, regenerate the config and commit both
together.

### Requirements

- PowerShell 7 or newer (`pwsh`).
- A local checkout of [`drx-schema`](https://github.com/mennotech/drx-schema).

### Steps

From the `drx-schema` checkout:

```powershell
Import-Module ./DrX-Schema.psd1 -Force

Export-DrXDrupalScaffoldConfig `
    -SchemaPath /path/to/drx-apiserver/server/schema `
    -OutputDir  /path/to/drx-apiserver/server/config
```

The command writes node type, field storage, field instance, form
display, and view display YAML files. Before regenerating, clear any
stale generated files (leave `.gitkeep` alone):

```sh
find server/config -type f ! -name .gitkeep -delete
```

Then run the export, review the diff, and commit `schema/` and
`config/` in the same change.

### Schema authoring notes

- Use plain or double-quoted string scalars for `description` fields.
  Folded scalars (`>-`) currently round-trip into the generated YAML as
  the literal `'>-'`; this is a `drx-schema` limitation, not a Drupal
  one.
- Field `type` values used here: `text`, `textarea`, `select`, `date`,
  `boolean`. `cardinality: -1` makes a field multi-value.
- `kind: supporting_record` is fine for plain content bundles; the kind
  does not change the generated Drupal artefacts.

## Verifying changes locally

From the repo root (supported path; compose is invoked by `make`):

```sh
make build        # build drx-apiserver:dev on top of drx-apiserver:dev
make up           # docker compose up -d
curl -s http://localhost:8088/jsonapi/node/note | jq '.data[].attributes.title'
make down
```

Or run the base smoke check on its own:

```sh
make smoke
```

Note: `make smoke` boots the **base** image, not this overlay, so it
will not exercise the seeded notes. Use `make up` for that.

`make smoke-stack` boots the full overlay against MinIO and asserts the
seeded `note` records are reachable over JSON:API. It is the
recommended end-to-end check before pushing changes that touch
[server/](.) or the bootstrap pipeline.

## Shared S3 storage (MinIO sidecar)

The overlay treats S3 as the source of truth for both user content and
the Litestream SQLite replica. The local
[`docker-compose.yml`](docker-compose.yml) boots a MinIO sidecar plus a
one-shot bucket initialiser (`minio-init`) that creates the bucket and
enables bucket versioning, then flips the public prefix to
anonymous-read, mirroring the production
bucket-policy posture documented in
[base/README.md → Shared S3 storage](../base/README.md#shared-s3-storage-mandatory-by-default).

The reference compose stack persists only the MinIO data volume. Drupal's
local filesystem and SQLite path are intentionally ephemeral, so restart/
recreate flows exercise Litestream restore behavior instead of relying on
host-mounted Drupal volumes.

[`.env.example`](../.env.example) seeds the `DRX_S3_*` variables to
point at the in-stack MinIO; the base image bridges them into
Litestream's native env vars on its own, so a single credential pair
covers backup/restore and Drupal's file backend.

| Prefix used by the overlay     | Purpose                                                |
| ------------------------------ | ------------------------------------------------------ |
| `drx-data-local/litestream/`   | Litestream SQLite replica.                             |
| `drx-data-local/private/`      | Drupal **private://** uploads (Drupal-gated).          |
| `drx-data-local/public/`       | Drupal **public://** uploads (anonymous read on this prefix only). |
| `drx-data-local/journal/v1/`   | Immutable file-change journal events (`drx_s3_journal`). |

For CI smoke tests that should skip S3 entirely, set
`DRX_S3_REQUIRED=0` (the Makefile already does this for `make smoke`
and `make up-base`).

## Litestream replication: admin UI and dev restore

The reference overlay enables the first-party `drx_litestream` module
that ships with the base image (see
[`base/modules/drx_litestream/`](../base/modules/drx_litestream/)),
which surfaces the base image's litestream integration to operators
without requiring shell access to the container. Local credentials and
endpoint come from the shared MinIO sidecar documented above, so the
dashboard shows live data with no extra configuration.

### Health dashboard

Once the stack is up (`make up`), visit
[http://localhost:8088/admin/config/drx/litestream](http://localhost:8088/admin/config/drx/litestream).
The page reports:

- Whether the `litestream replicate` daemon is running inside the
  container.
- Resolved config and SQLite paths and the replica URL in use.
- Local TXID and WAL size from `litestream status`.
- Latest TXID present on the replica (read via `litestream ltx`).
- The SQLite file's last-modified timestamp.

If the shared `DRX_S3_*` contract is not active (`DRX_S3_REQUIRED=0`
or missing bucket/credentials), the page shows a notice that
replication is disabled.

### Capturing and exporting point-in-time markers

From the dashboard, **Capture point-in-time marker** opens a form that
stamps the current replica TXID into a `drx_litestream_marker` row with
a human label and optional notes. The markers table at
`/admin/config/drx/litestream/markers` lists every capture and offers a
JSON export per row. The exported document includes:

- The replica URL, captured TXID, and capture timestamp.
- A `dev_restore_hint.shell` one-liner for a direct
  `litestream restore -txid …` invocation.
- A `dev_restore_hint.docker_env` block that maps directly onto the
  base image's runtime contract (`DRX_LITESTREAM_RESTORE_ON_BOOT=always`
  plus `DRX_LITESTREAM_RESTORE_TXID`), so a fresh container can
  reproduce that exact state.

### Restoring a marker on a dev machine

Two equivalent paths, both using values from the exported JSON:

1. Pull a single SQLite file with the `litestream` CLI:

   ```sh
   litestream restore \
     -txid <TXID> \
     -o ./dev.sqlite \
     <REPLICA_URL>
   ```

2. Boot a fresh `drx-apiserver` container pinned to the marker:

   ```sh
   docker run --rm \
     -e DRX_S3_BUCKET=<BUCKET> \
     -e DRX_S3_ENDPOINT=<S3_ENDPOINT> \
     -e DRX_S3_REGION=<S3_REGION> \
     -e DRX_S3_ACCESS_KEY_ID=<key> \
     -e DRX_S3_SECRET_ACCESS_KEY=<secret> \
     -e DRX_LITESTREAM_RESTORE_ON_BOOT=always \
     -e DRX_LITESTREAM_RESTORE_TXID=<TXID> \
     ghcr.io/mennotech/drx-apiserver:<tag>
   ```

   The bootstrap clears the local DB (because the policy is `always`),
   then runs `litestream restore -txid <TXID>` before Drupal install
   detection, so the site comes up at exactly the captured state.

### Automated drills

Two `make` targets exercise the round-trip end-to-end against MinIO:

- `make dr-drill` — writes a DB marker and a public file marker,
  removes and recreates the backend container (ephemeral local layer),
  and asserts both markers survive restore from replica/S3.
- See [base image runtime contract](../base/README.md#litestream-backup--restore-sqlite-only)
  for the full list of `DRX_LITESTREAM_*` env vars driving these flows.

