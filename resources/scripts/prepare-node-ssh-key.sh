#!/bin/sh
# NW-SSH-KEY-01: Runflare postStart materializes the dedicated Node SSH identity.
# Runtime preparation only: no SSH connection, VPS mutation or installer change.
set -eu
umask 077

WEB_DIR=/run/secrets
WEB_KEY="$WEB_DIR/denagate-node-ssh"
WORKER_DIR=/run/denagate-node-worker
WORKER_KEY="$WORKER_DIR/identity"
EXPECTED_KEY_FP='SHA256:y/drdp9P+7FscuHjq1ckcCjUjZhnuS+Chl9HJfYehZQ'

fail() { echo "Node SSH key preparation failed: $1" >&2; exit 1; }

# When unconfigured, this optional integration must leave existing SSH alone.
if [ -z "\${NODE_SSH_PRIVATE_KEY_B64:-}" ]; then
    echo "Node SSH key not configured (password SSH unchanged)."
    exit 0
fi

[ "$(id -u)" -eq 0 ] || fail "root postStart required"
id -u www >/dev/null 2>&1 || fail "queue worker user missing"
id -u www-data >/dev/null 2>&1 || fail "PHP worker user missing"
getent group www-data >/dev/null 2>&1 || fail "PHP worker group missing"
WORKER_GROUP="$(id -gn www)"
[ -n "$WORKER_GROUP" ] || fail "queue worker group missing"
[ "\${#NODE_SSH_PRIVATE_KEY_B64}" -le 16384 ] || fail "encoded key too large"
for cmd in base64 ssh-keygen install mktemp awk; do
    command -v "$cmd" >/dev/null 2>&1 || fail "required SSH utility missing"
done

# Isolate two consumers with separate root-owned, group-readable copies.
# Do not relax permissions on /run/secrets: RW backup also uses it.
[ ! -L "$WEB_DIR" ] && { [ ! -e "$WEB_DIR" ] || [ -d "$WEB_DIR" ]; } \
    || fail "unsafe secret directory"
[ ! -L "$WORKER_DIR" ] && { [ ! -e "$WORKER_DIR" ] || [ -d "$WORKER_DIR" ]; } \
    || fail "unsafe worker directory"
if [ ! -d "$WEB_DIR" ]; then
    install -d -o root -g www-data -m 0750 "$WEB_DIR"
fi
if [ ! -d "$WORKER_DIR" ]; then
    install -d -o root -g "$WORKER_GROUP" -m 0750 "$WORKER_DIR"
fi
[ ! -L "$WEB_KEY" ] && { [ ! -e "$WEB_KEY" ] || [ -f "$WEB_KEY" ]; } \
    || fail "unsafe PHP key target"
[ ! -L "$WORKER_KEY" ] && { [ ! -e "$WORKER_KEY" ] || [ -f "$WORKER_KEY" ]; } \
    || fail "unsafe queue key target"

TMP_KEY="$(mktemp "$WEB_DIR/.denagate-node-key.XXXXXX")" \
    || fail "temporary key unavailable"
TMP_PUB="$(mktemp "$WEB_DIR/.denagate-node-pub.XXXXXX")" \
    || fail "temporary public key unavailable"
trap 'rm -f -- "$TMP_KEY" "$TMP_PUB"' EXIT HUP INT TERM

# Decode from stdin; no private key in arguments, files in Git or logs.
printf '%s' "$NODE_SSH_PRIVATE_KEY_B64" | base64 -d > "$TMP_KEY" 2>/dev/null \
    || fail "invalid encoded private key"
chmod 0600 "$TMP_KEY"
ssh-keygen -y -P '' -f "$TMP_KEY" > "$TMP_PUB" 2>/dev/null \
    || fail "invalid or passphrase-protected private key"
KEY_FP="$(ssh-keygen -lf "$TMP_PUB" 2>/dev/null | awk '{print $2}')" \
    || fail "private key fingerprint unavailable"
[ "$KEY_FP" = "$EXPECTED_KEY_FP" ] \
    || fail "private key fingerprint mismatch"

# Only replace credential files after key verification. Neither runtime group
# may modify the private key. Different paths avoid privileged group changes.
install -o root -g www-data -m 0640 "$TMP_KEY" "$WEB_KEY" \
    || fail "PHP credential install failed"
install -o root -g "$WORKER_GROUP" -m 0640 "$TMP_KEY" "$WORKER_KEY" \
    || fail "queue credential install failed"

echo "Node SSH key runtime prepared (fingerprint verified)."
