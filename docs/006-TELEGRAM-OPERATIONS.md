# Telegram Foundation — Setup and Operations

**Phase:** F06-C completes role management after F06-A navigation and F06-B user operations, building on the F4 secure webhook and account-link foundation. No server commands or user-management actions through Telegram.

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

## F06-B user-management navigation
Open `/admin` → **👥 کاربران**, or use `/users` (in a private chat as a current authorized admin). Six accounts appear per page, oldest IDs first. Tap an account for its name, internal ID, email, roles, active/inactive state, and whether its Telegram account is linked. Use the numbered buttons and Back controls to navigate.

Tap **🔎 جست‌وجو**, then send `/finduser <term>` (one token, up to 64 chars). Search matches an internal numeric ID exactly or finds a fragment of name/email. The per-actor search context expires after 10 minutes; use `/finduser` again if expired. No user creation or role changes here.

For eligible accounts, tap **فعال‌کردن / غیرفعال‌کردن** and explicitly confirm. A random actor-bound confirmation is valid for only 3 minutes and is consumed once. The Identity transaction checks live role/activity and current target status again. Super Admins cannot be disabled from the bot, ordinary admins cannot disable peers, and users cannot disable themselves. Successful changes append `identity.user.status_changed` audit rows.

**Operations:** Database-backed Laravel Cache and its lock table are required for short-lived confirmations. Set up the scheduler separately for other Telegram tasks. As with all bots, this is NOT proof of a deployed bot; use a real private chat only after your webhook/polling and operator binding have been configured. F06-C role management remains deferred.

## F06-C role management
A **trusted** Super Admin opens `/admin` → **🔐 نقش‌ها و دسترسی‌ها** (or `/roles`) in private chat. Roles are paginated; tapping a role shows current permissions and user count. The registered permission catalog is paginated, eight per screen, with previous/next callbacks. System roles (`member`, `admin`, `super-admin`) are locked for permission edits. On custom roles, each registered permission can be toggled after a one-time confirmation. Only permission names actually defined/enforced in the backend can be assigned; no UI-defined privileges.

To create a role, tap **➕ نقش جدید** and send `/newrole reseller` (3–50 ASCII slug characters). The role is created without permissions after confirmation; then assign the desired permissions from its screen.

For an existing user, open **👥 کاربران** → user → **🔐 مدیریت نقش‌های کاربر** (visible to trusted roots). Toggle a desired role and explicitly confirm. Users can have multiple roles; removing the last one is blocked. Root role enrollment/revocation, root account disablement, modifying built-in role permissions and changing passwords are intentionally unavailable via the bot.

All role/permission/user-role writes are confirmed with a three-minute operator-bound single-use token, consumed by the cache lock, re-authorized by the Identity Application layer and recorded in `identity_audit_events`. The default database cache supports locks; configure a shared, durable lock-capable store in production and migrate Laravel's cache tables. If ENV IDs or active DB role change, stale callbacks will not grant rights.

Do not mistake successful Nutgram fake tests for Telegram/BotFather deployment; configure real HTTPS webhook and the two enrolled roots separately.

## F07 public registration and invite codes
Operator setup comes first: bootstrap and Telegram-link intended administrators/Super Admins **before** allowing public joins. Keep `TELEGRAM_PUBLIC_SIGNUP_ENABLED=false` while bootstrapping. When ready, set `TELEGRAM_PUBLIC_SIGNUP_ENABLED=true` and `TELEGRAM_BOT_USERNAME=YourBotUsername` (BotFather username without `@`) in the private deployment `.env`, then clear/rebuild config cache. Never commit token, webhook secret or root IDs. A configured public HTTPS webhook is still required for Telegram traffic.

A new person opens `/start` (or a shared deep link `https://t.me/YourBotUsername?start=ref_<24-hex-code>`). The start message **does not register anyone**. The person must tap **✅ ایجاد حساب رایگان** in the private chat, which creates a linked `member` identity. Old administrator accounts should instead enter `/link <one-time-code>`; joining cannot grant privileges. Users with an existing Telegram binding will **not** be registered again or moved to another referrer.

Any active linked member or administrator uses `/invite` or **🎁 دعوت دوستان** to get a stable opaque referral link and a count of invited users who completed signup. A 15-minute pending invitation context is saved only for the target Telegram user, then consumed at initial signup. Invalid codes are ignored; an existing account is never reattributed. This is attribution only: **no wallet, commission, money or reward**. New Telegram-only accounts currently have synthetic non-deliverable email addresses and randomized web passwords, and cannot reset or use a real email login until a deliberate future account-upgrade flow.

Before shipping: E2E test fresh private join, linked admin `/link`, invite click, duplicate `/start` and link, and forged group traffic against the real webhook. When the feature is disabled, joining must refuse to create a user even if a stale button is clicked.

## F08 manual reseller wallet (MVP, bank-reviewed)
Keep `BILLING_TOPUPS_ENABLED=false` until bank verification procedure and real Telegram E2E are signed off. Configure `BILLING_PAYMENT_INSTRUCTIONS` in production deployment settings (short, human-reviewed transfer instructions); these values are **not committed to git**.

A trusted Super Admin creates the custom `reseller` role using the RBAC UI (`/newrole reseller`) and assigns it to the appropriate linked account. The reseller uses `/wallet` to see a ledger-derived prepaid credit balance and ten recent requests. `/topup 500000` creates a request for **500,000 tomans** (stored as 5,000,000 rials), but does NOT credit any money. After the reseller independently pays using the configured bank details, they send `/paid <request_id> <bank_reference>` (6–64 alphanumeric/underscore/hyphen chars). This merely changes the request to **submitted**.

The allowlisted Super Admin uses `/topups` or **💳 بررسی شارژها** and independently verifies the transfer amount/reference against bank activity **outside SkyNet**. The root selects approval or rejection, then confirms with an operator-bound single-use token within 3 minutes. Approval atomically records the verified bank reference (globally unique), journal, debit to `cash_clearing`, credit to `wallet_liability`, and the approved status. Rejecting produces no ledger credit. The same payment reference cannot be used to fund two wallets. The UI lists ten oldest submitted requests per screen; additional submissions appear when earlier ones are resolved.

These are customer prepaid service credits and **cannot be transferred, withdrawn or used to purchase a service** yet; purchases are F09. This is an auditable compact ledger, not a tax/invoicing engine. Root must never approve based solely on a customer's typed reference or screenshot. For live deployment use a DB with reliable row locking (MySQL/PostgreSQL preferred for financial concurrency), backups and an operator reconciliation procedure. Real bank verification integration and bank-ref collision policies are deferred. Test retry, rejected claim, shared-bank-reference fraud, root revocation and crashed/duplicate webhook delivery before allowing real funds.

**Code milestone:** F08 PR #13 merged after CI passed with 106 tests / 494 assertions on PHP 8.4/8.5. No actual money transfers have been performed by these tests. Do not enable live topups without separate payment-operations verification.

## F09-B Telegram Subscription Builder (no ordering yet)
Use **🛒 خرید اشتراک** from the private Home menu or type `/buy`. For one active product the first screen lists allowed duration options **with minimum-volume prices**; multi-product catalogs first show paginated product cards with starting prices. After duration selection, choose a displayed volume/price or **✏️ حجم دلخواه** then send the integer amount (e.g., `75`, `۷۵` or `٧٥`) as an ordinary chat message. The opt-in text input expires in ten minutes, is scoped to the active account and cannot be activated in a group. Invalid amounts can be retried before expiry.

The summary screen shows current public price, role-dependent reseller discount and total, all **recalculated from Sales**, not from Telegram button text or a cached price. Prices in UI are formatted as integer tomans and rounded up when an exact rial amount contains a fraction of a toman. **This is read-only and cannot create or pay for an order**. Rates still require operator setup in `sales:product:set` / `sales:rate:set`; missing tariffs are clearly reported as unavailable. Telegram BotFather E2E remains pending even after fake-transport CI.

## F09-C — Confirming an order (not payment)
Once a customer reviews the final computed quote in the Telegram preview, **✅ ثبت سفارش** creates an unpaid **`pending_payment`** order with the exact server-side public/reseller quote frozen in the database. The checkout callback includes an opaque 10-minute buyer-bound token; if prices, product availability or role change, the order is **rejected** and the customer must re-open a fresh quote. Repeated clicks on an already successful token return the existing order, rather than creating another.

Use **📋 سفارش‌های من** or `/orders` to view the latest ten orders and tap any order for its snapshotted duration, volume, price and status. An order is **not a paid subscription**: no wallet money is reserved/deducted, no bank payment is completed and no Remnawave account is created. Purchasing/activation belongs to later independent increments.
