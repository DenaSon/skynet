# Telegram Foundation — Setup and Operations

**Phase:** F06-A adds read-only navigation to the F4 webhook and account-link foundation. No server commands or user-management actions through Telegram.

## Requirements
- SkyNet with PHP >=8.4.1, `composer install`, `php artisan migrate`.
- A Telegram bot token from BotFather and **one separate** secret for webhook verification.
- Public HTTPS application URL with TLS and a durable DB. Use HTTPS even behind a reverse proxy and configure trusted proxies correctly.

## Configuration
Add to the **untracked** environment file:

```dotenv
APP_URL=https://skynet.example.com
TELEGRAM_TOKEN=replace-with-your-botfather-token
TELEGRAM_WEBHOOK_SECRET=replace-with-a-long-random-url-safe-value
```

Generate a random secret with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; never commit or paste actual secrets into issues/logs. After changing production env values, clear/rebuild Laravel's cached configuration according to the deployment procedure.

## Register the webhook
```bash
php artisan telegram:webhook:set
# Or explicitly:
php artisan telegram:webhook:set https://skynet.example.com/api/telegram/webhook
```

This project command sets **the configured secret token** with Telegram and limits updates to `message` and `callback_query`. Do **not** use `nutgram:hook:set`: its APP_KEY-derived token is different from the application-controlled secret.

The endpoint `POST /api/telegram/webhook` rejects missing/mismatched secrets (403; 503 when not configured). It is not a web-session endpoint; no CSRF bypass is granted to the normal user routes.

## Link a user without public signup
1. Create the initial admin from a secure terminal using `php artisan identity:bootstrap-admin` (F3).
2. Determine the actual SkyNet user ID from your DB or `php artisan tinker` (do not assume it is 1).
3. Run `php artisan telegram:link-account <user_id>` in a secure terminal. This displays a **single-use** credential for 10 minutes.
4. In a **private chat** with your own bot, send `/link <code>`. The bot links your numeric Telegram ID to that account.
5. Use `/start` to see your menu. Admin users can view the read-only administration navigation. Member users cannot.

Do not forward link codes or reuse them. Link issuance replaces previous unused codes. Link attempts in group chats are ignored. There is no anonymous registration.

## Operations and monitoring
- The webhook writes deduplication receipts to `telegram_updates`. A completed update is acknowledged without rerunning handlers; in-progress duplicates return 503 and ask Telegram to retry; an expired lease or failed update can be retried.
- **Exactly-once outbound messaging is not guaranteed**. This receipt scheme is not an operations ledger for future monetary or server tasks. Those will need their own idempotency keys + durable job/operation state.
- Run Laravel Scheduler (e.g. `php artisan schedule:run` once a minute via cron), which executes `telegram:prune-updates` daily to remove processed receipts older than 7 days.
- Do not run `nutgram:run` polling against the same bot while a production webhook is active.
- Test with `php artisan test`, `vendor/bin/phpstan analyse`, `vendor/bin/pint --test`. The CI uses PHP 8.4 and 8.5.

## Future
A real Telegram admin/user interface and server operations will invoke module Application actions; later Livewire screens can reuse those same actions. No SSH/network protocol is selected in F4.

## F5 protected Super Admin setup
Set `SUPER_ADMIN_TELEGRAM_IDS=id1,id2` (at least two **distinct** numeric user IDs; **not** usernames) in your untracked `.env`, and refresh cached configuration. First bootstrap two internal users and link each account to its own private Telegram identity. Then use `php artisan identity:super-admin:promote <internal-user-id>` for both from the trusted terminal, explicitly confirming each enrollment. If an allowlist entry is removed or its account disabled, privileges stop immediately on subsequent requests. No token or ENV entry grants rights by itself. See [RBAC operations](007-RBAC-OPERATIONS.md).

## F06-A command and keyboard map
- `/start`, `/menu`: home; account/settings buttons for authenticated active users, admin button only for users who currently hold user-management authority.
- `/account`: current SkyNet ID, name, roles and active status (no passwords or linked tokens).
- `/settings`: informational screen with a Home button; **no editable settings are implemented**.
- `/admin`: navigation for users with `users.manage` authority; the Users screen states it will be implemented in F06-B.
- `menu:admin:roles`: read-only preview requiring a **trusted** Super Admin under the F5 allowlist; the future F06-C roles UI is not available to ordinary admins.
- Back/Home inline callbacks edit the same message. The bot checks the current user/permissions again on each interaction, including old callback buttons. Unauthorized admin callbacks receive a brief refusal.
- Groups are not supported for account or privileged menus.

Telegram user/preferences storage, Referral, privileged role enrollment, user mutation and system operations are explicitly outside F06-A.
