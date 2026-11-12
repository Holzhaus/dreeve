# Build the fit-tool Go binary (decodes Garmin .FIT files to JSON).
FROM --platform=$BUILDPLATFORM golang:1.27-alpine@sha256:eb61c3873c97416fff0116553fff65d67a3549f72e11d9758849a689cdd8b523 AS fit-tool
ARG TARGETARCH
WORKDIR /build
COPY docker/fit-tool/go.mod docker/fit-tool/go.sum ./
RUN go mod download
COPY docker/fit-tool/ ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH=${TARGETARCH} go build -ldflags="-s -w" -o /out/fit-tool .

# Download Shoutrrr (sends notifications) and verify it against pinned checksums.
# When bumping the version, take the new checksums from the checksums.txt of the release.
FROM --platform=$BUILDPLATFORM alpine:3.22@sha256:49340b346798875b5592e5d420241f2f9cfba5f47e62ebb1446a1b34734e898f AS shoutrrr
ARG TARGETARCH
ARG SHOUTRRR_VERSION="0.23.0"
ARG SHOUTRRR_SHA256_AMD64="c937841f1b05ff1872f55a72d297c36b18e8b3dc6217a99da9a0cdefa3eea6ec"
ARG SHOUTRRR_SHA256_ARM64="ac106dcb5a5b9328b47279485c6c1c0a9866aa110e5bab81158495c7f4383096"
RUN set -eux; \
    case "${TARGETARCH}" in \
      amd64) arch="amd64"; sha256="${SHOUTRRR_SHA256_AMD64}" ;; \
      arm64) arch="arm64v8"; sha256="${SHOUTRRR_SHA256_ARM64}" ;; \
      *) echo "Unsupported architecture: ${TARGETARCH}" >&2; exit 1 ;; \
    esac; \
    wget -q -O /tmp/shoutrrr.tar.gz "https://github.com/nicholas-fedor/shoutrrr/releases/download/v${SHOUTRRR_VERSION}/shoutrrr_linux_${arch}_${SHOUTRRR_VERSION}.tar.gz"; \
    echo "${sha256}  /tmp/shoutrrr.tar.gz" | sha256sum -c -; \
    mkdir -p /out; \
    tar -xzf /tmp/shoutrrr.tar.gz -C /out shoutrrr

# Install the Composer dependencies and assemble the application source. Neither depends on
# the target architecture, so this stage runs on the build platform. The PHP extensions are
# only installed in the runtime image, hence the ignored platform requirements.
FROM --platform=$BUILDPLATFORM composer:2@sha256:ca11f643d7791ebdd90a34fc20caf11380d434007ae539a9ea75cd9212e13dca AS app-source
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --ignore-platform-reqs
COPY . ./
RUN set -eux; \
    rm -rf docker; \
    composer dump-autoload --no-dev --optimize; \
    composer run-script compress-intl; \
    touch .env

# The runtime image is based on Docker Hardened Images: dhi.io/static has no shell and no package
# manager, only CA certificates and time zones. FrankenPHP and PHP come from the DHI packages.
FROM dhi.io/static:20261020-alpine3.24@sha256:4767f889704f46bbf9b83bd0225e0e868ae0cb45ddc5627350ecf1449a019721 AS static

# Build the PHP extensions Dreeve needs that the PHP of DHI's FrankenPHP package does not include,
# from the PHP sources of the very same version.
FROM dhi.io/alpine-base:3.24-alpine3.24-dev@sha256:266554e75cb00f88effe4048ba78b6f628d6a5ca67ac9e9727ae37a4a1299ce5 AS php-extensions
ARG PHP_VERSION="8.5.12"
ARG PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"
ARG PHP_ZSTD_VERSION="0.18.0"
ARG PHP_ZSTD_SHA256="223d0f77eb5a5e73cf5e7a0652dd8fde7ffdcc843e7f30eeb3998283dec847b9"
# hadolint ignore=DL3018
RUN set -eux; \
    apk add --no-cache frankenphp-8.5 frankenphp-8.5-dev autoconf curl g++ gcc icu-dev libpng-dev make pkgconf xz zlib-dev zstd-dev; \
    if [ "$(php-config --version)" != "${PHP_VERSION}" ]; then \
      echo "FrankenPHP now ships PHP $(php-config --version), update PHP_VERSION and PHP_SHA256." >&2; \
      exit 1; \
    fi; \
    curl -fsSL -o /tmp/php.tar.xz "https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz"; \
    echo "${PHP_SHA256}  /tmp/php.tar.xz" | sha256sum -c -; \
    curl -fsSL -o /tmp/zstd.tgz "https://pecl.php.net/get/zstd-${PHP_ZSTD_VERSION}.tgz"; \
    echo "${PHP_ZSTD_SHA256}  /tmp/zstd.tgz" | sha256sum -c -; \
    mkdir -p /tmp/php /tmp/zstd; \
    tar -xJf /tmp/php.tar.xz -C /tmp/php --strip-components=1; \
    tar -xzf /tmp/zstd.tgz -C /tmp/zstd --strip-components=1; \
    for extension in /tmp/php/ext/bcmath /tmp/php/ext/gd /tmp/php/ext/intl /tmp/php/ext/pcntl /tmp/zstd; do \
      cd "${extension}"; \
      phpize; \
      if [ "${extension}" = /tmp/zstd ]; then ./configure --with-libzstd; else ./configure; fi; \
      make -j"$(nproc)"; \
      make install; \
    done

# Assemble the root filesystem of the runtime image: dhi.io/static plus FrankenPHP, the libraries
# of the extensions and env, so console commands can keep their "#!/usr/bin/env php" shebang.
FROM php-extensions AS runtime-rootfs
COPY --from=static / /rootfs
# hadolint ignore=DL3018
RUN set -eux; \
    apk add --root /rootfs --no-cache --scripts=no frankenphp-8.5 coreutils-env icu-libs icu-data-full libpng zstd-libs; \
    # The packages pull in busybox for the install scripts of ca-certificates, which never ran. \
    # Remove busybox and the shell together with their entries in the package database, so \
    # vulnerability scanners keep an accurate view of the image. \
    shell_packages="busybox busybox-binsh ssl_client"; \
    for file in $(apk --root /rootfs info -q -L ${shell_packages}); do rm -f "/rootfs/${file}"; done; \
    awk -v drop=" ${shell_packages} " 'BEGIN { RS = ""; ORS = "\n\n" } { split($0, lines, "\n"); for (i in lines) if (lines[i] ~ /^P:/ && index(drop, " " substr(lines[i], 3) " ")) next; print }' \
      /rootfs/lib/apk/db/installed > /tmp/installed; \
    mv /tmp/installed /rootfs/lib/apk/db/installed; \
    extension_dir="$(php-config --extension-dir)"; \
    for extension in bcmath gd intl pcntl zstd; do \
      cp "${extension_dir}/${extension}.so" "/rootfs${extension_dir}/"; \
      echo "extension=${extension}" > "/rootfs/etc/php/conf.d/docker-php-ext-${extension}.ini"; \
    done; \
    # The unprivileged user only owns the directories that are meant to be volumes. Docker copies \
    # that ownership into new named volumes. \
    mkdir -p /rootfs/var/www/build /rootfs/var/www/storage/database /rootfs/var/www/storage/files /rootfs/var/www/watch /rootfs/var/www/backups; \
    chown 65532:65532 /rootfs/var/www/build /rootfs/var/www/storage/database /rootfs/var/www/storage/files /rootfs/var/www/watch /rootfs/var/www/backups

FROM static
COPY --from=runtime-rootfs /rootfs /

WORKDIR /var/www

# Run in production by default for all self-hosted instances.
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Caddy keeps some runtime state (instance ID, locks) in its data directory. HTTPS is terminated
# outside the container, so there are no certificates to keep and the state can live in /tmp.
ENV XDG_DATA_HOME=/tmp/caddy-data

# PHP reads its configuration from /etc/php. Earlier images used /usr/local/etc/php, keep reading
# additional ini files from there, so setups that mount their own keep working.
ENV PHP_INI_SCAN_DIR=/etc/php/conf.d:/usr/local/etc/php/conf.d

COPY docker/app/config/php.ini /etc/php/php.ini
COPY docker/app/config/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/config/snippets/ /etc/frankenphp/snippets/
COPY --chmod=755 docker/app/docker-entrypoint docker/app/docker-healthcheck /usr/local/bin/

# Add application
COPY --from=app-source /app /var/www

# Build the Symfony cache at build time, so the containers do not have to write it on start. As
# root, so the unprivileged user cannot change it. There is no shell, hence the exec form.
USER 0:0
RUN ["php", "bin/console", "cache:warmup"]

# Install Shoutrrr.
COPY --from=shoutrrr /out/shoutrrr /usr/bin/shoutrrr

# Install fit-tool (Garmin .FIT decoder).
COPY --from=fit-tool /out/fit-tool /usr/bin/fit-tool

# Run as the unprivileged "nonroot" user of the base image. The application stays owned by root.
USER 65532:65532

HEALTHCHECK --start-period=60s CMD ["docker-healthcheck"]

ENTRYPOINT ["docker-entrypoint"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]
