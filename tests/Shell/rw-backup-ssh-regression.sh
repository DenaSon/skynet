#!/usr/bin/env bash
# Regression test for the production forced SSH command. Never accesses /var/backups.
set -Eeuo pipefail
SCRIPT="resources/scripts/denagate-rw-backup-ssh.sh"
TEMP="$(mktemp -d)"
trap 'rm -rf -- "$TEMP"' EXIT

mkdir -p "$TEMP/managed/state" "$TEMP/legacy"
sed \
  -e "s@^BASE=/var/backups/denagate-rw-managed\$@BASE=$TEMP/managed@" \
  -e "s@^LEGACY=/var/backups/denagate-rw\$@LEGACY=$TEMP/legacy@" \
  -e "s@^WORKER=/usr/local/sbin/denagate-rw-backup-worker\$@WORKER=$TEMP/unused-worker@" \
  "$SCRIPT" > "$TEMP/forced.sh"
bash -n "$TEMP/forced.sh"

legacy=denagate-rw-20261106T141129Z
newer=rw-20261106T150000Z-a1b2c3d4
printf 'legacy archive\n' > "$TEMP/legacy/$legacy.tar"
( cd "$TEMP/legacy" && sha256sum "$legacy.tar" > "$legacy.tar.sha256" )

# Last healthy archive cannot be deleted.
if SSH_ORIGINAL_COMMAND="rw-backup delete $legacy" bash "$TEMP/forced.sh"; then
  echo 'FAIL: deleted the only good backup' >&2
  exit 1
else
  [[ "$?" -eq 73 ]] || { echo 'FAIL: wrong last-backup failure' >&2; exit 1; }
fi
[[ -f "$TEMP/legacy/$legacy.tar" ]]

list="$(SSH_ORIGINAL_COMMAND='rw-backup list' bash "$TEMP/forced.sh")"
[[ "$list" == "$legacy"$'\t'ready$'\t'* ]] || { echo 'FAIL: old backup missing from list' >&2; exit 1; }
metadata="$(SSH_ORIGINAL_COMMAND="rw-backup info $legacy" bash "$TEMP/forced.sh")"
[[ "$metadata" == *$'\t'* && "$metadata" == *"$(sha256sum "$TEMP/legacy/$legacy.tar" | cut -d' ' -f1)" ]] || exit 1

printf 'managed archive\n' > "$TEMP/managed/$newer.tar"
( cd "$TEMP/managed" && sha256sum "$newer.tar" > "$newer.tar.sha256" )
list="$(SSH_ORIGINAL_COMMAND='rw-backup list' bash "$TEMP/forced.sh")"
[[ "$list" == *"$legacy"$'\t'ready$'\t'* && "$list" == *"$newer"$'\t'ready$'\t'* ]] || {
  echo 'FAIL: missing backups in list' >&2
  exit 1
}
[[ "$(printf '%s\n' "$list" | wc -l)" -eq 2 ]] || exit 1

# A second backup permits deleting that version, while preserving the last one.
[[ "$(SSH_ORIGINAL_COMMAND="rw-backup delete $newer" bash "$TEMP/forced.sh")" == deleted ]]
[[ ! -f "$TEMP/managed/$newer.tar" && ! -f "$TEMP/managed/$newer.tar.sha256" ]]
[[ -f "$TEMP/legacy/$legacy.tar" ]]

# Fail closed for arbitrary command/path and concurrent marker.
if SSH_ORIGINAL_COMMAND='rw-backup delete ../../etc/passwd' bash "$TEMP/forced.sh"; then
  echo 'FAIL: accepted an arbitrary path' >&2; exit 1
fi
printf 'running\n' > "$TEMP/managed/state/$newer.status"
if SSH_ORIGINAL_COMMAND='rw-backup start' bash "$TEMP/forced.sh"; then
  echo 'FAIL: concurrent start accepted' >&2; exit 1
else
  [[ "$?" -eq 75 ]] || { echo 'FAIL: unexpected start return' >&2; exit 1; }
fi

echo 'RW BACKUP FORCED COMMAND REGRESSIONS PASSED'
