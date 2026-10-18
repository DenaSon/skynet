#!/bin/sh
# ParsaGate NW-02: fixed, unattended Remnawave Node install on an already-approved VPS.
# Invoked by systemd; does not register a Node or mutate existing workloads.
set -eu
umask 077
STATUS=/opt/parsa-node-install/status
printf 'installing\n' > "$STATUS"
trap 'printf "failed\n" > "$STATUS"' EXIT

if [ "$(id -u)" -ne 0 ]; then exit 10; fi
. /etc/os-release
case "$ID" in ubuntu|debian) ;; *) exit 11 ;; esac
if [ -e /var/run/docker.sock ] && ! command -v docker >/dev/null 2>&1; then exit 12; fi
if ! command -v docker >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y ca-certificates curl
    install -d -m 0755 /etc/apt/keyrings
    curl --fail --silent --show-error --location "https://download.docker.com/linux/$ID/gpg" -o /etc/apt/keyrings/docker.asc
    chmod 0644 /etc/apt/keyrings/docker.asc
    arch="$(dpkg --print-architecture)"
    . /etc/os-release
    printf 'Types: deb\nURIs: https://download.docker.com/linux/%s\nSuites: %s\nComponents: stable\nArchitectures: %s\nSigned-By: /etc/apt/keyrings/docker.asc\n' "$ID" "$VERSION_CODENAME" "$arch" > /etc/apt/sources.list.d/parsa-docker.sources
    apt-get update -qq
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
fi
systemctl enable --now docker
docker compose version >/dev/null
printf 'starting\n' > "$STATUS"
cd /opt/remnanode
docker compose config -q
docker compose up -d --no-recreate
[ "$(docker inspect --format '{{.State.Running}}' remnanode)" = true ]
printf 'running\n' > "$STATUS"
trap - EXIT
