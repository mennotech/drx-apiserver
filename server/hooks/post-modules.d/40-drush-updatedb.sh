#!/bin/sh
# Apply pending Drupal database updates after module enablement.
#
# Required for the live-restore flow used by this stack: when the base
# image restores an older SQLite snapshot from S3 (Litestream) into a
# container running newer module code, hook_update_N implementations
# must run before any service touches the affected tables. Without this
# the very first request that uses a new column / table errors out.
#
# Drush updatedb is a no-op when no updates are pending, so this is
# safe to run on every boot.

set -e

cd /var/www/html

if /var/www/html/vendor/bin/drush --root=/var/www/html/web updatedb --no-cache-clear -y >/tmp/drx-updatedb.log 2>&1; then
    echo "[drx] drush updatedb: ok"
else
    rc=$?
    echo "[drx] ERROR: drush updatedb returned $rc"
    tail -n 40 /tmp/drx-updatedb.log || true
    exit "$rc"
fi
