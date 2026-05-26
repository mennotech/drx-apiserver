#!/bin/bash
# Reference-app hook: seed a couple of example Note nodes the first time
# the site boots, so the proof-of-concept content type has something to
# show via JSON:API immediately.
#
# Idempotent: a marker state key (drx_apiserver.notes_seeded) records that
# seeding has run, so subsequent boots are no-ops even after container
# restarts or config re-imports.
set -euo pipefail

if drx::drush state:get drx_apiserver.notes_seeded --format=string 2>/dev/null \
        | grep -qx 1; then
    drx::log "drx-apiserver: notes already seeded, skipping"
    exit 0
fi

drx::log "drx-apiserver: seeding example notes"

drx::drush php:eval '
$nodes = [
  [
    "title" => "Welcome to drx-apiserver",
    "body" => "This Note was created on first boot by the 20-seed-notes.sh hook. Edit or delete it freely; the seed hook only runs once.",
    "status_val" => "published",
    "pinned" => TRUE,
    "category" => "reference",
    "tags" => ["welcome", "getting-started"],
  ],
  [
    "title" => "Try the JSON:API",
    "body" => "GET /jsonapi/node/note returns the collection. JSON:API write mode is enabled by the 10-jsonapi-write-mode.sh hook, so POST/PATCH/DELETE work too once you authenticate.",
    "status_val" => "published",
    "pinned" => FALSE,
    "category" => "project",
    "tags" => ["jsonapi", "demo"],
  ],
  [
    "title" => "Draft: ideas to follow up on",
    "body" => "Drafts demonstrate the lifecycle field. Toggle the status to published when you are ready.",
    "status_val" => "draft",
    "pinned" => FALSE,
    "category" => "idea",
    "tags" => ["todo"],
  ],
];

foreach ($nodes as $data) {
  $node = \Drupal\node\Entity\Node::create([
    "type" => "note",
    "title" => $data["title"],
    "field_body" => ["value" => $data["body"], "format" => "basic_html"],
    "field_status" => $data["status_val"],
    "field_pinned" => $data["pinned"] ? 1 : 0,
    "field_category" => $data["category"],
    "field_tags" => $data["tags"],
  ]);
  $node->save();
}

\Drupal::state()->set("drx_apiserver.notes_seeded", 1);
'

drx::log "drx-apiserver: seeded $(drx::drush sqlq 'SELECT COUNT(*) FROM node_field_data WHERE type = '\''note'\''') note(s)"
