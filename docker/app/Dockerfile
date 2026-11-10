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

FROM dunglas/frankenphp:1-php8.5-alpine@sha256:bf8051e99d28fd546b0d1c1b74329f3eba0496b56bb8cd979a10ab1265e28f5c

WORKDIR /var/www

# Run in production by default for all self-hosted instances.
ENV APP_ENV=prod
ENV APP_DEBUG=0

# Caddy keeps some runtime state (instance ID, locks) in its data directory. HTTPS is terminated
# outside the container, so there are no certificates to keep and the state can live in /tmp.
ENV XDG_DATA_HOME=/tmp/caddy-data

# hadolint ignore=DL3018
RUN apk add --no-cache \
  tzdata \
  zstd

RUN set -eux; \
	install-php-extensions \
        bcmath \
        ctype \
        curl \
        dom \
        fileinfo \
        gd \
        intl \
        mbstring \
        opcache \
        pdo \
        pdo_sqlite \
        phar \
        session \
        simplexml \
        tokenizer \
        xml \
        xmlreader \
        xmlwriter \
        pcntl \
        zstd;

# The base image allows FrankenPHP to bind to ports below 1024. Dreeve listens on 8080, and the
# file capability keeps FrankenPHP from starting at all when the container drops all capabilities.
RUN setcap -r /usr/local/bin/frankenphp

COPY docker/app/config/php.ini ${PHP_INI_DIR}/php.ini
COPY docker/app/config/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/config/snippets/ /etc/frankenphp/snippets/
COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Add application
COPY --from=app-source /app /var/www

# Build the Symfony cache at build time, so the containers do not have to write it on start.
RUN bin/console cache:warmup

# Install Shoutrrr.
COPY --from=shoutrrr /out/shoutrrr /usr/bin/shoutrrr

# Install fit-tool (Garmin .FIT decoder).
COPY --from=fit-tool /out/fit-tool /usr/bin/fit-tool

# Run as an unprivileged user. The application itself stays owned by root and read-only for that
# user, it only owns the directories that are meant to be volumes. Docker copies that ownership
# into new named volumes. The UID matches the "nonroot" user of distroless and hardened images.
RUN set -eux; \
    addgroup -S -g 65532 nonroot; \
    adduser -S -D -H -h /nonexistent -s /sbin/nologin -u 65532 -G nonroot nonroot; \
    mkdir -p build storage/database storage/files watch backups; \
    chown 65532:65532 build storage/database storage/files watch backups
USER 65532:65532

ENTRYPOINT ["docker-entrypoint.sh"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]