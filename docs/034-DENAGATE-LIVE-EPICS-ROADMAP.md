# DenaGate Live — Epics, Delivery Baseline & Forward Roadmap

> **Status: reference baseline + PROPOSED future roadmap (2026-10-30).**
> This document is an implementation handoff, **not** automatic approval to build future features, spend wallet credit, alter Provider state, change production ENV or modify bot menus.
> Canonical runtime state is the latest `master`; verify source and current business rules before each PR.

## 0. Purpose and source-of-truth order

DenaGate Live is the **reseller-only Telegram Mini App** on `https://live.denagate.ir`, delivered by the **same Laravel modular monolith**, not a second product/backend. Use this one document for the next Live feature decision, PR boundary, acceptance criteria and rollback. Do not duplicate the existing bot checkout, wallet or provisioning engines.

1. Latest `master` source, `AGENTS.md`, `docs/000-CONTEXT.md`, `docs/005-ROADMAP.md`, `docs/017-TELEGRAM-UX-IDENTITY.md`, `docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md`.
2. `docs/033-DENAGATE-LIVE-MINIAPP.md` for detailed past implementation, security patches and historical implementation constraints.
3. This document tracks the Live epic boundaries, next step, evidence, decision gates and operator testing; older 033 planning statements for Phases 0–2 are historical when superseded by merged code and operator acceptance here.
4. Nothing in the **proposed** phases below is approved for implementation or merge merely by appearing in this document. Require explicit operator authorization **per phase/PR**.

## 1. Current verified baseline — 2026-10-30

**Source:** `DenaSon/skynet`, `master` at documentation baseline `17e40d6a5a2c8c627547497ba83f973d0dace39f` ([PR #304](https://github.com/DenaSon/skynet/pull/304)). Do not pin future development to this old SHA; refresh `master`.

| Delivery ID | Scope already implemented | Evidence | Status |
| --- | --- | --- | --- |
| LIVE-0 / LIVE-1A | Runflare/Cloudflare host, Sales Bot `/live` private entry, Telegram `initData` HMAC, linked user/reseller RBAC, protected Laravel web session, logout, fail-closed feature gate | [#297](https://github.com/DenaSon/skynet/pull/297) | Merged; operator confirmed actual Telegram login |
| LIVE-1B | Livewire 4, existing Tailwind/Vite and Alpine; mobile-first reseller shell; persistent authorization on Livewire updates | [#299](https://github.com/DenaSon/skynet/pull/299) | Merged |
| LIVE-FIX-HTTPS | HTTPS URL generation for Live host under Runflare TLS termination (without globally trusting proxies) | [#301](https://github.com/DenaSon/skynet/pull/301) | Merged; operator confirmed HTTPS form action |
| LIVE-FIX-HMAC | Telegram bot-token HMAC includes optional `signature` (exclude only `hash`); no relaxation of freshness/identity validation | [#302](https://github.com/DenaSon/skynet/pull/302) | Merged; operator confirmed 403 resolved |
| LIVE-2A | Apple-style light RTL theme, `Vazirmatn` variable font self-hosted with OFL, white cards / Dena teal, mobile bottom navigation and desktop sidebar | [#303](https://github.com/DenaSon/skynet/pull/303) | Merged; operator visually accepted Telegram Mini App |
| LIVE-2B | Real reseller-owned credit, last top-ups, local service directory/labels and bounded pagination, settled sales 7/30 days; no new business logic | [#304](https://github.com/DenaSon/skynet/pull/304) | Merged; operator reported expected real data |
| LIVE-2B CI | Test fixes for independent revoked-role/inactive-user HTTP 403; all main-branch jobs green | [master CI](https://github.com/DenaSon/skynet/actions/runs/41105726118) | 1,278 Pest tests / 8,105 assertions; PHPStan/Pint/MySQL/Redis rollback passed |

**Evidence boundary:** Operator's successful real Telegram tests establish the reported end-to-end login and screens for their tested client/account, **not** every iOS/Android/Desktop version, every reseller or full bank/provider E2E. No future sales or renewal function is claimed to exist in the web app today.

### Shipped user-facing capabilities (do not regress)

- Private Sales Bot `/live` opens DenaGate Live for an **active, linked reseller**. Ordinary member, anonymous, revoked and inactive users are denied.
- No separate browser password or sign-up; direct browser without trusted Telegram Mini App entry cannot mint a reseller session.
- Four tabs: `نمای کلی`, `سرویس‌ها`, `اعتبار من`, `گزارش‌ها`. Current HTML is Persian/RTL; background `#F6F8FA`, brand accent `#14B8A6`; local Vazirmatn WOFF2 + OFL.
- Wallet `ViewWallet` reflects actual ledger-backed balance, **rials internally / tomans only in display**, plus last 10 top-up requests. Its existing Action may idempotently bootstrap an empty wallet; no new posting or transfer.
- Services `ListMyServices` lists owner-linked paid/fulfilled subscriptions, six per page with keyset paging; labels from `ManageResellerServiceLabels::forOrders`. Displayed “active” is **local recorded state**, not live Remnawave health.
- Reports `ReadResellerSalesReport` use settled bank/wallet transaction sources, Tehran 7/30-day dates and currently registered/pending counters. Never equate incomplete orders or wallet top-ups with confirmed revenue.
- No Mini App **purchasing, wallet debit, provider mutation, renewal checkout, receipt upload, public-member panel, direct web login** in merged LIVE-2B.

### Runtime map

| Concern | Current authoritative path / rule |
| --- | --- |
| Web host & paths | `routes/live.php`, `config/denagate_live.php`: `/`, `POST /auth/telegram`, `/dashboard`, `POST /logout`; `DENAGATE_LIVE_ENABLED` gates entry |
| Bot launch | `app/Interfaces/Telegram/Support/SalesLiveEntry.php` on the **Sales Bot**, command `/live`; no default bot-wide menu replacement |
| Identity/session | `ValidateTelegramInitData`, `LiveAuthController`, `EnsureLiveEnabled`, `EnsureLiveReseller`; valid Sales Bot HMAC, bounded timestamp, linked existing account, fresh reseller authorization, session regeneration, logout |
| Livewire presenter | `app/Interfaces/Web/Live/Dashboard.php`, `resources/views/live/dashboard.blade.php`; use Application Actions, never create a parallel REST controller/business service |
| Style | `resources/views/live/layout.blade.php`, `resources/views/live/entry.blade.php`, `resources/css/app.css`; existing Vite/Tailwind/Alpine; `public/fonts/vazirmatn-variable.woff2`, `public/fonts/OFL-Vazirmatn.txt` |
| Owner-scoped reads | `ViewWallet`, `ListMyServices`, `ManageResellerServiceLabels`, `ReadResellerSalesReport` |
| Regression tests | `tests/Feature/Live/LiveTelegramAuthTest.php`, `LiveDashboardShellTest.php`, `LiveResellerReadTest.php` |

### Operational lessons to preserve

- Cloudflare is the publicly authoritative DNS zone for `denagate.ir`; a record in Runflare's DNS interface alone did not make `live.denagate.ir` publicly resolve. **Do not hard-code a historical edge IP** in code or long-term plans; recheck Runflare's actual domain target and Cloudflare records on each infrastructure change.
- Runflare terminates TLS. Initial generated HTML pointed to `http://live.denagate.ir/auth/telegram`, preventing secure form submission; host-scoped HTTPS URL generation fixed it. Preserve the Runflare edge HTTP→HTTPS redirect and avoid global force-Scheme changes or unbounded trust of `X-Forwarded-*`.
- Real Telegram `initData` can carry `signature`. For bot-token HMAC verification exclude **only `hash`**; require signed Telegram user ID, freshness, Sales Bot token and authorization. Do not log/export raw `initData`, tokens or protected subscription URLs.
- Verify `public/build/manifest.json`, font availability, HTTPS, session cookies and an actual Telegram login separately from successful mock/CI tests.
- Never show developer concepts (“phase”, “PR”, “backend”, “not implemented”, “in the next phase”) or inaccurate availability/financial status in reseller-facing UI.

## 2. Proposed roadmap — ordered, independent delivery increments

**Recommended immediate next phase: LIVE-3A (catalog and real quote preview, READ ONLY).** This creates an understandable reseller shopping flow without touching financial postings, provider credentials or order tables. After operator approval and real Telegram acceptance, advance to 3B.

| Epic / phase | User-facing outcome | Scope / existing Actions | Explicitly out of scope | Release gate / dependency | State |
| --- | --- | --- | --- | --- | --- |
| **EPIC LIVE-03 / 3A — Plans & quote** | «خرید سرویس» → available plans → duration/traffic where supported → confirmed reseller price preview | `ListQuotedPresets`, `ListResellerBuilders`, `ListVisiblePlans`, `CalculatePlanQuote`; reuse published tariff/discount and plan validation; view-only Livewire wizard, clear back/cancel | Creating `sales_orders`, wallet debit, bank receipt, Provider calls, new price formulas, extra roles | Operator approval for 3A; tests for reseller role, invalid/stale plan, custom bounds, unchanged public and reseller discounts, 0 mutations; real Telegram UX | **SOURCE COMPLETE / CI GREEN — [#306](https://github.com/DenaSon/skynet/pull/306); live smoke pending** |
| **LIVE-3B — Pending order** | Explicitly confirm quote and customer identifier; see one pending order | `CreatePendingPlanOrder` with fresh quote/price/Squad revalidation, immutable snapshot and per-intent idempotency; reuse existing customer-label workflow only if its contract supports web cleanly | Money deduction, fulfillment, new customer phone requirement, implicit order creation while browsing | 3A accepted; explicit approval to write pending orders; duplicate submit/concurrency, spoofed buyer, changed plan, cancellation tests | Proposed |
| **LIVE-3C — Pay with credit** | Review final cost/balance → explicit one-tap pay → transparent pending/fulfilled/failure status | Existing `SettleWalletOrder` and existing payment/fulfillment outbox + feature gates; existing owner/ledger/idempotency/insufficient-balance rules; no parallel debit code | Direct Provider CREATE/PATCH in Livewire; new bank settlement flow, silent retries, double charging, unapproved ENV flag changes | **Separate sensitive financial approval**; MySQL transaction/concurrency tests, double-submit and crash recovery, real bank/wallet + Provider E2E with controlled disposable order and operator go/no-go | Proposed / gated |
| **EPIC LIVE-04 / 4A — Service detail** | Tap an owned service → plan, duration, verified usage/expiry where available | Owner-verified `GetMySubscription`, optional **on-demand** bounded `GetSubscriptionUsage` / existing `ReadResellerServiceHealth` GET; truthful unknown/expired labeling | Assuming local active = live healthy; background polling, bulk provider reads, exposing raw provider IDs | 2B baseline; owner/foreign ID, error, expired and bounded GET tests; separate live Provider read/visual check | Proposed |
| **LIVE-4B — Connection and customer name** | Owner chooses verified copyable subscription link/QR and may edit customer label | `GetSubscriptionLink` with existing owner/provider validation; `ManageResellerServiceLabels` owner-scoped save; copy and easy back path | Automatic disclosure on the list/dashboard, exposing credentials to unauthorized browser/actor, parallel QR/credentials store, new username/phone schema | 4A accepted; **separate PR for label write** if useful; URL secrecy/XSS/RBAC/revocation tests | Proposed |
| **LIVE-4C — Renewal in the same service** | Renew selected eligible subscription, see exact calculated price, confirm credit payment | `PrepareRenewalDraft`, `CreatePendingRenewalOrder`, existing `SettleWalletOrder` and queued `FulfillPaidRenewalOrder` lifecycle; preserve old source mapping | A new subscription/CREATE for renewal, quota reset without contract, unguarded Provider PATCH, uncertain auto-retry | 3C and 4A accepted; **separate renewal/Provider-write approval**, existing `SALES_RENEWAL_ENABLED` and Provider gate enforcement; real controlled renewal and rollback verification | Proposed / gated |
| **EPIC LIVE-05 / 5A — Credit top-up handoff** | Clear increase-credit action leading to existing Sales Bot top-up flow | Read `ViewWallet`; link/navigate to **existing** private bot flow without changing ledger or receipt handling | Independent web receipt upload, new banking APIs, auto-credit, new wallet table | Prioritize after checkout need is proven; validate real Telegram return/navigation and missing bot access | Optional / proposed |
| **LIVE-5B — Web receipt upload (removed)** | Not in the execution roadmap | No implementation | Browser receipt upload or new payment path | Explicitly removed by operator on 2026-10-30; reconsideration would require an entirely separate scope decision | **REMOVED — DO NOT BUILD** |
| **EPIC LIVE-06 / 6A — Entry & navigation polish** | Discoverable authorized Mini App entry in Sales Bot plus compact contextual return flows | Reuse Sales Bot routes, existing `/live`, role checks, preserve persistent keyboard priorities and Telegram Rich callbacks | Replacing Admin Bot or full Sales menu, a second public member app, mandatory BotFather-wide menu change | 3A–4A stable; separate bot UX approval, test old menus/forged callbacks and actual iOS/Android/Desktop | Proposed |
| **LIVE-6B — Operations & release hardening** | Reliable user-visible errors, performance, privacy and rollback | Bounded Action calls, server-only error logging (no secrets), accessibility/safe-area/reduced-motion, Vite/font checks, controlled release notes and runbook | New monitoring stack, arbitrary analytics/tracking, always-on Provider health poll | Continuous QA; keep changes small and independent; operator smoke and rollback evidence | Proposed |

**Sequencing rule:** Do **not** implement the entire EPIC in one PR. Each phase above needs its own acceptance checklist, price/payment write classification and review. If business Actions cannot meet an actual Mini App requirement, document the gap and seek approval before adding an adapter or changing an Action signature.

## 3. Immediate next PR specification — LIVE-3A (recommended)

**Goal:** An active reseller can review the real currently published managed plans and obtain a reliable price quotation *without* creating an order or moving money.

**In scope**
1. In the existing four-tab UI, expose a small primary «خرید سرویس» entry. Add a simple step-by-step view: published plans → parameter selection only when supported → final quote. Keep mobile bottom tabs readable; avoid adding a fifth persistent tab unless usability evidence supports it.
2. Read plans through `ListQuotedPresets` / `ListResellerBuilders` / `ListVisiblePlans`. Compute only through `CalculatePlanQuote` after fresh role and plan validation. Reflect reseller's **published discount** (not a hardcoded additional 35%).
3. Price/discount components shown in **tomans** with one consistent rial→toman conversion, clear total; no claimed discount stacking, no placeholder pricing.
4. Back/close/invalid-input and stale/deactivated-plan states are readable Persian messages; no development or provider terminology in UI.
5. Keep current Live `Auth`, HMAC, HTTP→HTTPS URL generation, CSRF, RBAC, WebApp entry and read-only dashboard fully unchanged.

**Out of scope:** `CreatePendingPlanOrder`, customer profile input, payment, top-up, renewal, Provider mutations, migrations, REST routes, new composer/npm packages, global BotFather/menu settings.

**Acceptance criteria (all required before proposing merge)**
- Owner is a current active reseller at initial render **and every Livewire update**; spoofed actor/foreign account/removed role cannot quote or see private data.
- Fixed/preset and permitted builder options use current authoritative plan revision and discount; invalid days/GB and suspended/no-price plans deny; existing public catalog behavior unaffected.
- Navigating/quoting/refreshing creates **zero** sales orders, wallet journals/settlements, provider calls or Telegram financial notifications.
- Production assets/font/RTL/navigation are unchanged; CI PHP 8.4 Pest, PHPStan, Pint, and MySQL smoke when the change detector requires it all green; manual Sales Bot `/live` test accepted.
- Distinct PR with a focused rollback and a small update to **this** living roadmap and/or 033 only if source-relevant.

**Decision to request before starting:** “Approve LIVE-3A read-only catalogue/quote UI, with no orders or money movement.” Approval of 3A does not authorize 3B/3C or Live Provider tests.

### LIVE-3A source checkpoint (2026-10-30)

- Operator explicitly approved **only** read-only catalogue and quote. Implementation PR: [#306](https://github.com/DenaSon/skynet/pull/306); branch `feature/live-3a-catalog-quote-20261030`. Source branch is not proof of CI or deployed acceptance.
- Reuses `ListQuotedPresets`, `ListResellerBuilders` and `CalculatePlanQuote` behind the current reseller auth/session. Preset and authorized custom builder quotes use published, revision-checked tariff and discount. Existing four-tab navigation remains intact with a contextual «خرید سرویس» action.
- Explicitly no orders, wallet debit/postings, Provider calls, schema/API changes, extra dependencies, or 3B/3C steps. New tests assert read-only behavior, invalid input, stale plans, public versus reseller pricing, and role revocation.
- **Source verification:** [CI run #41111514033](https://github.com/DenaSon/skynet/actions/runs/41111514033) passed PHP 8.4 Pest, PHPStan, Pint, MySQL migration/rollback and change detection. Final PR diff reviewed: presenter, Blade view, scoped Pest tests and this document only. **Live Telegram quote smoke remains pending** after merge/deploy; source CI does not establish client acceptance. Roll back by reverting PR #306; emergency disable via existing `DENAGATE_LIVE_ENABLED` gate.

## 4. Non-negotiable invariants for all future Epics

- **One modular monolith:** use Laravel 13, PHP 8.4+, Livewire 4, existing Tailwind/Vite/Alpine, Nutgram, MySQL and existing Application Actions. No second back-end, finance store, payment/provider proxy or default new dependencies.
- **Scope First:** preserve existing Admin/Sales bots, pricing, bank review, renewals, referrals, identity, API routes and all legacy callbacks. No global refactor for a UI feature.
- **Auth on every mutation:** derive user ID from authorized session, not Livewire public properties/client-supplied IDs; recheck current reseller role/activity and ownership, session identifier, CSRF and idempotency.
- **Financial truth:** debit, settlement, recharge, refunds and notifications only via existing ledger/transactional Actions; before/after semantics and concurrent duplicate handling require real disposable MySQL/Redis evidence. Never assume PHP mocks cover banking concurrency.
- **Provider truth:** local subscription state is not a live provider result; only owner-bound, bounded and justified RW GET may claim current status. Provider writes occur only through existing guarded queued lifecycle and with explicit authorization.
- **UI:** Persian, minimal Apple-style light theme, Vazirmatn locally served, Dena Teal `#14B8A6`, accessible touch targets, responsive RTL, persistent back/close, clear copy actions and helpful empty states. Never expose internal phase labels, developer notes, raw errors, Provider implementation names or secrets.
- **Deploy:** `master` merge triggers Production deployment. Require reviewed PR, green relevant CI (including PHPStan/Pint, PHP 8.4 and MySQL where meaningful), no destructive migration, operator-approved financial/Provider go-live, and rollback path. Do not change production ENV or BotFather unless expressly authorized.
- **No overstated evidence:** distinguish source merged, CI passed, Deploy observed, real Telegram device test, real payment test and real Provider operation.

## 5. Future PR handoff checklist (copy into PR)

- [ ] Link the **approved** Live phase ID and state exactly what is in/out of scope.
- [ ] Read latest `master` and referenced docs; enumerate reused Actions, guards, configuration and existing callback compatibility.
- [ ] Review wallet/rial↔toman conversion, published discount, plan revisions, owner binding, feature flags and failures for this feature.
- [ ] Implement smallest independently revertible view/action adapter, preserving four existing navigation destinations and customer-friendly Persian text.
- [ ] Add positive/negative tests for two separate resellers, inactive/revoked user, forgery, duplicate submission, exception handling and real MySQL/Provider behavior **where applicable**.
- [ ] Run and record PHP 8.4 full suite, PHPStan, Pint, Vite build for asset changes, MySQL/Redis migration/rollback when applicable; inspect actual PR diff for out-of-scope files.
- [ ] If financial/Provider sensitive: stop before real writes or merge until the operator explicitly authorizes the specific E2E and rollout.
- [ ] After controlled merge and Deploy, request real Telegram iOS/Android/Desktop acceptance (state what was tested), compare totals and retain rollback plan.
- [ ] Update only the necessary Live living document; use status **Done / Tested / Pending / Risks** in operator report.

## 6. Planned decisions / non-goals

**Open decisions (not approved):**
- Priority: confirm LIVE-3A as next read-only increment before implementation.
- Whether a custom reseller builder should appear together with preset plans or follow as a separate UX PR after a preset-plan acceptance test.
- Credit top-up uses the planned existing-bot handoff (5A); browser receipt upload (5B) has been removed from the execution roadmap.
- Whether Telegram Mini App receives a persistent/native menu entry once the primary workflows are stable, without burying the current Sales Bot keyboard.

**Explicit non-goals at this checkpoint:** direct browser OAuth login; ordinary-user/customer Mini App; Admin dashboard port; billing rewrite; bank/card gateway integration; bulk service suspension; automatic Provider health polling; standalone mobile app; broad new UI framework; speculative analytics/notifications.

**Next action:** operator approves **LIVE-3A** → implement only the read-only plan catalogue and quote preview in one PR. **Do not interpret this document as approval of any future money or Provider write.**
