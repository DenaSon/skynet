#!/bin/sh
# Runflare laravel-nginx postStart: prepare outbound SSH for the Node setup wizard.
# Runtime-only fallback for the supplied image; no VPS or Remnawave operations.
set -eu

ASKPASS=/var/www/resources/scripts/nw01-askpass.sh

if ! command -v ssh >/dev/null 2>&1 || ! command -v ssh-keyscan >/dev/null 2>&1; then
    if [ "$(id -u)" -ne 0 ]; then
        echo "OpenSSH is missing and the startup hook is not running as root." >&2
        exit 1
    fi

    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq --no-install-recommends openssh-client
fi

chmod 755 "$ASKPASS"

command -v ssh >/dev/null
command -v ssh-keyscan >/dev/null
test -x "$ASKPASS"

echo "SSH runtime ready."
