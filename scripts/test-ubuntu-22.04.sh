#!/usr/bin/env bash
# Runs the server PHPUnit suite on Ubuntu 22.04 (PHP 8.1), the minimum supported
# platform, inside a Podman container. Development happens on a newer Ubuntu and
# PHP, so this catches code or dependencies that need more than PHP 8.1.
#
# Usage: scripts/test-ubuntu-22.04.sh [--rebuild] [phpunit arguments...]
#   --rebuild  rebuild the cached test image (for example after changing packages)

set -euo pipefail

image="localhost/maguari-test-ubuntu-22.04"
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ "${1:-}" == "--rebuild" ]]; then
    shift
    podman image rm --force "$image" >/dev/null 2>&1 || true
fi

if ! podman image exists "$image"; then
    echo "Building $image (first run only)..."
    # php-cli and php-sqlite3 are what the server needs to run its code (sodium is
    # built in). php-xml and php-mbstring are needed by PHPUnit. composer, git and
    # unzip install the locked dependencies.
    podman build --tag "$image" --file - "$repo_root/scripts" <<'EOF'
FROM docker.io/library/ubuntu:22.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update \
    && apt-get install --yes --no-install-recommends \
        php-cli php-sqlite3 php-xml php-mbstring composer git unzip ca-certificates \
    && rm -rf /var/lib/apt/lists/*
ENV COMPOSER_ALLOW_SUPERUSER=1
EOF
fi

# The repository is mounted read-only. server/ (without vendor/ and var/) and
# shared/ are copied next to each other, as in the repository, and dependencies
# are installed from composer.lock inside the container, so the host's vendor/
# is never touched. Composer runs as root, but PHPUnit runs as
# nobody: the app never runs as root, and maguari-server refuses root for its
# database commands, which the CLI tests exercise.
podman run --rm \
    --volume "$repo_root:/src:ro" \
    --volume maguari-composer-cache:/root/.cache/composer \
    "$image" \
    bash -euo pipefail -c '
        php --version | head -n 1
        mkdir --parents /work/server /work/shared
        tar --create --directory /src/server --exclude=./vendor --exclude=./.phpunit.result.cache --exclude=./var . \
            | tar --extract --directory /work/server
        tar --create --directory /src/shared . | tar --extract --directory /work/shared
        cd /work/server
        composer install --no-interaction --no-progress --quiet
        chown -R nobody:nogroup /work
        setpriv --reuid=nobody --regid=nogroup --clear-groups vendor/bin/phpunit "$@"
    ' bash "$@"
