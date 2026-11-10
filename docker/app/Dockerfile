# Build the fit-tool Go binary (decodes Garmin .FIT files to JSON).
FROM --platform=$BUILDPLATFORM golang:1.25-alpine AS fit-tool
ARG TARGETARCH
WORKDIR /build
COPY docker/fit-tool/go.mod docker/fit-tool/go.sum ./
RUN go mod download
COPY docker/fit-tool/ ./
RUN CGO_ENABLED=0 GOOS=linux GOARCH=${TARGETARCH} go build -ldflags="-s -w" -o /out/fit-tool .

# Download Shoutrrr (sends notifications) and verify it against pinned checksums.
# When bumping the version, take the new checksums from the checksums.txt of the release.
FROM --platform=$BUILDPLATFORM alpine:3.22 AS shoutrrr
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
FROM --platform=$BUILDPLATFORM composer:2 AS app-source
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --ignore-platform-reqs
COPY . ./
RUN set -eux; \
    rm -rf docker; \
    composer dump-autoload --no-dev --optimize; \
    composer run-script compress-intl; \
    touch .env

FROM dunglas/frankenphp:1-php8.5-alpine

WORKDIR /var/www

# Run in production by default for all self-hosted instances.
ENV APP_ENV=prod
ENV APP_DEBUG=0

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

COPY docker/app/config/php.ini ${PHP_INI_DIR}/php.ini
COPY docker/app/config/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/config/snippets/ /etc/frankenphp/snippets/
COPY docker/app/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Add application
COPY --from=app-source /app /var/www

# Install Shoutrrr.
COPY --from=shoutrrr /out/shoutrrr /usr/bin/shoutrrr

# Install fit-tool (Garmin .FIT decoder).
COPY --from=fit-tool /out/fit-tool /usr/bin/fit-tool

ENTRYPOINT ["docker-entrypoint.sh"]
CMD [ "frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile" ]