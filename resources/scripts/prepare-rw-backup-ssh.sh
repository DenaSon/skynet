#!/bin/sh
# Runflare postStart: materialize a dedicated RW backup SSH key from a Secret.
# Never print the secret, accept an unpinned host, or put credentials in the repo.
set -eu
umask 077

DIR=/run/secrets
KEY="$DIR/rw-backup-ssh"
KNOWN="$DIR/rw-backup-known-hosts"
PINNED=/var/www/resources/ssh/rw-backup-known-hosts
EXPECTED_KEY_FP='SHA256:UYGvU7PFhf3957DhEFQ6d0xllJO9CcOzmWbBXGFLL0A'
EXPECTED_HOST_FP='SHA256:GOhp0wGAGpHHvKMrOUi0zZyj+IqLf7kTPa5ehluDht4'
EXPECTED_HOST='194.5.193.234'

fail() { echo "RW backup SSH setup failed: $1" >&2; exit 1; }

case "${RW_BACKUP_ENABLED:-false}" in
    1|true|TRUE|yes|on) ENABLED=true ;;
    *) ENABLED=false ;;
esac

# A missing Secret must not affect the existing node-setup SSH workflow.
if [ -z "${RW_BACKUP_SSH_PRIVATE_KEY_B64:-}" ]; then
    [ "$ENABLED" = false ] || fail "backup enabled without SSH Secret"
    echo "RW backup SSH not configured (backup remains disabled)."
    exit 0
fi

[ "$(id -u)" -eq 0 ] || fail "postStart must run as root"
getent group www-data >/dev/null || fail "www-data group is missing"
for cmd in base64 ssh-keygen install mktemp; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required SSH utility missing"
done
[ -f "$PINNED" ] && [ ! -L "$PINNED" ] || fail "pinned host key is missing"
[ ! -L "$DIR" ] || fail "secret directory must not be a symlink"
[ ! -e "$DIR" ] || [ -d "$DIR" ] || fail "secret directory is not a directory"

# Only create /run/secrets if absent; do not change an existing directory's
# ownership/permissions because another service might also use it.
if [ ! -d "$DIR" ]; then
    install -d -o root -g www-data -m 0750 "$DIR"
fi

[ ! -L "$KEY" ] && [ ! -L "$KNOWN" ] || fail "secret targets must not be symlinks"

TMP_KEY="$(mktemp "$DIR/.rw-backup-key.XXXXXX")"
TMP_PUB="$(mktemp "$DIR/.rw-backup-pub.XXXXXX")"
trap 'rm -f "$TMP_KEY" "$TMP_PUB"' 0

# Decode from standard input: never pass the secret in an argv or print it.
printf '%s' "$RW_BACKUP_SSH_PRIVATE_KEY_B64" | base64 -d > "$TMP_KEY" \
    || fail "invalid encoded private key"
chmod 0600 "$TMP_KEY"
ssh-keygen -y -P '' -f "$TMP_KEY" > "$TMP_PUB" 2>/dev/null \
    || fail "key must be a valid passphrase-free SSH private key"
KEY_FP="$(ssh-keygen -lf "$TMP_PUB" | awk '{print $2}')"
[ "$KEY_FP" = "$EXPECTED_KEY_FP" ] || fail "private key fingerprint mismatch"

HOST_FP="$(ssh-keygen -lf "$PINNED" | awk '{print $2}')"
[ "$HOST_FP" = "$EXPECTED_HOST_FP" ] || fail "pinned host fingerprint mismatch"
ssh-keygen -F "$EXPECTED_HOST" -f "$PINNED" >/dev/null 2>&1 \
    || fail "expected RW host not present in pinned host file"

# Validate before replacing either deployed credential. Files remain read-only
# to the php-fpm www-data group (and their root owner).
install -o root -g www-data -m 0640 "$TMP_KEY" "$KEY"
install -o root -g www-data -m 0640 "$PINNED" "$KNOWN"

[ -r "$KEY" ] && [ -r "$KNOWN" ] || fail "installed credentials are unreadable"
echo "RW backup SSH credentials ready (fingerprints verified)."
