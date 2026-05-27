# syntax=docker/dockerfile:1.7
#
# drx-apiserver — reference implementation built on the drx-apiserver base image.
#
# This Dockerfile demonstrates the recommended downstream consumption
# pattern: take an immutable base-image tag, COPY in project-specific
# modules / config / hooks, and let the base bootstrap handle the rest.

ARG BASE_IMAGE=drx-apiserver:dev
FROM ${BASE_IMAGE}

# Project custom modules (Drupal scaffold installer paths handle the rest).
COPY --chown=www-data:www-data modules/  /var/www/html/web/modules/custom/

# Config sync payload imported on boot via drx-init.
COPY --chown=www-data:www-data config/   /var/www/html/config/sync/

# Lifecycle hooks. Each file is executable and runs in lexical order.
COPY --chmod=0755 hooks/                 /etc/drx/hooks/
