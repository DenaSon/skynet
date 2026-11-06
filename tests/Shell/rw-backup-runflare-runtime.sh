#!/usr/bin/env bash
# Safe test fixture: creates a throwaway key, never uses the production private key.
set -Eeuo pipefail
SOURCE=resources/scripts/prepare-rw-backup-ssh.sh
TMP="$(mktemp -d)"
trap 'rm -rf -- "$TMP"' EXIT

# With feature OFF and no Secret, the existing postStart flow must still work.
RW_BACKUP_ENABLED=false /bin/sh "$SOURCE" > "$TMP/disabled.log"
grep -q 'not configured' "$TMP/disabled.log"
[[ ! -e /run/secrets/rw-backup-ssh ]] || {
    echo "Refusing to run on a runner with an existing RW backup key" >&2
    exit 1
}
# Enabled without a Secret must never silently succeed.
if RW_BACKUP_ENABLED=true /bin/sh "$SOURCE" > "$TMP/enabled.log" 2>&1; then
    echo 'FAIL: backup enabled with missing SSH Secret' >&2
    exit 1
fi

ssh-keygen -q -t ed25519 -N '' -f "$TMP/test-id" -C test-fixture
FP="$(ssh-keygen -lf "$TMP/test-id.pub" | awk '{print $2}')"
cp resources/ssh/rw-backup-known-hosts "$TMP/pinned"
sed -e "s@^DIR=/run/secrets\$@DIR=$TMP/secrets@" \
    -e "s@^PINNED=/var/www/resources/ssh/rw-backup-known-hosts\$@PINNED=$TMP/pinned@" \
    -e "s@^EXPECTED_KEY_FP=.*@EXPECTED_KEY_FP='$FP'@" \
    "$SOURCE" > "$TMP/runtime.sh"
sh -n "$TMP/runtime.sh"

# The fixture key is created locally, passed only via a process environment,
# and never printed or sent to GitHub as an artifact.
export RW_BACKUP_SSH_PRIVATE_KEY_B64
RW_BACKUP_SSH_PRIVATE_KEY_B64="$(base64 -w0 "$TMP/test-id")"
export RW_BACKUP_ENABLED=false
chmod 0755 "$TMP"
sudo --preserve-env=RW_BACKUP_SSH_PRIVATE_KEY_B64,RW_BACKUP_ENABLED /bin/sh "$TMP/runtime.sh" > "$TMP/ready.log"
grep -q 'credentials ready' "$TMP/ready.log"
[[ "$(sudo stat -c '%a %U:%G' "$TMP/secrets/rw-backup-ssh")" == '640 root:www-data' ]]
[[ "$(sudo stat -c '%a %U:%G' "$TMP/secrets/rw-backup-known-hosts")" == '640 root:www-data' ]]
sudo -u www-data test -r "$TMP/secrets/rw-backup-ssh"
sudo -u www-data test -r "$TMP/secrets/rw-backup-known-hosts"
sudo ssh-keygen -y -P '' -f "$TMP/secrets/rw-backup-ssh" > "$TMP/derived-pub"
cmp -s "$TMP/derived-pub" <(ssh-keygen -y -P '' -f "$TMP/test-id")

# Invalid base64 must fail without overwriting the previous valid key.
before="$(sudo sha256sum "$TMP/secrets/rw-backup-ssh" | awk '{print $1}')"
export RW_BACKUP_SSH_PRIVATE_KEY_B64='invalid-base64!'
if sudo --preserve-env=RW_BACKUP_SSH_PRIVATE_KEY_B64,RW_BACKUP_ENABLED /bin/sh "$TMP/runtime.sh" >/dev/null 2>&1; then
    echo 'FAIL: malformed private key accepted' >&2; exit 1
fi
after="$(sudo sha256sum "$TMP/secrets/rw-backup-ssh" | awk '{print $1}')"
[[ "$before" == "$after" ]] || { echo 'FAIL: existing credential overwritten on error' >&2; exit 1; }

# A modified host key must fail closed.
printf '127.0.0.1 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIEQv8qCRUQ93vQxrTR6HRrW7kk4dcInmZZ/p75dI4tgS\n' > "$TMP/pinned"
export RW_BACKUP_SSH_PRIVATE_KEY_B64
RW_BACKUP_SSH_PRIVATE_KEY_B64="$(base64 -w0 "$TMP/test-id")"
if sudo --preserve-env=RW_BACKUP_SSH_PRIVATE_KEY_B64,RW_BACKUP_ENABLED /bin/sh "$TMP/runtime.sh" >/dev/null 2>&1; then
    echo 'FAIL: changed server fingerprint accepted' >&2; exit 1
fi

echo 'RUNFLARE RW SSH RUNTIME TESTS PASSED'
