# MB-04 — Dedicated Admin Telegram UX: release review

**Scope:** Admin presentation over the existing BotFather token. The Sales token/router remains disabled. This is not a public Sales cutover or a new provider/dashboard API.

## Implemented screens / menu contract

| Actor / condition | `/start` and `/menu` | Persistent ReplyKeyboard when enabled | Inline navigation |
| --- | --- | --- | --- |
| Active linked, ordinary member / reseller | Existing buyer home unchanged | Existing buyer/reseller labels unchanged | Existing buyer/owner callbacks unchanged |
| Active linked admin (`users.manage`) | Admin-only home directly | `👥 کاربران`, `👤 حساب من`; two-column renderer | `👥 کاربران`, `👤 حساب من` |
| Active linked trusted Super Admin | Admin-only home directly | `👥 کاربران`, `🔐 نقش‌ها و دسترسی‌ها`, `📦 محصولات و پلن‌ها`, `👤 حساب من` | Users/Roles in one row; products/plans section and account |
| Trusted root, billing or manual-bank flag enabled | As above, plus `💳 امور مالی` | As above, plus `💳 امور مالی` | Finance section links only to enabled existing review queues |
| Inactive/unlinked or revoked actor | Existing unavailable / buyer home, as appropriate | Old privileged labels reauthorize and are denied | Forged or old privileged callbacks reauthorize and are denied |

- `/admin`, `menu:admin`, `menu:home`, old `🏠 منوی اصلی` Reply label, `/resetmenu`, all old admin commands, old callbacks and buyer/reseller commands are retained. `/start` refreshes an existing operator's persistent keyboard without requiring server-side Telegram changes.
- `📦 محصولات و پلن‌ها` retains `📚 محصولات پایه` and `📦 مدیریت پلن‌ها`, including the already implemented guided create/edit/status/price menus, quick-entry shortcuts and genuine native copy buttons. Their Application Actions, audit, single-use confirmation and TTL logic are unchanged.
- `💳 امور مالی` is omitted unless an existing bank/top-up review flag is enabled. Disabled sections are rejected even from stale Reply and Inline buttons.
- No fake `وضعیت سیستم`, no operator-wide `سرویس‌ها` or `سفارش‌ها` listing is rendered: there is currently no approved authorized Admin read Action providing these capabilities. Buyer-owned `/orders` and `/subscriptions` remain functional via legacy routes.
- Changes are UI/navigation and access gates only: no MySQL schema, no real provider read/write, no payment settlement behavior, no new dependency, no changed Sales flags.

## Manual BotFather smoke / screenshot checklist — not yet live-verified

- [ ] Capture sanitized Admin `/start` screenshot with Inline keyboard when hybrid menu disabled.
- [ ] Capture sanitized trusted-root `/start` screenshot with two-column persistent ReplyKeyboard enabled.
- [ ] Verify `کاربران` → user list → status confirmation → Cancel/Back; `نقش‌ها` → protected role; root allowlist revocation denies stale callbacks.
- [ ] Verify `محصولات و پلن‌ها` → base products, plans, guided edit, one-field edit, full-line shortcut and native clipboard copy; Back/Cancel do not mutate.
- [ ] When permitted, open finance review queues; with flags disabled, confirm finance is hidden and stale actions are denied. Never approve a real bank transaction during smoke.
- [ ] Confirm existing linked member/reseller sees their old `/start`; original `/buy`, `/orders`, `/builder` (eligible reseller), `/resetmenu` and old labels remain functional.
- [ ] Capture only redacted Telegram screenshots, without any token, username/phone, payment reference or sensitive link.

**Evidence boundary:** PHP 8.4/8.5 Pest, PHPStan, Pint and disposable MySQL migration CI are automated code gates. Real Telegram screenshots, user interaction and Production smoke are **unverified** until an operator actually executes this checklist. MB-05A/MB-05B offline are separate phases.
