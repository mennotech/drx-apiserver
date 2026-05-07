#!/bin/bash
# Reference-app hook: enable JSON:API write mode for the decoupled
# frontend. This is opt-in per project; the base image keeps JSON:API
# read-only by default.
set -euo pipefail

drx::log "drx-apiserver: enabling JSON:API write mode"
drx::drush cset --yes jsonapi.settings read_only 0
