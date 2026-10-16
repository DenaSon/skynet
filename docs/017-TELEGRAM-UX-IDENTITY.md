# ParsaGate — Telegram UI/UX Identity (Normative)

**Status:** Permanent, normative presentation contract for every future Telegram feature, including Super Admin. Adopted 2026-10-13. [PR #48](https://github.com/DenaSon/skynet/pull/48) implements shared language/copy components, grouped administration and guided Sales forms. Broad customer/finance workflow redesign and actual live Telegram smoke remain separate work.

## Product promise

ParsaGate is a practical Persian Telegram bot. A person can administer products, plans, users and prices without knowing databases, frameworks, or internal architecture. Preserve **every existing capability and Action**: simplification must never remove functions, bypass authorization, or change price, money, order or provisioning rules.

## Visible language

- The public-facing product name is **ParsaGate** (پارساگیت) in both Telegram bots, web screens and notifications. Never show SkyNet/SkyNetwork as a brand; retain legacy technical identifiers and historical records to prevent breaking changes.
- Warm, direct, concise Persian. One screen means one goal or decision. Use short headings, labeled values, one primary call to action, clear Back/Cancel.
- No engineering vocabulary in Telegram: milestones F09/F10/F11, UX labels, Laravel, Nutgram, Remnawave, API, CI, PR, E2E, database, migration, DTO, provider, revision, implementation status, operation codes, or developer commentary. Engineering detail belongs in code, documentation, logs and audits. Human record identifiers are allowed when needed for copy/reconciliation.
- Say what a feature can actually do. For an unpurchasable plan, say 'خرید این پلن فعلاً فعال نیست.' Do not simulate a purchase CTA.
- A validation error must explain the next step, not print internal exceptions. Sensitive confirmation names the object and its exact effect; avoid verbose boilerplate.
- Present money in clearly labeled tomans; calculations still use integer rials in Sales/Billing.
- Emojis are functional landmarks, not decoration. Status words: active = '🟢 فعال', inactive = '⏸️ غیرفعال', draft = '📝 پیش‌نویس', archived = '📦 بایگانی‌شده'.

## Navigation

- Persistent ReplyKeyboard (when opt-in enabled) only has small role-aware primary destinations. Admin subfunctions are grouped, not placed in a wall of buttons.
- InlineKeyboard is for object actions, selections, Back, Cancel and confirmations. Stale historic Reply labels plus old commands and callbacks MUST remain routable and re-authorized; never delete an Action to simplify a menu.
- Navigation is contextual; Back returns to a parent, Cancel makes no mutation. No inert buttons. Never assume hiding a button replaces server authorization.

## Input, clipboard, confirmation

- Prefer buttons for enumerated fields, and one human-entered value per prompt where feasible. Expose a fast copyable full-line shortcut for operators needing it.
- Copyable examples use safely escaped Telegram HTML code format plus Nutgram's native CopyTextButton on the InlineKeyboardButton. The copy payload is EXACTLY the original text (1–256 characters). Never fake a copy action with a callback, and never put secrets or live credentials in examples.
- A wizard must reuse existing validated Sales Actions. Never bypass validation, optimistic concurrency, audit, actor-bound single-use confirmation and TTL. Preview all proposed modifications before saving; sensitive status changes stay independently confirmed.
- Short, deterministic feedback for success, failure, expiry and cancellation; give a way back.

## Implementation boundaries and checklist

- Shared transport-only presentation helpers live under app/Interfaces/Telegram/Support/TelegramUi.php. Keep business rules in modules. No new payment or provider side effects in UX work.
- Every change should test: command/callback reachability, role and private-chat guards, copy payload and HTML escaping, cancellation, one-time/expiry/stale replay, existing order behavior and no extra provider calls.
- PHP 8.4/8.5 Pest, PHPStan and Pint must pass before merge. GitHub CI is not proof of live Telegram E2E; separately record which screens are manually verified.
- Preserve Back/Cancel, old callbacks and commands, and the enabled/disabled behavior of all flags.
- Release increments: UX-01 shared language/design vocabulary; UX-02 grouped navigation; UX-03 guided admin forms; UX-04 user catalog/builder/shop/order polish; UX-05 users/RBAC/finance polish; UX-06 exhaustive regression and live smoke.
- A PR may finish only a subset. Mark incomplete flows explicitly in docs/000-CONTEXT.md, rather than calling the entire redesign done.

Review this standard before every future Telegram UI feature.
