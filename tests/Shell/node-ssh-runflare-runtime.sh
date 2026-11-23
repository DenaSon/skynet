#!/usr/bin/env bash
# NW-SSH-KEY-01: disposable runtime fixture; never handles the Production Secret.
set -Eeuo pipefail
SOURCE=resources/scripts/prepare-node-ssh-key.sh
TMP="$(mktemp -d)"
trap 'sudo rm -rf -- "$TMP"' EXIT

# ubuntu-latest has www-data; add only the test-only queue user if absent.
id -u www-data >/dev/null 2>&1
if ! id -u www >/dev/null 2>&1; then
    sudo groupadd -f www
    sudo useradd --system --no-create-home --gid www www
fi

ssh-keygen -q -t ed25519 -N '' -f "$TMP/test-id" -C node-ssh-test-fixture
ssh-keygen -q -t ed25519 -N '' -f "$TMP/other-id" -C node-ssh-mismatch
FP="$(ssh-keygen -lf "$TMP/test-id.pub" | awk '{print $2}')"
sed -e "s@^WEB_DIR=/run/secrets\$@WEB_DIR=$TMP/web@" \
    -e "s@^WORKER_DIR=/run/denagate-node-worker\$@WORKER_DIR=$TMP/worker@" \
    -e "s@^EXPECTED_KEY_FP=.*@EXPECTED_KEY_FP='$FP'@" \
    "$SOURCE" > "$TMP/runtime.sh"
sh -n "$TMP/runtime.sh"
chmod 0755 "$TMP"

# Optional configuration must not create any credentials.
env -u NODE_SSH_PRIVATE_KEY_B64 /bin/sh "$TMP/runtime.sh" > "$TMP/empty.log"
grep -q 'not configured' "$TMP/empty.log"
[[ ! -e "$TMP/web/denagate-node-ssh" && ! -e "$TMP/worker/identity" ]]

export NODE_SSH_PRIVATE_KEY_B64
NODE_SSH_PRIVATE_KEY_B64="$(base64 -w0 "$TMP/test-id")"
sudo --preserve-env=NODE_SSH_PRIVATE_KEY_B64 /bin/sh "$TMP/runtime.sh" > "$TMP/ready.log"
grep -q 'runtime prepared' "$TMP/ready.log"
WEB_KEY="$TMP/web/denagate-node-ssh"
QUEUE_KEY="$TMP/worker/identity"
[[ "$(sudo stat -c '%a %U:%G' "$WEB_KEY")" == '640 root:www-data' ]]
[[ "$(sudo stat -c '%a %U:%G' "$QUEUE_KEY")" == "640 root:$(id -gn www)" ]]
[[ "$(sudo stat -c '%a %U:%G' "$TMP/worker")" == "750 root:$(id -gn www)" ]]
sudo -u www-data test -r "$WEB_KEY"
sudo -u www test -r "$QUEUE_KEY"
sudo -u www-data test ! -r "$QUEUE_KEY"
sudo -u www test ! -r "$WEB_KEY"
sudo -u www-data ssh-keygen -y -P '' -f "$WEB_KEY" > "$TMP/web-pub"
sudo -u www ssh-keygen -y -P '' -f "$QUEUE_KEY" > "$TMP/queue-pub"
cmp -s "$TMP/web-pub" "$TMP/queue-pub"
cmp -s "$TMP/queue-pub" <(ssh-keygen -y -P '' -f "$TMP/test-id")

# An invalid credential or different valid key must not replace either file.
before="$(sudo sha256sum "$WEB_KEY" "$QUEUE_KEY" | awk '{print $1}')"
NODE_SSH_PRIVATE_KEY_B64='not-base64!'
export NODE_SSH_PRIVATE_KEY_B64
if sudo --preserve-env=NODE_SSH_PRIVATE_KEY_B64 /bin/sh "$TMP/runtime.sh" > "$TMP/bad.log" 2>&1; then
    echo 'FAIL: malformed private key accepted' >&2
    exit 1
fi
! grep -Fq "$NODE_SSH_PRIVATE_KEY_B64" "$TMP/bad.log"
NODE_SSH_PRIVATE_KEY_B64="$(base64 -w0 "$TMP/other-id")"
if sudo --preserve-env=NODE_SSH_PRIVATE_KEY_B64 /bin/sh "$TMP/runtime.sh" > "$TMP/mismatch.log" 2>&1; then
    echo 'FAIL: fingerprint mismatch accepted' >&2
    exit 1
fi
after="$(sudo sha256sum "$WEB_KEY" "$QUEUE_KEY" | awk '{print $1}')"
[[ "$before" == "$after" ]]

# Refuse symlinks, even with a valid key.
sudo rm "$QUEUE_KEY"
printf '%s\n' 'do-not-overwrite' > "$TMP/sentinel"
sudo ln -s "$TMP/sentinel" "$QUEUE_KEY"
NODE_SSH_PRIVATE_KEY_B64="$(base64 -w0 "$TMP/test-id")"
if sudo --preserve-env=NODE_SSH_PRIVATE_KEY_B64 /bin/sh "$TMP/runtime.sh" > "$TMP/symlink.log" 2>&1; then
    echo 'FAIL: symlink private-key destination accepted' >&2
    exit 1
fi
grep -qx 'do-not-overwrite' "$TMP/sentinel"
echo 'NODE SSH KEY RUNTIME TESTS PASSED'
