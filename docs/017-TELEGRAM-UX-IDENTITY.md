# DenaGate — Telegram UI/UX Identity (Normative)

**Status:** Permanent, normative presentation contract for every future Telegram feature, including Super Admin. Adopted 2026-10-13. [PR #48](https://github.com/DenaSon/skynet/pull/48) implements shared language/copy components, grouped administration and guided Sales forms. Broad customer/finance workflow redesign and actual live Telegram smoke remain separate work.

## Product promise

DenaGate is a practical Persian Telegram bot. A person can administer products, plans, users and prices without knowing databases, frameworks, or internal architecture. Preserve **every existing capability and Action**: simplification must never remove functions, bypass authorization, or change price, money, order or provisioning rules.

## Visible language

- The public-facing product name in new/changed UI is **DenaGate** (updated 2026-10-20, UXR plan). Older ParsaGate text is migrated only in the current feature scope. Retain technical identifiers and historical records; do not rebrand infrastructure or stored references.
- Warm, direct, concise Persian. One screen means one goal or decision. Use short headings, labeled values, one primary call to action, clear Back/Cancel.
- No engineering vocabulary in Telegram: milestones F09/F10/F11, UX labels, Laravel, Nutgram, Remnawave, API, CI, PR, E2E, database, migration, DTO, provider, revision, implementation status, operation codes, or developer commentary. Engineering detail belongs in code, documentation, logs and audits. Human record identifiers are allowed when needed for copy/reconciliation.
- Say what a feature can actually do. For an unpurchasable plan, say 'خرید این پلن فعلاً فعال نیست.' Do not simulate a purchase CTA.
- A validation error must explain the next step, not print internal exceptions. Sensitive confirmation names the object and its exact effect; avoid verbose boilerplate.
- Present money in clearly labeled tomans; calculations still use integer rials in Sales/Billing.
- Emojis are functional landmarks, not decoration. Status words: active = '🟢 فعال', inactive = '⏸️ غیرفعال', draft = '📝 پیش‌نویس', archived = '📦 بایگانی‌شده'.


## MENU-01 — native Sales command menu (approved 2026-10-22; supersedes Sales ReplyKeyboard requirement)

The operator explicitly approved replacing the **Sales-only** permanent four-button ReplyKeyboard with Telegram's native `☰ Menu` command list. Existing Sales `/buy`, `/subscriptions`, `/account`, `/help` are the four menu destinations (the first is a minimal alias to the existing authorized Buy presenter). Sales `/start`, `/menu` and stale Home text remove the previous keyboard via `ReplyKeyboardRemove`; Sales inline submenus and all previously accepted text labels remain available. This scoped override supersedes the previous four-button persistent requirement in items 5, Navigation and UXR-01 below **for Sales only**. The previous UXR-01 and PR #139 descriptions are historical code/acceptance checkpoints, not new instructions to reinstate the Sales ReplyKeyboard. Admin/legacy role-aware keyboard and commands are unchanged. Menu commands are installed only by a separately invoked, token-guarded `sales:telegram:menu:register` after deploy, not on every webhook. A Telegram native client determines icon and location; the app cannot move the emoji button.

## Permanent Telegram visual and writing standard — all bots (approved 2026-10-21)

**Binding design rule, not a new feature phase:** Apply to every *new or modified* screen in both Sales and Admin Telegram bots, including privileged screens. Prefer clean, accessible, minimal Persian UI; keep unchanged paths working. Do not launch a broad UI rewrite without a separate approved scope.

1. **Native semantic colors, sparingly:** Nutgram/Telegram `ButtonStyle::SUCCESS` (green) for the principal positive action, `PRIMARY` (blue) for useful navigation/selection, `DANGER` (red) only for rejection/destructive actions, and default neutral for ordinary Back/Cancel/account/help. Not every button needs a color. Native Telegram clients own rendering; no arbitrary hex/RGB, no false promise of identical appearance on older clients or themes. Existing DenaGate teal/navy branding is for brand graphics or supported web surfaces, not a configurable Telegram-button palette.
2. **Relevant icons only:** Use one recognizable Unicode emoji when it improves scanning (🛒 خرید, 📋 سرویس‌ها, ✅ تأیید, ❌ رد, ⚠️ هشدار, 🏠 خانه). Keep the label understandable without the emoji or background color. Prefer Unicode over `icon_custom_emoji_id`; custom emoji IDs need eligible accounts/bots and explicit assets, never fake them.
3. **Text hierarchy and spacing:** One screen = one intent; short bold heading, blank line between logical groups, brief sentences and compact lists. Highlight important figures and actual prices with selective `<b>…</b>`, not entire paragraphs; clearly label prices in toman. Use short `<blockquote>…</blockquote>` for a genuinely useful note/caution, not for decoration. Use Telegram-supported HTML `parse_mode: 'HTML'`, with `TelegramUi::escape()` for all dynamic/customer/operator-provided content before inserting into markup.
4. **Copy and input:** Use `<code>` for short identifiable values and copyable examples. Provide native `CopyTextButton` when practical (length and privacy constraints); never paste secrets into public or shared chats. Report unavailable/unknown/awaiting states truthfully, without technical errors, misleading zero values or decorative success indicators.
5. **Navigation and actions:** Small persistent Reply Keyboard for *primary destinations* where still supported (Admin/legacy); the Sales Bot uses its MENU-01 native four-command hamburger list, not a persistent keyboard. Inline Keyboard for nested objects, paging, Back/Cancel, read/refresh and one-time sensitive confirmations. The Admin primary keyboard stays role-aware. Button presses must work; historic labels/callbacks remain routed and freshly authorized. Avoid duplicated greeting messages on callbacks and avoid editing media as text.
6. **Accessibility and review:** Status must remain legible in words, not color alone. Ensure readable RTL Persian wording, restrained emoji, concise labels, a valid return path and clear error recovery. Review layouts and serialized native styles on PHP 8.4/8.5; verify actual Telegram client rendering separately after deployment. No additional packages, UI framework, permissions or payment/provider side effects for visual polish.

**Reference implementation (source-only):** [PR #139](https://github.com/DenaSon/skynet/pull/139) merged on `master` as `0f843898a`: Sales two-row Reply menu, green Buy/blue Services, styled Inline Buy/plan selection, old «🛒 خرید سرویس» text still accepted. The PHP 8.4/8.5, Pest (642 tests / 4,030 assertions), PHPStan, Pint and disposable MySQL PR checks passed; **real Telegram color/route smoke is not recorded**. The rule is global for *future scoped work*; PR #139 did not restyle Admin or every existing message.

## MVP-01 scoped plan details (2026-10-17)

- Admin-only plan detail shows the linked service's **remote-confirmed human name**, or a concise unavailable/missing/outdated message. Never show Squad UUID, raw API response, technical flags or developer terms to operators.
- A single actionable Persian reason explains why checkout is not ready; existing service-selection and Back buttons remain. This is presentation-only and never overrides verified checkout/financial/provider guards.

## MVP-02 Admin fulfillment outcomes (2026-10-17)

- Root-only Admin subscription activation queue keeps pending/review states while a separate small «✅ فعال‌سازی‌های انجام‌شده» screen shows locally completed orders; success is described as recorded, not proof of current online provider status.
- Keep a short refresh and contextual Back path and the original explicit confirmation for any write. Never expose provider credentials, API metadata or developer vocabulary in status/result text.

## MVP-04 — Super Admin service status (2026-10-17)

- From a completed-order detail, show only «🔌 مدیریت وضعیت اشتراک» then the verified «فعال/غیرفعال/نیازمند بررسی/قابل بررسی نیست» state. Show at most one available operation, with explicit one-time confirmation and a safe Back/refresh path; never show developer error text, provider ID/UUID or credentials.
- A queued operation is a **request**, not a completed status change. If the result is uncertain, offer only «🔎 بررسی نتیجه», which must not write again. No root status buttons in Sales Bot or ordinary Admin menus.

## Navigation

- Persistent ReplyKeyboard (when opt-in enabled, on Admin/legacy) only has small role-aware primary destinations. Sales uses native commands per MENU-01. Admin subfunctions are grouped, not placed in a wall of buttons.
- InlineKeyboard is for object actions, selections, Back, Cancel and confirmations. Stale historic Reply labels plus old commands and callbacks MUST remain routable and re-authorized; never delete an Action to simplify a menu.
- Navigation is contextual; Back returns to a parent, Cancel makes no mutation. No inert buttons. Never assume hiding a button replaces server authorization.

## UXR-02 — Sales-only payment presentation (2026-10-20)
- Preset selection from Sales home or catalog opens the one-screen payment directly, with fresh buyer/price/Squad validation; no separate preflight/preview/confirmation page. Existing callbacks remain safe, Admin and legacy routes remain independent.
- Render the **recorded order price** in bold tomans, with plan/traffic/duration and only real configured destination details. Escape all dynamic text in Telegram HTML; use native CopyTextButton for exact bank card and amount. Do not ship sample or inferred payment details.
- A short optional ForceReply is a UX hint for photos, **never** proof of ownership or payment; photo submission verifies current private Sales account, owned payable order, 10-minute scoped state and duplicate media through existing Actions. After receipt: «رسید دریافت شد؛ منتظر بررسی مدیر باشید». No Sales approval or provider writes.
- A repeat tap resumes the same owned pending order; «خرید جدید» is explicit. Expired/non-photo input and Home/Cancel show safe way forward without wiping Admin bot input. Financial and UXR-03 scope remains unchanged.

## UXR-01 — Sales presentation contract (2026-10-20)

- **Historical UXR-01 rule, replaced by approved MENU-01:** Sales formerly installed a persistent two-column Reply menu on `/start`/entry: «🛒 خرید سرویس», «📋 سرویس‌های من», «👤 حساب کاربری», «💬 پشتیبانی». MENU-01 now clears it and registers those destinations as native commands. The separate legacy/Admin hybrid-menu opt-in still does not control Sales. Existing callbacks edit their page instead of sending another home greeting. Reply and inline keyboards use separate messages, as required by Telegram.
- Home shows at most five current buyer-priced, published, Squad-ready presets per page. This is discovery only: retain existing checkout/repricing/financial guards. No readiness/preflight screen removal before UXR-02.
- Account retains invitations and reseller builder access; applications live under support/training. Guides are short and static; use verified official client links. Support uses the configured real Telegram account. If unset/invalid, show an honest unavailable message and guides, without an inert contact button.
- Use Telegram HTML with escaped dynamic values: bold headings/prices, optional short blockquote for one useful instruction, sparse functional emoji, blank lines between groups. Avoid walls of text or all-bold screens. Existing unmodified finance/service templates are completed in later UXR phases.
- Primary labels/Home/Cancel take precedence over pending free text and clear only this bot's UI input draft. Unknown text/buttons offer a safe way home and never fall through to Admin handlers. Recheck active/private identity and reseller role on each action.
- Acknowledge before a slow remote read; a best-effort `typing` indicator is optional feedback, never proof of business success and never a reason to retry a write. Keep shared Telegram transport helpers.
- In Admin Node management, «افزودن سرور جدید» is for an unprepared VPS; «ثبت نود آماده» registers a preinstalled node. Explain that users choose one route. Do not change ports or installation behavior in a naming change.

## Input, clipboard, confirmation

- Prefer buttons for enumerated fields, and one human-entered value per prompt where feasible. Expose a fast copyable full-line shortcut for operators needing it.
- Copyable examples use safely escaped Telegram HTML code format plus Nutgram's native CopyTextButton on the InlineKeyboardButton. The copy payload is EXACTLY the original text (1–256 characters). Never fake a copy action with a callback, and never put secrets or live credentials in examples.
- A wizard must reuse existing validated Sales Actions. Never bypass validation, optimistic concurrency, audit, actor-bound single-use confirmation and TTL. Preview all proposed modifications before saving; sensitive status changes stay independently confirmed.
- Short, deterministic feedback for success, failure, expiry and cancellation; give a way back.

## Implementation boundaries and checklist

- Shared transport-only presentation helpers live under app/Interfaces/Telegram/Support/TelegramUi.php. All presenters must use `TelegramUi::answerCallback`, `TelegramUi::editText` or `TelegramUi::editCaption` instead of calling Nutgram callback acknowledgement or message/caption edit methods directly. Only Telegram HTTP 400 `message is not modified` and expired callback acknowledgements are benign; every other error must still surface. A source-regression test enforces this boundary. Keep business rules in modules. No new payment or provider side effects in UX work.
- Every change should test: command/callback reachability, role and private-chat guards, copy payload and HTML escaping, cancellation, one-time/expiry/stale replay, existing order behavior and no extra provider calls.
- PHP 8.4/8.5 Pest, PHPStan and Pint must pass before merge. GitHub CI is not proof of live Telegram E2E; separately record which screens are manually verified.
- Preserve Back/Cancel, old callbacks and commands, and the enabled/disabled behavior of all flags.
- Release increments: UX-01 shared language/design vocabulary; UX-02 grouped navigation; UX-03 guided admin forms; UX-04 user catalog/builder/shop/order polish; UX-05 users/RBAC/finance polish; UX-06 exhaustive regression and live smoke.
- A PR may finish only a subset. Mark incomplete flows explicitly in docs/000-CONTEXT.md, rather than calling the entire redesign done.

Review this standard before every future Telegram UI feature.

## NW-01 — Guided server readiness

- Only private trusted root and when NODE_SSH_SETUP_ENABLED is on; one prompt at a time, Cancel and contextual Back. Never echo SSH password or raw key material.
- Independently confirm the displayed SSH fingerprint before a one-shot authentication test. An SSH preflight pass must never be presented as completed Node installation or VPN connectivity.
- Deletion failures of password messages abort the flow. Existing Node, Host, Profile and Sales menus/callbacks remain intact.

## NW-02 — Automated Node installation

- After the verified server check, show one explicit install confirmation; never begin remote writes from a read-only validation callback.
- Only show short Persian state messages for queued, installing, running, failed or uncertain operations. Do not expose SSH password, Node SECRET_KEY, raw logs, Linux package commands or internal phase labels.
- Refresh status without auto-retrying. A running server is not a connected Node until panel registration in the following flow. Always preserve Back and the original Node/Host menus.

## NW-03 — Node/Host completion

- One explicit confirmation to register an installed Node, followed by a fresh connection check; disconnected or unavailable must not look successful.
- Client port is a separate user-entered field and must match the chosen inbound; do not imply SSH or Node management ports are customer endpoints.
- The Host is created disabled. Link to existing Host details and its separate enable confirmation; if its profile/inbound cannot be verified, show a short manual-review notice.
- Show a clear instruction to review Internal Squad access before enabling a Host. No automatic edits to users, squads or sales.

## NW-04 — Recovery UX

- Show «ادامه راه‌اندازی اخیر» in the existing Node menu only when an authorized unexpired attempt exists and SSH install is enabled; do not show a dead button or retain secrets in the bookmark.
- In pending/failed/uncertain states, say what to check without inventing success, requiring blind retries or revealing debug logs/credentials.
- For a server setup begun in another menu session, the same status screen must be recoverable; no new install, Node, Host or Squad write is triggered by refresh or return. Preserve current Node/Host/Profile menus and separate Host enable confirmation.


## UXR-03 Sales/Admin payment-to-connection message contract

- A customer-submitted receipt is **awaiting bank review**, never a successful payment. Admin receives the protected receipt image, short plan/order/amount context, and only two direct decision buttons when the gated mode is enabled. The decision is the operator's final explicit attestation of a separately verified real bank transfer, not bank API verification.
- A committed approval may show «پرداخت تأیید شد؛ اشتراک در حال آماده‌سازی است» **only while automated provisioning is actually enabled and verified**. An uncertain/failed create is not described as active. Rejected receipts may be resubmitted through the existing purchase flow.
- Only after the real RW result and owned order/subscription mapping are committed, send a fresh «✅ اشتراک شما فعال شد» Sales message with «🔐 نمایش کد اتصال». The callback contains only an order identifier; a new owner/authorization check precedes the private connection link. Receipt/photo and connection credentials never appear in public chats, logs or callbacks.
- Legacy payment and fulfillment management commands remain available to authorized operators for exceptional/manual recovery; simple Persian is shown to Telegram users, with internal mechanism and rollout flag names restricted to developer documentation.


## UXR-04 — Account and owned services

- The Sales account displays a modest summary of the active linked identity, actual membership date, all paid/fulfilled sales-order totals (not unpaid orders) and registered owned services. All money remains calculated in rials and labelled in tomans; reseller wallet stays reseller-only and re-authorized.
- «📋 سرویس‌های من» lists only actual linked services, not orders awaiting payment or activation. Use the plan/name, traffic and duration in short readable lines; a button opens details directly. The stored local `active` state is labelled «فعال در سوابق» and must not be misrepresented as real provider usability or expiry. The customer sees verified current usage, quota and expiry only after the owner-authorized provider read succeeds.
- Show «🔐 نمایش کد اتصال» only after the verified link/usage read succeeds. Use the existing private ownership Action at the final click too. Offer «آموزش اتصال» and «پشتیبانی» without invented operator handles or tickets.
- Short escaped HTML headings, labelled lines, bold monetary totals, a single brief blockquote when useful, clear navigation and descriptive status words take precedence over decorative UI. Failed reads show unavailable/retry, never artificial zero statistics.


## UXR-05 — Official subscription browser page handoff

- «🌐 صفحه اشتراک» is a separate, optional action near «🔐 نمایش کد اتصال» in the Sales-only service detail and success message. It appears only after an operator configures the exact live Remnawave Subscription Page hostname; otherwise the legacy private connection flow is unchanged.
- Page callbacks carry only order ID and cursor. On every click, recheck private buyer, owned order, active provider identity and expiry through existing Application Actions; accept only the exact HTTPS host returned by RW, then show a protected private Telegram message with the real browser-open URL and native copy when permitted. Never fabricate or log subscription credentials, append short UUIDs, or hand out panel management URLs.
- The **official** Subpage Builder is responsible for DenaGate/Persian branding, mobile use, QR/import, real usage/expiry and customer instructions. Laravel does not render the web page. Missing external deployment/provider readiness must be described as unavailable, not activated. See [docs/027](027-UXR05-SUBSCRIPTION-PAGE-RUNBOOK.md).
\n\n## CLOSE-01 — Single decision payment review\n\n- Admin receives a receipt photo with only «✅ تأیید پرداخت» and «❌ رد رسید», never «بررسی تأیید», typed `/bankconfirm`, or another confirmation screen. Payment decisions happen exactly once on root-private callback; repeating/forging cannot duplicate a journal.\n- If customer notification or automatic RW creation is unavailable, approval must show a short actionable message, **without changing** the claim/order financial state. This prevents the old paid-order/manual-`/fulfillment` trap. Pending order fulfillment detail is status/recovery only.\n- Rejection notifies buyer and does not create RW. Only confirmed remote completion is described as active and yields the private Sales connection action. Finance/provider internal details and token names stay out of Telegram messages.\n

## NAV-01 — Admin media return and Sales true home (2026-10-21)

- Admin's existing `menu:admin` callbacks must always return to the authorized management menu. When the callback is attached to a photo or other media, do **not** attempt `editMessageText` on media; answer the callback and send a new text menu while leaving the media intact. Text-origin callbacks retain in-place `TelegramUi::editText` behavior. This avoids Telegram 400 and repeated update retries after receipt-photo navigation; do not hide unrelated 400 failures or skip RBAC.
- Sales `menu:home` and `sales:home` mean the **main Sales menu**, not the ready-plan catalog. The minimal home displays Buy, My Services, Account and Support, plus an all-plans shortcut; the persistent two-column Reply menu remains unchanged. Buy opens the existing buyer-validated, paginated ready-plan view, and legacy `sales:home:{page}` callbacks still open its original page. The current checkout/quote, purchases, owned services, Support, credentials, notifications and provider behavior stay untouched.
- Test source navigation across text and media, Sales /start /menu/callback, stale offer pagination, active role authorization and no business-side changes. Operator Telegram acceptance remains separate from fake Nutgram/CI.

## Sales native button styling (2026-10-21)

- The four existing Sales primary destinations remain a persistent two-column Reply keyboard, while nested plan selections, account/service details, help, Back and confirmations remain Inline. No new router, Admin change or callback contract.
- Sales Buy uses the native Telegram `success` (green) style and a simple green-dot label; Services uses `primary` (blue). Account and Support stay neutral. The matching Home and plan-selection inline buttons use native `success` / `primary` respectively. Existing keyboard text «🛒 خرید سرویس» remains an accepted alias for older client keyboards.
- Telegram controls colors; older clients may render default colors. Custom emoji ID icons are not used because they require eligible bot ownership/Premium. Ordinary Unicode emoji remain supported. Nutgram 4.50.3 exposes `ButtonStyle` natively; no dependency, feature flag, migration, finance or provider changes.
- Source tests cover serialized styles, four-button layout, historical text, and preserved callback routing. Real Telegram presentation is an independent post-deploy check.

## Targeted customer guide and live subscription detail (2026-10-21)

- Sales guides use verified direct 2dust GitHub releases for Android `v2rayNG` and Windows `v2rayN` and official Apple App Store for iOS/macOS `V2Box`. Never mention the backend provider brand or link to its documentation in buyer messages. Preserve existing guide callbacks and home/support destinations; live imports still need device verification.
- For authorized owned linked services, detail performs a fresh read-only provider GET even when remote subscription status is expired, disabled or limited; show actual live status/expiry/traffic and truthful unavailable state on failures. Only ACTIVE, unexpired, non-exhausted credentials can be delivered; `GetSubscriptionLink` remains strict and revalidated. Local aggregate expired totals are not invented. No write, schema, sync or new provider operations.
