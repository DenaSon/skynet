#!/usr/bin/env bash
# Root-owned worker launched by systemd-run; never invoked through SSH.
set -Eeuo pipefail
umask 077
ID="${1:-}"
[[ "$ID" =~ ^rw-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$ ]] || exit 64

BASE=/var/backups/denagate-rw-managed
STATE="$BASE/state/$ID.status"
FINAL="$BASE/$ID.tar"
PW=/etc/denagate-backup/restic-password
exec 9>"$BASE/.operations.lock"
flock -x 9

[[ -f "$STATE" && "$(cat "$STATE")" == running ]] || exit 75
[[ -r "$PW" && -d /opt/remnawave ]] || exit 69
[[ ! -e "$FINAL" ]] || exit 73

STAGE=""
cleanup() {
  local code=$?
  if ((code != 0)); then
    printf 'failed\n' > "$STATE"
    rm -f -- "$FINAL" "$FINAL.sha256" "$FINAL.part" "$FINAL.sha256.part"
  fi
  [[ -z "$STAGE" ]] || rm -rf -- "$STAGE"
}
trap cleanup EXIT
trap 'exit 1' TERM INT
STAGE="$(mktemp -d "$BASE/.stage.XXXXXXXX")"
export RESTIC_PASSWORD_FILE="$PW"

mkdir -p "$STAGE/extras" "$STAGE/repository"
chmod 0700 "$STAGE" "$STAGE/extras"

# A logical dump, not a live file copy of PostgreSQL 18.
docker exec -u postgres remnawave-db \
  sh -ec 'exec pg_dumpall -U "${POSTGRES_USER:-postgres}"' \
  | gzip -1 > "$STAGE/extras/postgresql-all.sql.gz"
test -s "$STAGE/extras/postgresql-all.sql.gz"
gzip -t "$STAGE/extras/postgresql-all.sql.gz"

tar -C /var/lib/docker/volumes/caddy_caddy-ssl-data/_data \
  -czf "$STAGE/extras/caddy-ssl-data.tar.gz" .
tar -tzf "$STAGE/extras/caddy-ssl-data.tar.gz" >/dev/null

{
  date -u
  uname -a
  docker ps --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}'
  docker compose ls
  docker volume ls
  docker image ls --digests
  df -hT
  lsblk -f
  dpkg-query -W
} > "$STAGE/extras/system-inventory.txt"

SOURCES=(/opt /etc /root /home /usr/local /boot "$STAGE/extras")
for path in /var/lib/cloud /var/spool/cron; do
  [[ ! -e "$path" ]] || SOURCES+=("$path")
done

restic -r "$STAGE/repository" init
nice -n 10 restic -r "$STAGE/repository" backup "${SOURCES[@]}" --exclude=/root/.cache
restic -r "$STAGE/repository" check --read-data

tar -C "$STAGE" -cf "$FINAL.part" repository
tar -tf "$FINAL.part" >/dev/null
mv -- "$FINAL.part" "$FINAL"
sha256sum "$FINAL" > "$FINAL.sha256.part"
mv -- "$FINAL.sha256.part" "$FINAL.sha256"
printf 'ready\n' > "$STATE"
