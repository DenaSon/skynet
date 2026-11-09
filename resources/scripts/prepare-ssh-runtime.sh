#!/bin/sh
# Runflare laravel-nginx postStart: prepare outbound SSH for the Node setup wizard.
# Runtime-only fallback for the supplied image; no VPS or Remnawave operations.
set -eu

ASKPASS=/var/www/resources/scripts/nw01-askpass.sh

# Run as root in the Runflare postStart hook. The PHP queue worker runs as
# 'www' while PHP-FPM runs as 'www-data'. Repair only Laravel log files and
# make future daily rotations inherit the shared group (see logging.php).
LOG_DIR=/var/www/storage/logs
if [ "$(id -u)" -ne 0 ]; then
    echo "Laravel log preparation requires the root postStart user." >&2
    exit 1
fi
if [ ! -d "$LOG_DIR" ] || [ -L "$LOG_DIR" ]; then
    echo "Laravel log directory missing or unsafe." >&2
    exit 1
fi
id -u www >/dev/null
getent group www-data >/dev/null
chown www:www-data "$LOG_DIR"
chmod 2775 "$LOG_DIR"

for logfile in "$LOG_DIR"/laravel*.log; do
    [ -e "$logfile" ] || [ -L "$logfile" ] || continue
    if [ -L "$logfile" ] || [ ! -f "$logfile" ]; then
        echo "Unsafe Laravel log target; refusing to change ownership." >&2
        exit 1
    fi
    chown www:www-data "$logfile"
    chmod 0664 "$logfile"
done

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

# Optional RW backup identity: isolated from Node SSH setup and default-off.
/bin/sh /var/www/resources/scripts/prepare-rw-backup-ssh.sh

echo "SSH runtime ready."
