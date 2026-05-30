# syntax=docker/dockerfile:1.7
#
# drx-apiserver
# Reusable, production-oriented Drupal 10 base image.
#
# Downstream images extend this base by:
#   - copying base modules into  /var/www/html/web/modules/base/
#   - copying configuration into   /var/www/html/config/sync/
#   - dropping init hooks into     /etc/drx/hooks/<phase>.d/
#   - setting environment variables documented in README.md
#
# This image deliberately does NOT bake in any one downstream project's
# modules, branding, or platform-specific deployment behavior.

ARG PHP_BASE_IMAGE=php:8.3.31-apache-bookworm@sha256:7a981a5d14208d35dc4b43c4c0f60e24a4fec9c80509cfe8046ed6598d250793

# Pinned litestream release. Bump in concert with base/CHANGELOG.md.
ARG LITESTREAM_VERSION=0.5.11
ARG LITESTREAM_GRPC_VERSION=1.79.3
ARG LITESTREAM_GOOGLE_API_VERSION=0.253.0
ARG LITESTREAM_GO_JOSE_VERSION=4.1.4
ARG LITESTREAM_OTEL_VERSION=1.43.0

# -----------------------------------------------------------------------------
# Stage 1a: litestream-fetch — pull a pinned, architecture-correct litestream
# binary into a tiny scratch-friendly layer. Kept separate from the PHP
# builder so that bumping LITESTREAM_VERSION does not bust the (much larger)
# composer install cache.
# -----------------------------------------------------------------------------
FROM golang:1.26-bookworm AS litestream-fetch

ARG LITESTREAM_VERSION
ARG LITESTREAM_GRPC_VERSION
ARG LITESTREAM_GOOGLE_API_VERSION
ARG LITESTREAM_GO_JOSE_VERSION
ARG LITESTREAM_OTEL_VERSION
ARG TARGETARCH

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends ca-certificates git; \
    rm -rf /var/lib/apt/lists/*; \
    case "${TARGETARCH:-amd64}" in \
        amd64|arm64) ;; \
        *) echo "Unsupported TARGETARCH=${TARGETARCH}"; exit 1 ;; \
    esac; \
    git clone --depth 1 --branch "v${LITESTREAM_VERSION}" https://github.com/benbjohnson/litestream.git /tmp/litestream; \
    cd /tmp/litestream; \
    go mod edit -require="google.golang.org/grpc@v${LITESTREAM_GRPC_VERSION}"; \
    go mod edit -require="google.golang.org/api@v${LITESTREAM_GOOGLE_API_VERSION}"; \
    go mod edit -require="github.com/go-jose/go-jose/v4@v${LITESTREAM_GO_JOSE_VERSION}"; \
    go mod edit -require="go.opentelemetry.io/otel@v${LITESTREAM_OTEL_VERSION}"; \
    go mod edit -require="go.opentelemetry.io/otel/sdk@v${LITESTREAM_OTEL_VERSION}"; \
    CGO_ENABLED=0 GOOS=linux GOARCH="${TARGETARCH:-amd64}" \
      GOWORK=off GOFLAGS="-mod=mod -buildvcs=false" \
      go build -trimpath -ldflags "-s -w -X main.version=v${LITESTREAM_VERSION}" -o /usr/local/bin/litestream ./cmd/litestream; \
    chmod 0755 /usr/local/bin/litestream; \
    rm -rf /root/.cache/go-build /go/pkg/mod; \
    /usr/local/bin/litestream version

# -----------------------------------------------------------------------------
# Stage 1: builder — installs Composer dependencies into a writable layer.
# -----------------------------------------------------------------------------
FROM ${PHP_BASE_IMAGE} AS builder

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache \
    COMPOSER_ALLOW_SUPERUSER=1

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        zip \
        libpng-dev \
        libjpeg-dev \
        libwebp-dev \
        libfreetype6-dev \
        libicu-dev \
        libxml2-dev \
        libzip-dev \
        libsqlite3-dev \
        libpq-dev; \
    rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        gd \
        intl \
        opcache \
        pdo_mysql \
        pdo_pgsql \
        pdo_sqlite \
        xml \
        zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./

RUN set -eux; \
    install -d -o www-data -g www-data -m 0770 /tmp/composer /tmp/composer/cache; \
    chown -R www-data:www-data /var/www/html

USER www-data
RUN umask 0027; \
    composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --optimize-autoloader \
        --prefer-dist
USER root

# -----------------------------------------------------------------------------
# Stage 2: runtime — minimal Apache + PHP, hardened defaults.
# -----------------------------------------------------------------------------
FROM ${PHP_BASE_IMAGE} AS runtime

ARG DRX_BASE_VERSION=0.0.0-dev
ARG DRX_BASE_VCS_REF=unknown
ARG DRX_BASE_BUILD_DATE=unknown
ARG DRX_APT_SECURITY_OVERRIDES="libgnutls30=3.7.9-2+deb12u7 libgssapi-krb5-2=1.20.1-2+deb12u5 libk5crypto3=1.20.1-2+deb12u5 libkrb5-3=1.20.1-2+deb12u5 libkrb5support0=1.20.1-2+deb12u5"

LABEL org.opencontainers.image.title="drx-apiserver" \
      org.opencontainers.image.description="Reusable Drupal 10 base image for decoupled API backends." \
      org.opencontainers.image.source="https://github.com/mennotech/drx-apiserver" \
      org.opencontainers.image.licenses="GPL-2.0-or-later" \
      org.opencontainers.image.version="${DRX_BASE_VERSION}" \
      org.opencontainers.image.revision="${DRX_BASE_VCS_REF}" \
      org.opencontainers.image.created="${DRX_BASE_BUILD_DATE}"

ENV DEBIAN_FRONTEND=noninteractive \
    APACHE_DOCUMENT_ROOT=/var/www/html/web \
    DRX_BASE_VERSION=${DRX_BASE_VERSION} \
    DRX_HOOKS_DIR=/etc/drx/hooks \
    DRX_LIB_DIR=/usr/local/lib/drx

# Runtime system packages only. Compiled PHP extensions and their shared
# libraries are copied from the builder stage; we install only the runtime
# .so libraries that those extensions link against.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        sqlite3 \
        sudo \
        libpng16-16 \
        libjpeg62-turbo \
        libwebp7 \
        libfreetype6 \
        libicu72 \
        libxml2 \
        libzip4 \
        libpq5 \
        tini; \
    # Optional, temporary exact-version overrides for already-installed
    # packages (e.g. "openssl=3.0.20-1~deb12u1 apache2=2.4.67-1~deb12u2").
    # This enables targeted CVE patching without a blanket dist-upgrade.
    if [ -n "${DRX_APT_SECURITY_OVERRIDES}" ]; then \
        for spec in ${DRX_APT_SECURITY_OVERRIDES}; do \
            case "${spec}" in \
                *=*) ;; \
                *) echo "DRX_APT_SECURITY_OVERRIDES entries must use name=version (got: ${spec})"; exit 1 ;; \
            esac; \
        done; \
        apt-get install -y --no-install-recommends --only-upgrade ${DRX_APT_SECURITY_OVERRIDES}; \
    fi; \
    # linux-libc-dev ships in the upstream PHP base image as a build-time
    # dependency; it carries a long tail of kernel CVEs that never apply
    # at runtime (no kernel runs inside the container). Purge it.
    apt-get purge -y --auto-remove linux-libc-dev || true; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# Bring compiled PHP extensions and their config in from the builder stage.
COPY --from=builder /usr/local/lib/php/extensions/        /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/            /usr/local/etc/php/conf.d/

# Litestream binary for SQLite backup/restore (feature is derived from
# the shared DRX_S3_* contract at bootstrap time).
COPY --from=litestream-fetch /usr/local/bin/litestream    /usr/local/bin/litestream

# Apache configuration: Drupal docroot, required modules, hardening.
RUN set -eux; \
    sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf; \
    a2enmod rewrite headers expires; \
    # Suppress AH00558 ServerName warning; runtime can override.
    echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf; \
    a2enconf servername; \
    # Hide server banner.
    { \
        echo 'ServerTokens Prod'; \
        echo 'ServerSignature Off'; \
        echo 'TraceEnable Off'; \
    } > /etc/apache2/conf-available/security-hardening.conf; \
    a2enconf security-hardening; \
    # Prevent browser-native auth prompts on 401 so SPA frontends control UX.
    printf '%s\n' \
        '<IfModule mod_headers.c>' \
        '  Header always unset WWW-Authenticate' \
        '</IfModule>' \
        > /etc/apache2/conf-available/strip-www-authenticate.conf; \
    a2enconf strip-www-authenticate

# PHP runtime configuration tuned for Drupal API workloads. Values are
# fixed in the image; downstream projects override by dropping additional
# .ini files into /usr/local/etc/php/conf.d/ via their Dockerfile or hooks.
RUN set -eux; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"; \
    { \
        echo 'memory_limit = 256M'; \
        echo 'upload_max_filesize = 32M'; \
        echo 'post_max_size = 32M'; \
        echo 'max_execution_time = 300'; \
        echo 'expose_php = Off'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.use_strict_mode = 1'; \
    } > "$PHP_INI_DIR/conf.d/99-drx-runtime.ini"; \
    { \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.interned_strings_buffer=16'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > "$PHP_INI_DIR/conf.d/99-drx-opcache.ini"

# Bring in the application files installed by the builder stage.
WORKDIR /var/www/html
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html
RUN ln -s /var/www/html/vendor/bin/drush /usr/local/bin/drush

# Drupal-specific writable directories (outside webroot for the database).
RUN set -eux; \
    install -d -o www-data -g www-data -m 0770 /var/drupal-db; \
    install -d -o www-data -g www-data -m 0770 /var/www/html/web/sites/default/files; \
    install -d -o root -g root -m 0755 \
        ${DRX_LIB_DIR} \
        ${DRX_HOOKS_DIR} \
        ${DRX_HOOKS_DIR}/pre-bootstrap.d \
        ${DRX_HOOKS_DIR}/post-install.d \
        ${DRX_HOOKS_DIR}/post-config-import.d \
        ${DRX_HOOKS_DIR}/post-modules.d \
        ${DRX_HOOKS_DIR}/post-bootstrap.d

# Bootstrap orchestrator and decomposed modules.
COPY lib/        ${DRX_LIB_DIR}/
COPY init.sh    /usr/local/bin/drx-init
RUN chmod 0755 /usr/local/bin/drx-init ${DRX_LIB_DIR}/*.sh

# Healthcheck script: lightweight, no curl-bashing in CMD.
COPY healthcheck.sh /usr/local/bin/drx-healthcheck
RUN chmod 0755 /usr/local/bin/drx-healthcheck

EXPOSE 80

# tini gives us proper PID 1 signal handling for clean shutdowns.
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/drx-init"]
CMD ["apache2-foreground"]

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=5 \
    CMD ["/usr/local/bin/drx-healthcheck"]
