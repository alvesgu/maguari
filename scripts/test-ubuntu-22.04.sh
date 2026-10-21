#!/usr/bin/env bash
# Runs the server PHPUnit suite on Ubuntu 22.04 (PHP 8.1), the minimum supported
# platform, inside a Podman container. Development happens on a newer Ubuntu and
# PHP, so this catches code or dependencies that need more than PHP 8.1.
#
# Usage: scripts/test-ubuntu-22.04.sh [--rebuild] [phpunit arguments...]
#   --rebuild  rebuild the cached test image even though nothing changed
#
# The image holds the PHP packages and the Composer dependencies installed from
# server/composer.lock. Its tag is a hash of composer.lock and of the recipe
# below, so changing either builds a new image on the next run, and every
# other run skips the install.

set -euo pipefail

repository="localhost/maguari-test-ubuntu-22.04"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# php-cli and php-sqlite3 are what the server needs to run its code (sodium is
# built in). php-xml and php-mbstring are needed by PHPUnit. composer, git and
# unzip install the locked dependencies, into /opt/maguari-vendor/vendor.
containerfile='FROM docker.io/library/ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        php-cli php-sqlite3 php-xml php-mbstring composer git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /opt/maguari-vendor
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --quiet
'

tag="$( { printf '%s' "$containerfile"; cat "$repo_root/server/composer.lock"; } | sha256sum | cut -c1-12)"
image="$repository:$tag"

if [[ "${1:-}" == "--rebuild" ]]; then
    shift
    podman image rm --force "$image" >/dev/null 2>&1 || true
fi

if ! podman image exists "$image"; then
    echo "Building $image (composer.lock or the package list changed)..."
    context="$(mktemp --directory)"
    trap 'rm -rf "$context"' EXIT
    cp "$repo_root/server/composer.json" "$repo_root/server/composer.lock" "$context/"
    printf '%s' "$containerfile" | podman build --tag "$image" --file - "$context"

    # Images built for an older composer.lock are not needed any more.
    podman images --format '{{.Repository}}:{{.Tag}}' \
        | grep --fixed-strings "$repository:" \
        | grep --invert-match --fixed-strings "$image" \
        | xargs --no-run-if-empty podman image rm >/dev/null 2>&1 || true
fi

# The repository is mounted read-only. server/ (without vendor/ and var/),
# shared/ and client/ (without var/) are copied next to each other, as in the
# repository, and the image's vendor/ is copied into server/ (a copy, not a
# link: Composer's autoloader finds the code relative to vendor/'s real path),
# so the host's vendor/ is never used. PHPUnit runs as nobody: the app never
# runs as root, and maguari-server refuses root for its database commands,
# which the CLI tests exercise.
podman run --rm \
    --volume "$repo_root:/src:ro" \
    "$image" \
    bash -euo pipefail -c '
        php --version | head -n 1
        mkdir --parents /work/server /work/shared /work/client
        tar --create --directory /src/server --exclude=./vendor --exclude=./.phpunit.result.cache --exclude=./var . \
            | tar --extract --directory /work/server
        tar --create --directory /src/shared . | tar --extract --directory /work/shared
        tar --create --directory /src/client --exclude=./var . | tar --extract --directory /work/client
        cp --archive /opt/maguari-vendor/vendor /work/server/vendor
        chown -R nobody:nogroup /work
        cd /work/server
        setpriv --reuid=nobody --regid=nogroup --clear-groups vendor/bin/phpunit "$@"
    ' bash "$@"
