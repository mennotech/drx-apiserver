#!/bin/bash
# Container healthcheck. Validates Apache responsiveness and Drupal bootstrap.
# Returns 0 if healthy, non-zero otherwise.
set -eu

PORT="${DRX_HEALTHCHECK_PORT:-80}"
PATH_URL="${DRX_HEALTHCHECK_PATH:-/user/login_status?_format=json}"

curl --fail --silent --show-error --max-time 5 \
    "http://127.0.0.1:${PORT}${PATH_URL}" >/dev/null
