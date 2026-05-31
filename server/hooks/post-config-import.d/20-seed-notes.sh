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
// Pick a text format that actually exists. plain_text is shipped by the
// filter module and is the safe universal default; fall back to whatever
// the first enabled format is so this seed survives profiles that strip
// it (e.g. heavily customised installs).
$format_storage = \Drupal::entityTypeManager()->getStorage("filter_format");
$format = $format_storage->load("plain_text");
if (!$format || !$format->status()) {
  $candidates = $format_storage->loadByProperties(["status" => TRUE]);
  $format = $candidates ? reset($candidates) : NULL;
}
$format_id = $format ? $format->id() : "plain_text";

$sample_pdf = base64_decode("JVBERi0xLjEKMSAwIG9iago8PCAvVHlwZSAvQ2F0YWxvZyAvUGFnZXMgMiAwIFIgPj4KZW5kb2JqCjIgMCBvYmoKPDwgL1R5cGUgL1BhZ2VzIC9LaWRzIFszIDAgUl0gL0NvdW50IDEgPj4KZW5kb2JqCjMgMCBvYmoKPDwgL1R5cGUgL1BhZ2UgL1BhcmVudCAyIDAgUiAvTWVkaWFCb3ggWzAgMCAzMDAgMTQ0XSAvQ29udGVudHMgNCAwIFIgPj4KZW5kb2JqCjQgMCBvYmoKPDwgL0xlbmd0aCA0NCA+PgpzdHJlYW0KQlQgL0YxIDI0IFRmIDcyIDcyIFRkIChEclggTm90ZSBBdHRhY2htZW50KSBUaiBFVAplbmRzdHJlYW0KZW5kb2JqCnhyZWYKMCA1CjAwMDAwMDAwMDAgNjU1MzUgZiAKMDAwMDAwMDAxMCAwMDAwMCBuIAowMDAwMDAwMDYwIDAwMDAwIG4gCjAwMDAwMDAxMTcgMDAwMDAgbiAKMDAwMDAwMDIxMCAwMDAwMCBuIAp0cmFpbGVyCjw8IC9Sb290IDEgMCBSIC9TaXplIDUgPj4Kc3RhcnR4cmVmCjMwMgolJUVPRgo=", TRUE);
if ($sample_pdf === FALSE) {
  throw new \RuntimeException("Failed to decode embedded sample PDF attachment");
}

$nodes = [
  [
    "title" => "Welcome to drx-apiserver",
    "body" => "This Note was created on first boot by the 20-seed-notes.sh hook. Edit or delete it freely; the seed hook only runs once.",
    "status_val" => "published",
    "pinned" => TRUE,
    "category" => "reference",
    "tags" => ["welcome", "getting-started"],
    "attachment" => [
      "name" => "welcome-note.txt",
      "body" => "Welcome to drx-apiserver notes. This text file is seeded as an example attachment.",
      "description" => "Example text attachment",
    ],
  ],
  [
    "title" => "Try the JSON:API",
    "body" => "GET /jsonapi/node/note returns the collection. JSON:API write mode is enabled by the 10-jsonapi-write-mode.sh hook, so POST/PATCH/DELETE work too once you authenticate.",
    "status_val" => "published",
    "pinned" => FALSE,
    "category" => "project",
    "tags" => ["jsonapi", "demo"],
    "attachment" => [
      "name" => "api-quickstart.pdf",
      "body" => $sample_pdf,
      "description" => "Example PDF attachment",
    ],
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
  $attachments = [];
  if (!empty($data["attachment"])) {
    $subdir = "note-attachments/" . gmdate("Y-m");
    $dir_uri = "public://" . $subdir;
    \Drupal::service("file_system")->prepareDirectory(
      $dir_uri,
      \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $repo = \Drupal::service("file.repository");
    $raw_name = (string) ($data["attachment"]["name"] ?? "attachment.bin");
    $safe_name = preg_replace("/[^A-Za-z0-9._-]+/", "-", $raw_name) ?: "attachment.bin";
    $uri = $dir_uri . "/" . $safe_name;
    $file = $repo->writeData(
      (string) ($data["attachment"]["body"] ?? ""),
      $uri,
      \Drupal\Core\File\FileExists::Replace,
    );
    $file->setPermanent();
    $file->save();
    $attachments[] = [
      "target_id" => (int) $file->id(),
      "description" => (string) ($data["attachment"]["description"] ?? ""),
    ];
  }

  $node = \Drupal\node\Entity\Node::create([
    "type" => "note",
    "title" => $data["title"],
    "field_body" => ["value" => $data["body"], "format" => $format_id],
    "field_attachments" => $attachments,
    "field_status" => $data["status_val"],
    "field_pinned" => $data["pinned"] ? 1 : 0,
    "field_category" => $data["category"],
    "field_note_tags" => $data["tags"],
  ]);
  $node->save();
}

\Drupal::state()->set("drx_apiserver.notes_seeded", 1);
'

drx::log "drx-apiserver: seeded $(drx::drush sqlq 'SELECT COUNT(*) FROM node_field_data WHERE type = '\''note'\''') note(s)"
