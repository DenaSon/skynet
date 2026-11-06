# RW-BACKUP-01 — Remnawave manual backups

Status: source implementation, default OFF until operator acceptance.
Scope: Super Admin Bot > Settings > Backup management: create, list, download, delete. No cloud storage, scheduled runs, auto-delete, restore from Bot, backup of independent nodes, or whole-disk image.

## Architecture

- Trusted Super Admin authorization with private-chat enforcement on every callback. Laravel uses a dedicated pinned-host-key SSH identity; only server-defined operations can run.
- The root-owned forced command denagate-rw-backup-ssh allows only start, list, info, stream, delete. No arbitrary Telegram shell commands. Archive names are validated against strict IDs.
- Start launches a short detached systemd unit, so the Telegram webhook does not wait for the backup; refresh reads running/ready/failed states.
- The worker creates a separate encrypted Restic repository per backup, saves PostgreSQL 18 logical pg_dumpall, Caddy SSL data, /opt/remnawave, /etc, /root, /home, /usr/local, /boot, and optional cron/cloud-init, plus inventory. Restic check with read-data and tar verification precede publication.
- Archives and SHA256 sidecars go to /var/backups/denagate-rw-managed. Previously created archives in /var/backups/denagate-rw/denagate-rw-*.tar are also discoverable if their SHA256 sidecar exists.
- Downloads use a private 10-minute, one-time, high-entropy bearer URL, re-check root eligibility, and stream from RW to the browser without permanent Laravel storage. Treat the URL as a secret: do not log the full /internal/rw-backups/* URL in web-server, application, CDN or analytics access logs. HTTPS required. A stolen unredeemed URL can be used by its holder.
- Deletion requires second confirmation, is blocked for the last ready backup, and locks against concurrent downloads. Each archive is independent, so removing it frees the entire archive without Restic prune.

## Operator setup — RW server

Do NOT change a running production SSH configuration without maintaining a separate recovery session and confirming host access. Do not touch the previous validated archive.

1. Copy version-controlled resources/scripts/denagate-rw-backup-ssh.sh and resources/scripts/denagate-rw-backup-worker.sh to the RW server, review them and install as root-owned 0700 at /usr/local/sbin/denagate-rw-backup-ssh and /usr/local/sbin/denagate-rw-backup-worker, respectively.
2. Create /etc/denagate-backup (0700) and /etc/denagate-backup/restic-password (root-owned 0600). Provision a strong, offline-stored Restic password without shell arguments or logs. This secret is not stored in GitHub or Laravel. The original 2026-11-06 backup remains decryptable with its original password, which may differ.
3. Generate a dedicated Ed25519 keypair in the Laravel secret store; mount private key read-only into the running Laravel container. Add ONLY its public key to root authorized_keys on RW with forced command and restrictions:

       restrict,command="/usr/local/sbin/denagate-rw-backup-ssh" ssh-ed25519 AAAA... rw-backup-bot

   If egress is static, additionally use the OpenSSH from= restriction. This credential is still highly privileged: protect it, rotate it and revoke it on compromise.
4. Verify the remote SSH host-key fingerprint via a trusted independent channel, then pin its public key in a Laravel-mounted 0600 known_hosts file. Do NOT blindly accept output of ssh-keyscan.
5. Verify restic, docker, gzip, tar, flock, systemd-run and sufficient free disk; remnawave-db must be the expected PostgreSQL 18 container and caddy_caddy-ssl-data the correct Caddy volume.
6. Set the Laravel environment (never in GitHub), initially disabled:

       RW_BACKUP_ENABLED=false
       RW_BACKUP_SSH_HOST=<verified-server-host>
       RW_BACKUP_SSH_PORT=22
       RW_BACKUP_SSH_USER=root
       RW_BACKUP_SSH_KEY_PATH=/run/secrets/rw-backup-ssh
       RW_BACKUP_SSH_KNOWN_HOSTS_PATH=/run/secrets/rw-backup-known-hosts

   Ensure the PHP-FPM user can read both mounted files. Rebuild the Laravel config cache. Test SSH from the actual running PHP container with strict host checking. Only enable RW_BACKUP_ENABLED=true after full operator acceptance.
7. Configure web proxy for HTTPS streaming of files larger than 100 MB: do not buffer complete streams or enforce a short upstream timeout. Suppress sensitive bearer-link paths in request logs.

## Runflare deployment — persistent credential source (RW-BACKUP-02)

The existing Laravel postStart command calls /bin/sh /var/www/resources/scripts/prepare-ssh-runtime.sh,
which now calls prepare-rw-backup-ssh.sh after the existing outbound SSH setup.

**Verified identity (2026-11-06):** RW at 194.5.193.234:22; ED25519 server
fingerprint SHA256:GOhp0wGAGpHHvKMrOUi0zZyj+IqLf7kTPa5ehluDht4;
dedicated client ED25519 fingerprint
SHA256:UYGvU7PFhf3957DhEFQ6d0xllJO9CcOzmWbBXGFLL0A.
The public host key is pinned at resources/ssh/rw-backup-known-hosts.
Do not substitute unauthenticated ssh-keyscan output.

1. On the **operator's Windows computer** where the registered passphrase-free key resides,
   run this in PowerShell:

       $key = Join-Path $HOME ".ssh\denagate_rw_backup"
       ssh-keygen -lf "$key.pub"
       $encoded = [Convert]::ToBase64String([IO.File]::ReadAllBytes($key))
       $encoded | Set-Clipboard
       Remove-Variable encoded

   Verify the client fingerprint above before copying. This base64 string represents
   the private key: it is **not encryption**. Never share it in a chat, Git commit,
   issue, shell command argument, or screenshot. Clear the clipboard after setup.

2. In **Runflare > project skynet > Secrets**, store the copied value as a
   protected secret and expose it to the laravel service under the variable
   RW_BACKUP_SSH_PRIVATE_KEY_B64 via a Secret reference, not a plain env variable.
   See https://runflare.com/docs/secret/ and
   https://runflare.com/docs/setup-environment-variable/ .
   Saving changes to environment settings may restart the service.

3. Configure these service environment values (no credentials in source):

       RW_BACKUP_ENABLED=false
       RW_BACKUP_SSH_HOST=194.5.193.234
       RW_BACKUP_SSH_PORT=22
       RW_BACKUP_SSH_USER=root
       RW_BACKUP_SSH_KEY_PATH=/run/secrets/rw-backup-ssh
       RW_BACKUP_SSH_KNOWN_HOSTS_PATH=/run/secrets/rw-backup-known-hosts

4. The root-run postStart hook validates the decoded private key without logging it,
   refuses passphrases or wrong client fingerprints, verifies the pinned host key,
   and installs files as root:www-data with mode 0640 under /run/secrets.
   If the directory does not exist it is created with mode 0750. An existing
   /run/secrets directory is never re-permissioned. No key Secret is needed
   while RW_BACKUP_ENABLED=false, preserving the prior Node SSH setup.

5. Verify from the actual **Laravel container** without printing secret contents:

       stat -c '%a %U:%G %n' /run/secrets/rw-backup-ssh /run/secrets/rw-backup-known-hosts
       su -s /bin/sh www-data -c 'test -r /run/secrets/rw-backup-ssh && test -r /run/secrets/rw-backup-known-hosts && echo WWW_DATA_CAN_READ=YES'

   Next, test the read-only SSH list command as www-data; keep the application
   feature disabled until complete list/download/checksum/restore acceptance.

**Rollback:** Keep RW_BACKUP_ENABLED=false. Removing the Runflare secret reference
stops future provisioning after the next deployment; already running pods may still
hold the key until terminated. Revoking the restricted authorized_keys entry on RW
disables the credential immediately. Never print raw env vars, /proc/*/environ, or
private keys while troubleshooting.

## Test/acceptance plan

- Super Admin entry in Settings, private-chat only; ordinary admins, revoked accounts, expired confirmation tokens and arbitrary archive IDs cannot list, start, download or delete.
- Start is bounded and returns an ID quickly; states change running > ready. Parallel starts are rejected.
- Download a new encrypted file with one-use 10-minute link and verify byte count + SHA256 on a separate machine. Replay, expired link and revoked root return not found.
- Delete an older version and confirm disk space reduction, but deny last backup and delete during streaming.
- Run test suite, PHPStan, Pint and GitHub CI. CI does not verify production SSH, real systemd jobs or full PostgreSQL restore.
- Final Production smoke and independent restore test require operator action. Rollback is RW_BACKUP_ENABLED=false plus restoring cached configuration; remove dedicated authorized_keys entry to revoke SSH operations.

## Limitations

- No off-server durability until downloaded. Failure of RW VPS can destroy undownloaded archives.
- If worker is killed or times out, running marker may stay fail-closed; diagnose unit and journal before operator marks the failed status. Do not clear an active job blindly.
- Live Valkey state, Docker image layers, node-local data, and a bitwise VPS disk image are excluded.
- File and repository checks do not establish that a complete PostgreSQL/application restore was successful.
