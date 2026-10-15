# MB-01 — Audited Telegram Route, Permission & State Inventory

**Phase:** MB-01 (documentation and contract only)  
**Baseline:** `master` `047549c5888076df04d1ccb30aacf98370ee893b`, inspected 2026-10-15  
**Primary source:** `routes/telegram.php`, related handlers/support, `config/telegram.php` and `config/nutgram.php`  
**Read with:** [ADR 0009](adr/0009-dual-telegram-bot-boundaries.md), [execution roadmap](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md), [Telegram UX](017-TELEGRAM-UX-IDENTITY.md).

**MB-03 registration handoff (PR #55, source implementation):** The line references below intentionally describe the immutable MB-01 baseline, not current file positions. The original BotFather runtime now composes explicit `app/Interfaces/Telegram/routes/shared.php` (10), `admin.php` (63), `legacy_sales.php` (39 including photo) and `legacy_text.php` (one mixed catch-all), through `routes/telegram.php`. All 113 registrations remain available to old linked users. The independent `routes/sales.php` is intentionally empty; it never loads Admin, legacy or shared registrations. An executable contract test compares the exact command/callback inventory and asserts no duplicate or lost registration. **No Admin-only cutover and no public Sales Bot activation in MB-03.**

> This is an **audited migration inventory**, not proof that a new router is implemented or that every money/provider feature is currently enabled. A route classified `SALES` is **still registered in today's single legacy bot** and must stay reachable there until a verified migration path exists. `SHARED` means a common **business capability or navigation intent**, **not** a blanket guarantee that both future bots expose it to all callers. All authorization remains in Identity/Application actions.

## 1. Coverage and current runtime

**Count extracted from `routes/telegram.php`: 27 commands, 84 callback registrations, 1 catch-all photo handler, 1 catch-all text handler = 113 top-level registrations.** Assignment: 63 ADMIN, 39 SALES, 10 SHARED, 1 MIXED. **No registration left without a destination.**

- The single `Nutgram` runtime registers every route; `POST /api/telegram/webhook` validates one header secret, then calls one `ProcessTelegramUpdate`; the deduplication PK is only `telegram_updates.update_id`. One default BotFather token and a default single `telegram.bot_username` are configured.
- `telegram_accounts` currently maps **one SkyNetwork user to one Telegram numeric user ID**. It is not proof that the same user has started the future other bot; separate bot-contact/delivery eligibility is needed. No account duplication is part of MB-02.
- Current Super Admin guard is **more than role membership**: `SuperAdminTrust::eligible` requires active + `super-admin` + `SUPER_ADMIN_TELEGRAM_IDS` with at least two distinct allowlisted IDs and the user's linked Telegram identity. Ordinary admins require `users.manage` (via `isAdmin()`). Never replace these with a menu-based check.
- `TELEGRAM_PUBLIC_SIGNUP_ENABLED`, `TELEGRAM_HYBRID_MENU_ENABLED`, `BILLING_TOPUPS_ENABLED`, `SALES_MANUAL_BANK_REVIEW_ENABLED`, and `REMNAWAVE_WRITES_ENABLED` remain disabled by default where currently specified; **actual Production values were not queried**.
- No real Remnawave server/API live test is established. Real customer/reseller session counts in Production are **unknown from GitHub**; a privacy-safe, read-only preflight is required before removal of existing legacy routes.

**Legend:** `ADMIN` = only Admin Bot once cut over; `SALES` = Sales Bot later, while staying on legacy bridge now; `SHARED` = context-aware navigation/identity; `MIXED` = currently combined dispatcher, must split. Table "Guard" is the **required minimum** or current UI guard family; specific Application Action checks, record ownership, TTL, stale and revision validation must be preserved, not replaced by this table.

## 2. Complete command inventory (27/27)

| Registered command pattern | Target | Minimum guard / restriction | Code location |
| --- | --- | --- | --- |
| `/start {payload}` | SHARED | Private; actor/flow conditional | `routes/telegram.php:29` |
| `/start` | SHARED | Private; actor/flow conditional | `routes/telegram.php:30` |
| `/menu` | SHARED | Private; actor/flow conditional | `routes/telegram.php:31` |
| `/resetmenu` | SHARED | Private; actor/flow conditional | `routes/telegram.php:32` |
| `/account` | SHARED | Private+active actor | `routes/telegram.php:42` |
| `/invite` | SALES | Private+active buyer/owner | `routes/telegram.php:43` |
| `/buy` | SALES | Private+active buyer/owner | `routes/telegram.php:44` |
| `/subscriptions` | SALES | Private+active buyer/owner | `routes/telegram.php:47` |
| `/orders` | SALES | Private+active buyer/owner | `routes/telegram.php:50` |
| `/wallet` | SALES | Private+active reseller (+flag for topup) | `routes/telegram.php:53` |
| `/topup {toman}` | SALES | Private+active reseller (+flag for topup) | `routes/telegram.php:56` |
| `/paid {id} {reference}` | SALES | Private+active reseller (+flag for topup) | `routes/telegram.php:59` |
| `/payments` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:62` |
| `/bankconfirm {claim} {reference}` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:65` |
| `/topups` | ADMIN | Private+trusted root+topup flag | `routes/telegram.php:71` |
| `/settings` | SHARED | Private+active actor | `routes/telegram.php:74` |
| `/admin` | ADMIN | Private+isAdmin | `routes/telegram.php:75` |
| `/link {code}` | SHARED | Private; actor/flow conditional | `routes/telegram.php:76` |
| `/users` | ADMIN | Private+isAdmin | `routes/telegram.php:77` |
| `/roles` | ADMIN | Private+trusted root | `routes/telegram.php:78` |
| `/builder` | SALES | Private+active reseller (+flag for topup) | `routes/telegram.php:79` |
| `/catalog` | SALES | Private+active buyer/owner | `routes/telegram.php:82` |
| `/products` | ADMIN | Private+trusted root | `routes/telegram.php:85` |
| `/plans` | ADMIN | Private+trusted root | `routes/telegram.php:88` |
| `/plancancel` | ADMIN | Private+trusted root | `routes/telegram.php:91` |
| `/newrole {name}` | ADMIN | Private+trusted root | `routes/telegram.php:94` |
| `/finduser {query}` | ADMIN | Private+isAdmin | `routes/telegram.php:97` |

Notes: `/start {payload}` may contain referral onboarding data; Admin Bot must not accidentally register public referral onboarding on its dedicated path. `/link {code}` must remain a **single-use private identity join**, not an admin privilege grant. The root-only `/products` and `/plans` wizards remain tied to existing Sales Application authorization.

## 3. Complete callback inventory (84/84)

| Registered callback pattern | Target | Minimum guard / restriction | Code location |
| --- | --- | --- | --- |
| `menu:home` | SHARED | Private; actor conditional | `routes/telegram.php:101` |
| `menu:account` | SHARED | Private+active actor | `routes/telegram.php:102` |
| `menu:invite` | SALES | Private+active buyer/ownership | `routes/telegram.php:103` |
| `menu:wallet` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:104` |
| `bank:list` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:107` |
| `bank:show:{claim}` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:110` |
| `bank:choose:{claim}:{decision}` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:113` |
| `bank:apply:{token}` | ADMIN | Private+trusted root+bank flag | `routes/telegram.php:116` |
| `shop:pay:{id}` | SALES | Private+active buyer/ownership | `routes/telegram.php:119` |
| `billing:help` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:122` |
| `menu:billing-reviews` | ADMIN | Private+trusted root+topup flag | `routes/telegram.php:125` |
| `billing:review:{id}:{decision}` | ADMIN | Private+trusted root+topup flag | `routes/telegram.php:128` |
| `billing:apply:{token}` | ADMIN | Private+trusted root+topup flag | `routes/telegram.php:131` |
| `onboarding:join` | SALES | Private+public-signup flag (OFF) | `routes/telegram.php:134` |
| `menu:settings` | SHARED | Private+active actor | `routes/telegram.php:135` |
| `menu:admin` | ADMIN | Private+isAdmin | `routes/telegram.php:136` |
| `menu:admin:users` | ADMIN | Private+isAdmin | `routes/telegram.php:137` |
| `menu:admin:roles` | ADMIN | Private+trusted root | `routes/telegram.php:138` |
| `menu:builder` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:139` |
| `builder:list:{page}` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:142` |
| `builder:detail:{id}:{page}` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:145` |
| `builder:days:{id}:{gb}:{page}` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:148` |
| `builder:quote:{id}:{gb}:{days}:{page}` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:151` |
| `builder:custom:{id}:{page}` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:154` |
| `builder:cancel` | SALES | Private+active reseller (flag for topup) | `routes/telegram.php:157` |
| `menu:plan-catalog` | SALES | Private+active buyer/ownership | `routes/telegram.php:160` |
| `catalog:list:{page}` | SALES | Private+active buyer/ownership | `routes/telegram.php:163` |
| `catalog:detail:{id}:{page}` | SALES | Private+active buyer/ownership | `routes/telegram.php:166` |
| `menu:admin:sales` | ADMIN | Private+trusted root | `routes/telegram.php:169` |
| `menu:admin:finance` | ADMIN | Private+trusted root | `routes/telegram.php:172` |
| `menu:admin:plans` | ADMIN | Private+trusted root | `routes/telegram.php:175` |
| `plan:list:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:178` |
| `plan:examples` | ADMIN | Private+trusted root | `routes/telegram.php:181` |
| `menu:admin:products` | ADMIN | Private+trusted root | `routes/telegram.php:184` |
| `product:list:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:187` |
| `product:view:{id}` | ADMIN | Private+trusted root | `routes/telegram.php:190` |
| `product:form:{operation}:{id}:{days}` | ADMIN | Private+trusted root | `routes/telegram.php:193` |
| `product:status:{operation}:{id}:{days}` | ADMIN | Private+trusted root | `routes/telegram.php:196` |
| `product:apply:{token}` | ADMIN | Private+trusted root | `routes/telegram.php:199` |
| `product:quick` | ADMIN | Private+trusted root | `routes/telegram.php:202` |
| `product:back` | ADMIN | Private+trusted root | `routes/telegram.php:205` |
| `product:cancel` | ADMIN | Private+trusted root | `routes/telegram.php:208` |
| `plan:products` | ADMIN | Private+trusted root | `routes/telegram.php:211` |
| `plan:view:{id}` | ADMIN | Private+trusted root | `routes/telegram.php:214` |
| `plan:begin:{type}:{id}` | ADMIN | Private+trusted root | `routes/telegram.php:217` |
| `plan:save:{token}` | ADMIN | Private+trusted root | `routes/telegram.php:220` |
| `plan:status:{id}:{operation}` | ADMIN | Private+trusted root | `routes/telegram.php:223` |
| `plan:apply:{type}:{token}` | ADMIN | Private+trusted root | `routes/telegram.php:226` |
| `plan:editquick` | ADMIN | Private+trusted root | `routes/telegram.php:229` |
| `plan:editfields` | ADMIN | Private+trusted root | `routes/telegram.php:232` |
| `plan:editfield:{index}` | ADMIN | Private+trusted root | `routes/telegram.php:235` |
| `plan:editchoice:{value}` | ADMIN | Private+trusted root | `routes/telegram.php:238` |
| `plan:choose:{value}` | ADMIN | Private+trusted root | `routes/telegram.php:241` |
| `plan:quick` | ADMIN | Private+trusted root | `routes/telegram.php:244` |
| `plan:back` | ADMIN | Private+trusted root | `routes/telegram.php:247` |
| `plan:cancel` | ADMIN | Private+trusted root | `routes/telegram.php:250` |
| `users:page:{page}` | ADMIN | Private+isAdmin | `routes/telegram.php:254` |
| `users:findpage:{page}` | ADMIN | Private+isAdmin | `routes/telegram.php:257` |
| `users:search` | ADMIN | Private+isAdmin | `routes/telegram.php:260` |
| `users:view:{id}:{page}:{mode}` | ADMIN | Private+isAdmin | `routes/telegram.php:263` |
| `users:confirm:{id}:{page}:{mode}` | ADMIN | Private+isAdmin | `routes/telegram.php:266` |
| `users:apply:{token}` | ADMIN | Private+isAdmin | `routes/telegram.php:269` |
| `rbac:page:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:273` |
| `rbac:role:{id}:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:276` |
| `rbac:new` | ADMIN | Private+trusted root | `routes/telegram.php:279` |
| `rbac:perm:{role}:{permission}` | ADMIN | Private+trusted root | `routes/telegram.php:282` |
| `rbac:user:{id}:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:285` |
| `rbac:member:{user}:{role}:{page}` | ADMIN | Private+trusted root | `routes/telegram.php:288` |
| `rbac:apply:{token}` | ADMIN | Private+trusted root | `routes/telegram.php:291` |
| `rbac:p:{id}:{page}:{permissionPage}` | ADMIN | Private+trusted root | `routes/telegram.php:295` |
| `menu:subscriptions` | SALES | Private+active buyer/ownership | `routes/telegram.php:299` |
| `subs:list:{cursor}` | SALES | Private+active buyer/ownership | `routes/telegram.php:302` |
| `subs:view:{id}:{cursor}` | SALES | Private+active buyer/ownership | `routes/telegram.php:305` |
| `subs:link:{id}:{cursor}` | SALES | Private+active buyer/ownership | `routes/telegram.php:308` |
| `subs:nodes:{id}:{cursor}` | SALES | Private+active buyer/ownership | `routes/telegram.php:311` |
| `menu:shop` | SALES | Private+active buyer/ownership | `routes/telegram.php:315` |
| `shop:catalog:{page}` | SALES | Private+active buyer/ownership | `routes/telegram.php:318` |
| `shop:product:{id}` | SALES | Private+active buyer/ownership | `routes/telegram.php:321` |
| `shop:volume:{id}:{days}` | SALES | Private+active buyer/ownership | `routes/telegram.php:324` |
| `shop:custom:{id}:{days}` | SALES | Private+active buyer/ownership | `routes/telegram.php:327` |
| `shop:quote:{id}:{days}:{gb}` | SALES | Private+active buyer/ownership | `routes/telegram.php:330` |
| `shop:order:{token}` | SALES | Private+active buyer/ownership | `routes/telegram.php:356` |
| `shop:orders` | SALES | Private+active buyer/ownership | `routes/telegram.php:359` |
| `shop:order:view:{id}` | SALES | Private+active buyer/ownership | `routes/telegram.php:362` |

The old callback strings themselves are **compatibility contracts**. Buttons already delivered into existing chats must not silently turn into unauthorized provider/money writes; validate actor, bot, state, order/plan revision and confirmation expiry on every click. For the legacy bot, route to prior safe action or a clear fallback as recorded in MB-03 parity tests.

## 4. Non-command Telegram handlers

| Handler | Target | Existing order / acceptance concern |
| --- | --- | --- |
| `onPhoto` (`routes/telegram.php:68`) | SALES + legacy bridge | `ManualPaymentUi::photo` only processes after `telegram:payphoto:{userId}` state and when manual bank review is enabled. Stored `photo_encrypted` contains a **source-bot-scoped** `file_id`; preserve consent, claim identity, pending-order checks, fingerprint duplicate denial and root-review recovery. |
| `onText('{volume}')` (`routes/telegram.php:336`) | MIXED → split | Exact dispatch order is `ReplyMenuHandler::handle` → `ProductAdminUi::text` → `PlanAdminUi::text` → `ResellerBuilderUi::customText` → `ShopUi::customText`. Keep old precedence on the legacy bridge; separately register an Admin-only text path and a Sales-only text path. Never reroute unauthenticated text to a privileged form. |

## 5. Persistent ReplyKeyboard inventory (18/18)

**Source:** `ReplyMenuLabels::all()` and `ReplyMenuHandler`; requires opt-in `telegram.hybrid_menu_enabled`. Old Reply labels **must remain understood on the original bot** even after primary keyboard contents change.

| Constant | Exact Persian label | Target | Guard |
| --- | --- | --- | --- |
| `BUY` | 🛒 خرید اشتراک | SALES | Active buyer |
| `SUBSCRIPTIONS` | 📦 سرویس‌های من | SALES | Active owner |
| `CATALOG` | 📋 پلن‌ها و قیمت‌ها | SALES | Active buyer |
| `ACCOUNT` | 👤 حساب من | SHARED | Active actor |
| `INVITE` | 🎁 دعوت دوستان | SALES | Active buyer |
| `SETTINGS` | ⚙️ تنظیمات | SHARED | Active actor |
| `ADMIN` | 🛡️ مدیریت | ADMIN | isAdmin |
| `WALLET` | 💼 کیف پول نماینده | SALES | Active reseller |
| `BUILDER` | 💼 پلن‌ساز نماینده | SALES | Active reseller |
| `USERS` | 👥 کاربران | ADMIN | isAdmin |
| `ROLES` | 🔐 نقش‌ها و دسترسی‌ها | ADMIN | Trusted root |
| `PLANS` | 📦 مدیریت پلن‌ها | ADMIN | Trusted root |
| `SALES_SECTION` | 📦 محصولات و پلن‌ها | ADMIN | Trusted root |
| `FINANCE_SECTION` | 💳 امور مالی | ADMIN | Trusted root + enabled flags |
| `PRODUCTS` | 📚 محصولات پایه | ADMIN | Trusted root |
| `TOPUPS` | 💳 بررسی شارژها | ADMIN | Trusted root + topup flag |
| `RECEIPTS` | 🧾 بررسی رسیدهای خرید | ADMIN | Trusted root + bank flag |
| `HOME` | 🏠 منوی اصلی | SHARED | Active actor; legacy compatibility |

## 6. State, confirmations and notification touchpoints

| Current state / implementation | Owner after split | Contract for migration |
| --- | --- | --- |
| `telegram_updates` (`update_id` only PK, 90-second lease; processed older than 7 days pruned) | PER BOT | Unique `(bot_key,update_id)`; old receipts backfilled `admin`, legacy webhook mapped to `admin`; concurrent/replayed updates isolated. Reverse schema changes must **not** strand Sales receipts. |
| `TelegramAccount` + `TelegramLinkToken` | SHARED Identity | One internal user for one Telegram numeric ID; one-time link remains private. Track per-bot started/blocked/delivery state separately, without invented new login requirements. |
| `tg:rbac:confirmation:{token}`, `tg:user-status:{token}` | ADMIN | Actor/token-bound, one-shot lock; no cross-bot token acceptance. |
| `telegram:productadmin:draft:{actorId}`, `telegram:planadmin:draft:{actorId}` | ADMIN | Preserve short TTL, mutex and legacy in-flight drafts during phased migration. |
| `telegram:bankreview:{token}`, `telegram:bankreview:origin:{userId}:{claimId}` | ADMIN | Operator-only, single-use confirmation; origin message IDs belong to **the sending bot and chat**, not universally transferable. |
| `telegram:billing:review:{token}` | ADMIN | Keep root/flag checks, single-use token and Billing-side journal idempotency. |
| `telegram:payphoto:{userId}` | SALES | Time-bounded buyer photo intent, not proof of payment. On the legacy bot preserve exact behavior until safe Sales cutover. |
| `telegram:shop:checkout:{token}`, `telegram:shop:volume:{buyerId}` | SALES | Retain buyer/quote ownership and dedupe. Do not enable new managed-plan checkout. |
| `telegram:builder:selection:{buyerId}`, `telegram:signup:referral:{telegramId}` | SALES | Preserve scoped ephemeral selection/referral state and opt-in registration OFF default. |
| `ManualPaymentUi::alertRoots` / `sendPhoto` | ADMIN destination, currently same source bot | **Present:** synchronous, best-effort, uses claim's bot-scoped `file_id`. **MB-05A:** durable bounded notification, safe media bridge, no loss of claim if Telegram unreachable. |
| `ManualPaymentUi::apply` → `TelegramRecipient::forActiveUser` / `sendMessage` | SALES destination, currently same source bot | **Present:** best-effort notification after committed review; do not reverse settlement on send failure. **MB-05A:** route via customer-started Sales Bot and durable outbox; review remains one-time. |
| `sales_fulfillment_outbox` | Sales → Provisioning; **not Telegram notifications** | Existing paid-order intent has a unique event key and no dispatcher. Never consume it as a generic Telegram notification outbox or duplicate financial/provisioning effects. |
| `telegram:webhook:set`, `telegram:prune-updates` | PER BOT aware operator commands | Preserve the existing command behavior for the current bot while enabling explicit context for the new bot; do not drop pending updates. |

### Current notification / authority flow (not a claim of new implementation)

`Sales/Billing order owner` → `ManualBankPayments::submit` stores claim/authorized receipt → `ManualPaymentUi::alertRoots` sends directly via existing bot (may fail) → trusted root uses `bank:*` to review with independent bank reference → `ManualBankPayments::review` atomically changes money/order/review state → `ManualPaymentUi::apply` directly notifies buyer (may fail). **MB-05A** moves delivery only to a durable destination-specific route; existing money transitions and owner validation do not change.

## 7. Action parity / future test matrix

| Feature family | Prior actor/action contract | MB-03/MB-04 parity gate |
| --- | --- | --- |
| `users:*`, `/users`, `/finduser` | Active linked manager with `users.manage`; users statuses through guarded Identity Action | Unauthorized/disabled/stale user denied; existing search/view/status still works |
| `rbac:*`, `/roles`, `/newrole` | Trusted allowlisted Super Admin only, confirmation/token/TTL | Non-root and revoked root denied even with forged callback |
| `product:*`, `plan:*`, `/products`, `/plans` | Trusted root, revision-safe Sales Actions and audit, one-shot previews | Create/edit/price/status unchanged; drafts, copy, Back/Cancel and prior pipe format still work |
| `bank:*`, `/payments`, `/bankconfirm` | Root + manual bank flag, verified bank reference | Photo claim review/approve/reject never duplicated; no automatic provider activation |
| `billing:review:*`, `/topups` | Root + topup flag and Billing journal guards | No extra credit or double capture |
| `shop:*`, `/buy`, `/orders` | Active buyer/owner; F09 pending orders only | Legacy user access remains until migration; no new checkout |
| `subs:*`, `/subscriptions` | Active confirmed owner; credentials only from guarded link action | No cross-user secret link or fake Remnawave response |
| `builder:*`, `/builder`, wallet | Active reseller + current pricing/role checks | No unauthenticated quote/wallet access; no remote mutation |
| Onboarding/referrals | Private opt-in and OFF-by-default public flag | No automatic registration from `/start`; invite owner tracking preserved |

## 8. MB-01 completion and MB-02 preflight

**Completed by MB-01:** entire statically registered route set and ReplyKeyboard label set assigned to target+guard; documented State/cache, source-bot media risk, legacy nonadmin dependency and current notification path; chosen minimal BotContext/rollback contract in ADR 0009. **No PHP/migration/runtime change in this phase.**

**Still required before MB-02/MB-03 cutover:** privacy-safe read-only **actual Production** counts of nonadmin linked users and recent usage (GitHub cannot provide), staging-style copy of real migration structure, verified old-webhook mapping, test bot token + started chats if testing real two-bot messaging, and explicit activation/rollback checklist. If these are missing, keep current legacy customer/reseller commands running and postpone final Admin-only routing on that bot. Do not guess counts.

**Mechanical acceptance check for this document:** exactly 27 commands, 84 callbacks, 1 photo, 1 text, 18 labels; source lists were read against the pinned `master`. On later route changes, update inventory and regression tests as part of the corresponding phase PR.
