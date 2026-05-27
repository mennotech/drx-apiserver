# drx-apiserver — reference overlay

This directory is the **reference consumer** of `drx-drupal-base`. It
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
| [modules/custom/](modules/custom/) | Drop-in directory for project custom modules. Empty placeholder today. |
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

From the repo root:

```sh
make app          # build drx-apiserver:dev on top of drx-drupal-base:dev
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
