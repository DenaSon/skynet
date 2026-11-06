#!/usr/bin/env bash
# Root-owned forced SSH command. SSH_ORIGINAL_COMMAND is never evaluated.
set -Eeuo pipefail
set -f
umask 077
BASE=/var/backups/denagate-rw-managed
LEGACY=/var/backups/denagate-rw
WORKER=/usr/local/sbin/denagate-rw-backup-worker
mkdir -p "$BASE/state"
chmod 0700 "$BASE" "$BASE/state"
exec 9>"$BASE/.operations.lock"

valid_id() {
  [[ "$1" =~ ^rw-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$ || "$1" =~ ^denagate-rw-[0-9]{8}T[0-9]{6}Z$ ]]
}
archive_path() {
  if [[ "$1" == denagate-rw-* ]]; then printf '%s/%s.tar' "$LEGACY" "$1"
  else printf '%s/%s.tar' "$BASE" "$1"; fi
}
require_ready() {
  valid_id "$1" || exit 64
  local file
  file="$(archive_path "$1")"
  [[ -f "$file" && -f "$file.sha256" ]] || exit 66
  printf '%s' "$file"
}
list_all() {
  local file id archive state size mtime
  shopt -s nullglob
  for file in "$BASE"/rw-*.tar "$LEGACY"/denagate-rw-*.tar "$BASE"/state/rw-*.status; do
    id="$(basename "$file")"
    id="${id%.status}"
    id="${id%.tar}"
    valid_id "$id" || continue
    [[ "$file" != *.status || ! -f "$BASE/$id.tar" ]] || continue
    archive="$(archive_path "$id")"
    if [[ -f "$archive" && -f "$archive.sha256" ]]; then
      state=ready
    elif [[ -f "$BASE/state/$id.status" ]]; then
      state="$(head -n 1 "$BASE/state/$id.status")"
    else state=failed; fi
    [[ "$state" == ready || "$state" == running || "$state" == failed ]] || state=failed
    size=0
    [[ ! -f "$archive" ]] || size="$(stat -c %s "$archive")"
    mtime="$(stat -c %Y "$file")"
    printf '%s\t%s\t%s\t%s\n' "$id" "$state" "$size" "$mtime"
  done
}
start_backup() {
  flock -n -x 9 || exit 75
  local f id
  shopt -s nullglob
  for f in "$BASE"/state/rw-*.status; do
    # On worker crash a running marker is intentionally fail-closed.
    [[ "$(cat "$f")" != running ]] || exit 75
  done
  command -v systemd-run >/dev/null
  [[ -x "$WORKER" && -r /etc/denagate-backup/restic-password ]] || exit 69
  id="rw-$(date -u +%Y%m%dT%H%M%SZ)-$(od -An -N4 -tx1 /dev/urandom | tr -d ' \n')"
  printf 'running\n' > "$BASE/state/$id.status"
  if ! systemd-run --quiet --collect --property=RuntimeMaxSec=1200 \
    --unit="denagate-rw-backup-$id" "$WORKER" "$id"; then
    printf 'failed\n' > "$BASE/state/$id.status"
    exit 70
  fi
  printf '%s\n' "$id"
}
delete_backup() {
  valid_id "$1" || exit 64
  flock -n -x 9 || exit 75
  local file count=0 archive
  archive="$(require_ready "$1")"
  shopt -s nullglob
  for file in "$BASE"/rw-*.tar "$LEGACY"/denagate-rw-*.tar; do
    [[ ! -f "$file.sha256" ]] || count=$((count + 1))
  done
  ((count > 1)) || exit 73
  rm -- "$archive" "$archive.sha256"
  rm -f -- "$BASE/state/$1.status"
  printf 'deleted\n'
}
read -r -a ARGS <<< "${SSH_ORIGINAL_COMMAND:-}"
[[ ${#ARGS[@]} -ge 2 && ${#ARGS[@]} -le 3 && "${ARGS[0]}" == rw-backup ]] || exit 64
case "${ARGS[1]}" in
  list) [[ ${#ARGS[@]} -eq 2 ]] || exit 64; list_all ;;
  start) [[ ${#ARGS[@]} -eq 2 ]] || exit 64; start_backup ;;
  info)
    [[ ${#ARGS[@]} -eq 3 ]] || exit 64
    archive="$(require_ready "${ARGS[2]}")"
    checksum="$(awk 'NR==1 {print $1}' "$archive.sha256")"
    [[ "$checksum" =~ ^[a-f0-9]{64}$ ]] || exit 65
    printf '%s\t%s\n' "$(stat -c %s "$archive")" "$checksum"
    ;;
  stream)
    [[ ${#ARGS[@]} -eq 3 ]] || exit 64
    valid_id "${ARGS[2]}" || exit 64
    flock -s 9
    archive="$(require_ready "${ARGS[2]}")"
    cat -- "$archive"
    ;;
  delete)
    [[ ${#ARGS[@]} -eq 3 ]] || exit 64
    delete_backup "${ARGS[2]}" ;;
  *) exit 64 ;;
esac
