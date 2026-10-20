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

- Persistent ReplyKeyboard (when opt-in enabled) only has small role-aware primary destinations. Admin subfunctions are grouped, not placed in a wall of buttons.
- InlineKeyboard is for object actions, selections, Back, Cancel and confirmations. Stale historic Reply labels plus old commands and callbacks MUST remain routable and re-authorized; never delete an Action to simplify a menu.
- Navigation is contextual; Back returns to a parent, Cancel makes no mutation. No inert buttons. Never assume hiding a button replaces server authorization.

## UXR-01 — Sales presentation contract (2026-10-20)

- Sales always installs its own persistent two-column Reply menu on `/start`/entry: «🛒 خرید سرویس», «📋 سرویس‌های من», «👤 حساب کاربری», «💬 پشتیبانی». The separate legacy/Admin hybrid-menu opt-in does not control Sales. Existing callbacks edit their page instead of sending another home greeting. Reply and inline keyboards use separate messages, as required by Telegram.
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
