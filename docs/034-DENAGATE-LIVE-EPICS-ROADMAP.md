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

**Final scoped implementation: LIVE-6B release hardening (source/CI release candidate).** Operator requested the last defined LIVE-6B phase and a clean transition to separate UI/UX Polish. LIVE-6A merged via [PR #314](https://github.com/DenaSon/skynet/pull/314) with post-merge CI success; real 6A multi-device UX acceptance has not been explicitly recorded. No financial/Provider E2E, production ENV or real payment is authorized by this code-hardening phase. After its PR and post-merge checks pass, all **approved Mini App development phases** may be marked source-complete; operational and real-device acceptance remain separate gates.

| Epic / phase | User-facing outcome | Scope / existing Actions | Explicitly out of scope | Release gate / dependency | State |
| --- | --- | --- | --- | --- | --- |
| **EPIC LIVE-03 / 3A — Plans & quote** | «خرید سرویس» → available plans → duration/traffic where supported → confirmed reseller price preview | `ListQuotedPresets`, `ListResellerBuilders`, `ListVisiblePlans`, `CalculatePlanQuote`; reuse published tariff/discount and plan validation; view-only Livewire wizard, clear back/cancel | Creating `sales_orders`, wallet debit, bank receipt, Provider calls, new price formulas, extra roles | Operator approval for 3A; tests for reseller role, invalid/stale plan, custom bounds, unchanged public and reseller discounts, 0 mutations; real Telegram UX | **MERGED / operator Telegram accepted — [#306](https://github.com/DenaSon/skynet/pull/306), runtime fix [#307](https://github.com/DenaSon/skynet/pull/307)** |
| **LIVE-3B — Pending order** | Explicitly confirm quote and customer identifier; see one pending order | `CreatePendingPlanOrder` with fresh quote/price/Squad revalidation, immutable snapshot and per-intent idempotency; reuse existing customer-label workflow only if its contract supports web cleanly | Money deduction, fulfillment, new customer phone requirement, implicit order creation while browsing | 3A accepted; explicit approval to write pending orders; duplicate submit/concurrency, spoofed buyer, changed plan, cancellation tests | **MERGED [#308](https://github.com/DenaSon/skynet/pull/308); operator accepted 3B Telegram flow** |
| **LIVE-3C — Pay with credit** | Review final cost/balance → explicit one-tap pay → transparent pending/fulfilled/failure status | Existing `SettleWalletOrder` and existing payment/fulfillment outbox + feature gates; existing owner/ledger/idempotency/insufficient-balance rules; no parallel debit code | Direct Provider CREATE/PATCH in Livewire; new bank settlement flow, silent retries, double charging, unapproved ENV flag changes | **Separate sensitive financial approval**; MySQL transaction/concurrency tests, double-submit and crash recovery, real bank/wallet + Provider E2E with controlled disposable order and operator go/no-go | **MERGED [#309](https://github.com/DenaSon/skynet/pull/309); real wallet/Provider E2E still independently gated** |
| **EPIC LIVE-04 / 4A — Service detail** | Tap an owned service → plan, duration, verified usage/expiry where available | Owner-verified `GetMySubscription`, optional **on-demand** bounded `GetSubscriptionUsage` / existing `ReadResellerServiceHealth` GET; truthful unknown/expired labeling | Assuming local active = live healthy; background polling, bulk provider reads, exposing raw provider IDs | 2B baseline; owner/foreign ID, error, expired and bounded GET tests; separate live Provider read/visual check | **MERGED [#310](https://github.com/DenaSon/skynet/pull/310); operator accepted real Telegram service detail + on-demand read** |
| **LIVE-4B — Connection and customer name** | Owner chooses verified copyable subscription link/QR and may edit customer label | `GetSubscriptionLink` with existing owner/provider validation; `ManageResellerServiceLabels` owner-scoped save; copy and easy back path | Automatic disclosure on the list/dashboard, exposing credentials to unauthorized browser/actor, parallel QR/credentials store, new username/phone schema | 4A accepted; **separate PR for label write** if useful; URL secrecy/XSS/RBAC/revocation tests | **MERGED [#311](https://github.com/DenaSon/skynet/pull/311); operator confirmed real Telegram copy/QR and label** |
| **LIVE-4C — Renewal in the same service** | Renew selected eligible subscription, see exact calculated price, confirm credit payment | `PrepareRenewalDraft`, `CreatePendingRenewalOrder`, existing `SettleWalletOrder` and queued `FulfillPaidRenewalOrder` lifecycle; preserve old source mapping | A new subscription/CREATE for renewal, quota reset without contract, unguarded Provider PATCH, uncertain auto-retry | 3C and 4A accepted; **separate renewal/Provider-write approval**, existing `SALES_RENEWAL_ENABLED` and Provider gate enforcement; real controlled renewal and rollback verification | LIVE-4C source implementation; controlled real financial/Provider acceptance separately gated |
| **EPIC LIVE-05 / 5A — Credit top-up handoff** | Clear increase-credit action leading to existing Sales Bot top-up flow | Read `ViewWallet`; link/navigate to **existing** private bot flow without changing ledger or receipt handling | Independent web receipt upload, new banking APIs, auto-credit, new wallet table | Prioritize after checkout need is proven; validate real Telegram return/navigation and missing bot access | **MERGED [#313](https://github.com/DenaSon/skynet/pull/313); operator accepted real Telegram handoff** |
| **LIVE-5B — Web receipt upload (removed)** | Not in the execution roadmap | No implementation | Browser receipt upload or new payment path | Explicitly removed by operator on 2026-10-30; reconsideration would require an entirely separate scope decision | **REMOVED — DO NOT BUILD** |
| **EPIC LIVE-06 / 6A — Entry & navigation polish** | Discoverable authorized Mini App entry in Sales Bot plus compact contextual return flows | Reuse Sales Bot routes, existing `/live`, role checks, preserve persistent keyboard priorities and Telegram Rich callbacks | Replacing Admin Bot or full Sales menu, a second public member app, mandatory BotFather-wide menu change | 3A–4A stable; separate bot UX approval, test old menus/forged callbacks and actual iOS/Android/Desktop | LIVE-6A code and CI; real Telegram mobile/desktop navigation acceptance pending |
| **LIVE-6B — Operations & release hardening** | Reliable user-visible errors, performance, privacy and rollback | Bounded Action calls, server-only error logging (no secrets), accessibility/safe-area/reduced-motion, Vite/font checks, controlled release notes and runbook | New monitoring stack, arbitrary analytics/tracking, always-on Provider health poll | Continuous QA; keep changes small and independent; operator smoke and rollback evidence | LIVE-6B implementation PR; source/CI and operator live smoke tracked separately |

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

## LIVE-3B — Pending order implementation checkpoint (2026-10-30)

**Approval:** Operator explicitly requested phase 3B after confirming working real Telegram navigation in LIVE-3A (bundled Livewire JS). No authorization was given for wallet debit, payment confirmation, provider writes, receipt uploads, new APIs or migrations.

**Source scope:**
- Continue existing `Dashboard` shop/quote step; after seeing the published discounted price the reseller supplies a **customer username** (not a required phone) and deliberately clicks «تأیید و ثبت سفارش».
- Only the authenticated reseller session supplies buyer ID. The preview is bound to the exact server-calculated `PlanPriceQuote` fields by a Livewire locked hash; changing plan, duration, volume, published revision, price or discount requires a new preview.
- A server-generated locked 128-bit idempotency key is stable during the one confirmation intent and reset only by choosing another plan, leaving the flow or explicit re-preview. Browser cannot replace it through Livewire updates.
- Existing `CreatePendingPlanOrder` revalidates price, published plan revision and verified Squad under database locks and writes exactly one immutable `pending_payment` order. `ManageResellerServiceLabels::savePending` saves the customer username. The small `RegisterPendingResellerOrder` Application composition runs both existing Actions inside **one SQL transaction**, preventing an order without valid customer details and denying a different name on a replay.
- Display the owned order number, customer name, immutable total and «در انتظار پرداخت»; do not debit wallet, settle order, enqueue fulfillment, contact Provider, or create a new public web payment endpoint. The existing Sales Bot's `سفارش‌های من` is the only payment handoff, and LIVE-3C remains explicitly gated.

**Verification gate:** PHP 8.4 Pest, Pint, PHPStan, disposable MySQL/Redis CI; valid preset and builder quotes, no money or Provider calls, missing/invalid username rollback, duplicate intents, cross-buyer isolation, stale tariff/revision, missing Squad, role revocation, back/cancel, and successful real Telegram Mini App receipt. Source CI does NOT establish true parallel production concurrency or real Telegram E2E.

**Rollback:** Revert only this PR; preserve already persisted orders, labels, and existing bot flows. Never run a destructive migration or delete financial/order data for rollback.


## LIVE-3C — Wallet checkout source checkpoint (2026-10-30)

**Operator:** accepted 3B real Telegram flow and requested the next phase, LIVE-3C. This authorizes a scoped code PR with automated tests; it does **not** authorize an actual financial transaction, real Provider write, changed Production flags or automatic live test.

**Implementation boundary:**
- Continue the existing Mini App's paid/unpaid order detail. A bounded owner-scoped list of the ten recent **new-plan purchase** orders survives refresh, using additive `OrderReader::recentNewPlans` / `findNewPlan` checks. Renewals are excluded even if an order ID is manually submitted; renewal payment remains part of LIVE-4C, not 3C. No untrusted buyer ID.
- Only explicitly selected *owned, pending* orders have a 5-minute server-backed wallet confirmation. Show the immutable order amount, real ledger-backed available balance and projected balance. The final button explicitly states that credit will be debited. Cancel, navigation, expiry or a changed balance/order requires another approval.
- Final confirmation calls **only** `SettleWalletOrder::handle(SubscriptionViewer::web(), orderId)`. Existing Action guards the feature flag, session owner, buyer status, reseller label, verified current plan-to-Squad binding, automatic fulfillment and Telegram-notification readiness, wallet journal, insufficient funds, bank receipt conflicts, paid retry integrity and a unique durable fulfillment outbox in a locked SQL transaction. Provider CREATE/PATCH is **not** invoked by the web presenter.
- Verified `paid` (debit committed, pending fulfillment) must not appear as a remotely active service. `fulfilled` is a local historical status, not a live health assertion. Real provisioning requires queued worker and Production checks independently.
- No new payment engine, schema changes, endpoint, env switch, bank receipt flow, payment token in URL, new dependency or Web 5B upload.

**Verification before release:** PHP 8.4/Pest, PHPStan, Pint, MySQL migration/rollback and focused wallet tests (insufficient balance, changed balance, expiry/cancel, role and owner revocation, bad Squad, disabled feature/Provider, one journal+two balanced postings+one outbox on success, no HTTP Provider calls). Concurrency is primarily enforced by existing DB locks and unique keys; a genuine production MySQL concurrency acceptance and disposable real Provider E2E are **not** implied by SQLite and fake-queue unit tests.

**Live acceptance separate:** After deploy, for first financial smoke use a deliberately controlled, disposable reseller order only with the operator's explicit payment/Provider authorization; verify wallet ledger pre/post, fulfillment event state, worker health and genuine Provider activation, then return to UI for status. If those approvals are absent, test only the preview and cancellation paths.

**Rollback:** revert this Mini App code PR, never undo a committed wallet journal or provider operation; preserve existing bot payment capability and immutable financial evidence.

## LIVE-4A — Owned service detail and explicit real usage (2026-10-31)

**Approval:** The operator requested the next independent phase after 3C, with clean closeout. This authorizes **service detail + read-only on-demand provider GET** only; LIVE-4B credential link/QR and customer-name write, LIVE-4C renewal and direct Provider writes are separately gated.

**Implementation scope:**
- In the existing Services section, each row exposes «مشاهده جزئیات». `openService(orderId)` checks the exact selected ID against the **current six-item owner-authorized `ListMyServices` page** and reuses `GetMySubscription(SubscriptionViewer::web(), orderId)` to display safe immutable purchased plan, period, volume, date and local recorded status. Foreign and unlinked orders are never selectable; unauthorized actors are denied. Preserve the current cursor on returning to the list.
- A separate user-initiated «بررسی وضعیت» invokes **one existing `GetSubscriptionUsage` GET** per click, only for a locally active linked service. The existing `ActiveSubscriptionAccess` checks owner, exact remote identity and reauthorizes after the network round trip. No Provider GET occurs during list rendering, detail opening, navigation or background/live polling.
- Only a safe projection of remote status (active/expired/disabled/exhausted/limited/unknown), actual used/remaining bytes and expiry is displayed; unknown/provider outage never silently reuses a stale success or implies that a locally active record is remotely online. Provider username, ID, raw body, subscription URL or secrets never enter Livewire public state, HTML, logs or error messages.
- Keep old navigation and section state. No migrations, new packages, ENV toggles, wallet journals, payment, fulfillment, renewal, provider POST/PATCH or new web API endpoints.

**Verification:** PHP 8.4/Pest, PHPStan, Pint and current MySQL rollback CI. New tests exercise owner/foreign ID, unlinked paid/pending orders, revoked roles, page cursors, no default network calls, precisely one owner-checked GET on tap, expired and unlimited states, remote failure clearing stale usage, no private credentials, zero financial/provider mutations. Mocked HTTP proves request shape only, **not** real Remnawave health or Telegram rendering. Real acceptance: operator opens an owned service in Telegram, checks the formatted detail, performs a single GET and confirms returned status/quota/expiry against the live Provider. No real update operations required.

**Rollback:** revert this 4A code-only PR; preserve all linked orders, financial records and Provider state. Failure to read remote usage should only show an unknown/retry message while the owned local detail remains accessible.


## LIVE-4B — Explicit private subscription link/QR and customer name (2026-10-31)

**Approval:** Operator confirmed LIVE-4A real Telegram testing passed and requested LIVE-4B. The phase covers only explicit retrieval/copy of the owned working connection and an optional locally stored customer name; 4C renewal, Provider state changes and bank/payment workflows are excluded.

**Read-only credential reveal:**
- New «نمایش لینک و QR» on an already selected authorized service, **not** the list, overview, notification, report, public URL or automatic render. It calls the existing `GetSubscriptionLink(SubscriptionViewer::web(), selectedOrderId)`, which verifies authenticated owner, current linked active state, current remote identity and connection usability before and after one Provider GET.
- Generate a real PNG QR in memory through the **already installed** `SubscriptionQrPng` / `endroid/qr-code`; it contains the same validated HTTPS subscription URL, not a new stored credential. Browser-only image data URI and readonly copyable field appear only after explicit successful request. The secret is temporarily present in the owner-authorized Livewire component snapshot while the detail is open, since copying/QR requires browser access. `#[Locked]` props forbid client-side injection; back, switching tabs/services and «پنهان کردن» reset them. Do not emit credentials in errors, logs, location/history, or external QR APIs.
- Copy uses the existing bundled Alpine + browser clipboard with a selection fallback; no external SDK, endpoint, CDN, QR DB column or new ENV. A Provider failure, disabled/expired/exhausted user, remote identity mismatch or revoked account yields no connection secret; show a generic retry message. Static local status never substitutes for remote usability checks.

**Customer label write:**
- «ویرایش نام» enters a server-controlled edit mode and loads only the authorized label via `ManageResellerServiceLabels::get`. «ذخیره نام» uses exactly its existing locked SQL/owner-validated `save`; whitespace input removes optional name without deleting phone or service. The Action owns validation, normalization, maximum 60 characters and idempotent storage; do not use direct SQL, change customer username in Remnawave, or add schema.
- Clear forms on navigation and cancel. Display labels escaped in HTML. No automatic Provider read or financial write for editing.

**Regression & security QA:** PHPUnit/Pest on PHP 8.4, PHPStan, Pint, MySQL migration/rollback, source diff. Focused tests: no request/credential until explicit reveal; only one validated GET, exact credential and PNG QR, hide/back clears; remote rejected read immediately clears prior link; invalid/foreign/unlinked/revoked actor no exposure; optional label save/clear/XSS/validation and no Provider/billing operations. Source mocks do **not** replace actual Telegram iOS/Android clipboard and QR scanning tests. No purchase, wallet, provider POST/PATCH, renewal, migration or menu changes.

**Rollback:** revert 4B presenter/templates/tests/docs changes only; local labels already edited through the existing stable Action remain customer-owned. No QR or connection URL persistence in a new database or system outside the authenticated browser.

## LIVE-4C — Same-subscription renewal with existing wallet settlement (2026-10-31)

**Approval/scope:** After LIVE-4B real Telegram testing passed, operator explicitly requested 4C implementation. This PR introduces only an existing-service renewal presenter, no new renewal economics, separate money code, provider HTTP in Livewire, schema or ENV changes. Real money/Provider acceptance requires separate, explicit operator go/no-go.

**User path:** Owned service detail → «بررسی قیمت تمدید» (existing `PrepareRenewalDraft`: strict owner/active provider identity, expected Squad, no-reset quota, immutable original volume/duration, current published reseller price) → confirm price and create the pending renewal order (existing `CreatePendingRenewalOrder`: feature flag, repeated provider GET, DB-lock, existing pending/paid reuse, original subscription mapping, no CREATE) → review immutable order amount and verified available credit → five-minute server-cached single-use explicit confirmation → `SettleWalletOrder(SubscriptionViewer::web(), orderId)`. The existing Action alone posts double-entry journal, paid status, exactly one `:renew` fulfillment intent and existing notifications atomically; only existing worker may later execute Provider PATCH, respecting its rollout gates. Payments must never be presented as a completed remote renewal. Show paid/pending/fulfilled/needs_review separately.

**Access/integrity boundaries:** `OrderReader::latestRenewalForSource(buyerId, sourceId)` is tenant/owner-scoped through BOTH source and renewal Sales order and never accepts a browser-supplied renewal order ID. Server-owned `#[Locked]` preview/confirmation amounts prevent injected price/IDs. Preview/registration rechecks published price and records the latest order separately from the 3C new-plan checkout. Before debit, confirm unchanged order amount, pending status, available credit and single-use five-minute token; existing settlement Action rechecks feature gates, source/subscription mapping, Squad, payment conflicts, locked wallet, unique outbox and idempotency. No second subscription or new connection URL is generated. Existing 3C new-plan path stays renewal-excluding.

**Failure behavior:** Price change voids old quote; incomplete/disabled renewal and provider outage cannot produce a payable order; insufficient/revised balance, cancelled/expired token and stolen/unlinked source must not debit; ambiguous settlement presents a safe status-check warning (not «try payment again»). Open/reload same source shows the latest persisted renewal order without another Provider GET. Payable renewal orders are not cancelled or silently deleted by UI back; only confirmation tokens are cleared. Feature flags `sales.renewal_enabled`, `sales.wallet_settlement_enabled`, existing Provider-write, Squad/notification/fulfillment readiness remain authoritative.

**Verification:** Full PHP 8.4/Pest, PHPStan/Pint, MySQL migration/rollback. Focused tests for real Action contracts, zero charge at preview, duplicate order protection, price drift, wallet insufficiency/change, cancelled/expired single-use token, disabled gates, mismatched role/ownership, one journal/settlement/renew outbox and zero new subscriptions/links/provider writes in HTTP fake tests. SQLite/fake HTTP do **not** establish production concurrency or actual RW PATCH effects. No controlled Production wallet debits, Provider PATCHes or changes to feature flags are performed in this phase's code review.

**Operator acceptance:** After deployment, verify read-only quote and price for a known owned subscription, no unexpected order/charge, and navigation persistence. The actual disposable paid renewal E2E (one debit, same Provider ID, expiration/quota, worker reconciliation and notifications) must be explicitly approved and supervised separately. Revert 4C UI code for rollback; **never roll back a settled journal or automatically retry an uncertain Provider update**.

## LIVE-5A — Safe wallet top-up handoff to the existing Sales Bot (2026-10-31)

**Approval:** Operator requested the next roadmap phase after 4C Merge. Only UI/deep-link handoff and on-demand wallet read are in scope. No new receipt upload, credit mutation, Provider operation, schema, environment switch, backend topup creation or change to the existing Billing Actions.

**Existing flow used:** The Sales Bot already supports a private `/start {payload}` route, `WalletTopupUi::start` with an authenticated private Telegram actor, `sales:wallet:topup` callbacks, existing unfinished-topup recovery, explicit amount selection, receipt photo and supervised bank approval. We do not add new top-up machinery. On an actual active reseller's **private Sales** `/start live_topup` only, the existing Sales UI is invoked directly after normal actor checks and current input cleanup. Guest/member/revoked users, unrelated `/start` parameters, ordinary referral onboarding and existing bot menus remain unchanged. The payload is only a navigational hint, **not** authority or a payment request.

**Mini App handoff:** On the existing `credit` page, show the actual ledger-backed balance plus one clear «➕ افزایش اعتبار در بات» link to `https://t.me/{configured_sales_username}?start=live_topup`. Sanitize `telegram.sales.bot_username` with exact Telegram username syntax and require current Sales + top-up feature gates; never accept a browser-supplied username, derive it from a token, hardcode an unverified destination, create redirects or expose secrets in the URL. If unavailable, render readable inline guidance rather than an invalid link. Reuse the official Telegram WebApp JS already on the Live entry to call `openTelegramLink` on user click inside Telegram, with native HTTPS anchor as a browser fallback. This does not auto-close the Mini App; Telegram may require users to press Start for the deep link. Do not auto-create a pending top-up or auto-credit on navigation.

**Return/refresh:** The existing Live credit view offers an explicit `$refresh` button to reread `ViewWallet` after the user returns. The balance changes only after the existing supervised approval posts to the wallet journal. No automatic polling, web receipt flow, browser-side payment handler or broad bot menu changes. Entry, auth, role revalidation, Reports/Shop/Services and existing callback IDs stay unchanged.

**Verification:** PHP 8.4/Pest and full CI (including PHPStan, Pint, MySQL migrations/rollback), real `ViewWallet` data after an independently funded account, link sanitization/missing/disabled cases, forged deep links and role revocation, no implicit orders/top-ups/ledger postings, existing Sales /start/referrals regression, and fixed redirect destination. Fake Bot and browser DOM tests cannot confirm Telegram-client navigation across platforms. Operator to tap handoff on real iOS/Android Telegram, enter existing top-up flow, return and explicitly refresh; do not send real bank payments or approve real top-ups during this source code verification.

**Rollback:** Revert only 5A's credit-page link, layout SDK inclusion and exact Sales /start payload branch; preserve all other callbacks, existing credit and historical topups. Existing topups/ledger state must never be rolled back.

## LIVE-6A — Discoverable private reseller Mini App and clean return (2026-10-31)

**Approval:** The operator accepted real LIVE-5A and authorized **only** LIVE-6A: discoverability and navigation. LIVE-6B operational hardening, new notifications, financial/provider writes, Admin interfaces, BotFather-wide native menu mutations and new end-user web logins remain out of scope.

**Sales Bot entry (existing architecture):**
- The existing authorized reseller `SalesHomeUi::start` home keyboard and `SalesHomeUi::account` account keyboard add one compact inline `🚀 پنل نمایندگی` callback **only** for a current role `reseller` when `denagate_live.enabled=true`. Existing buy, credit, service, reseller builder, reports, support, reply keyboard, Rich/native fallback and native Telegram command menu stay intact. Rich home serializes this callback through the pre-existing Rich button builder rather than introducing new rendering code.
- One exact `sales:live` Sales-only callback routes to the **existing** `SalesLiveEntry::show` Action. The Action rechecks private chat, Telegram identity, current role and deployment gate, acknowledges the callback and sends its existing private `WebAppInfo` button pointing to the constant `denagate_live.url`. This callback is a navigation hint, never a privilege grant. Stale/forged callback, member and disabled feature get the original safe denial. Existing explicit `/live` remains supported and no BotFather global menu change is made.
- This is an additive shortcut; never alter referral, trial, wallet/receipt, purchase or Admin handler registrations.

**Mini App return:**
- Use the already-validated `telegram.sales.bot_username` in the existing Livewire read presenter to create a separate plain bot chat destination `https://t.me/{username}`. Respect `telegram.sales.enabled` and strict bot-username allowlist; never accept query parameters, arbitrary redirects or forged user destinations. Preserve the existing `?start=live_topup` deep link on the credit page only when top-ups are enabled.
- Render a small `بازگشت به بات فروش` link below the overview purchase CTA and in a light footer under context screens. On a real Telegram client, `openTelegramLink` navigates to the bot; a normal HTTPS anchor is available outside Telegram. The return link does not log out, silently cancel an order, mutate the wallet, write Provider data or change persistence. No extra bottom navigation slot.
- With unavailable/invalid bot settings, omit the link entirely. No extra package, config, ENV, DB migration, ledger table or polling.

**Acceptance/review:** Full PHP 8.4/Pest, PHPStan/Pint, MySQL migration/rollback + diff security review. Targeted tests: reseller home/account discoverability in Rich UI, existing callbacks preserved, exact `sales:live` route and callback ACK, member/revocation/disabled gate denial, no finance/provisioning writes, safe link omission for bad configuration, no unguarded top-up deep links, Mini App reseller RBAC. Fake Telegram tests do not prove native iOS/Android/Desktop WebApp launch. Operator should tap home/account button and open an authenticated reseller Mini App, then return to the Sales Bot from overview, credit and service screens; confirm home, credit, order and report screens still work. No real payment/Provider test under 6A.

**Rollback:** Revert 6A UI/route/callback/presenter changes independently. The existing `/live` entry, Mini App session, wallet and subscription data remain intact, and no financial reversal is needed.

## LIVE-6B — Final source hardening & release/rollback checklist (2026-10-31)

**Operator scope:** Final existing roadmap phase before a separately approved UI/UX Polish. This is a small, additive Laravel/web-only reliability change, **not** new product functionality, checkout behavior, permission, migration, Provider API, animation library, background polling, analytics or monitoring stack. Existing Sales Bot, Admin Bot, wallet/renewal settlement and protected API boundaries are preserved.

**Audited baseline:** LIVE-6A source and post-merge CI on `master` via [PR #314](https://github.com/DenaSon/skynet/pull/314) (1,330 tests, 8,715 assertions on the post-merge run). Existing Live dashboard fetches scoped read Actions only for visible section; services list is 6 per page, reports use 7/30-day windows, service usage/credential remote requests remain explicit user actions and no always-on Provider polling was introduced. Explicit wallet/renewal confirmation continues solely through the original Actions and server-backed one-use snapshots. No additional financial operation is authorized in 6B.

### Minimal hardening changes

1. **Privacy headers:** Existing Live-host `EnsureLiveEnabled` sets `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer` and `X-Content-Type-Options: nosniff` on its responses after running the next middleware. This covers the HTTPS login entry, authenticated dashboard and host-scoped redirects/errors in the route group; Laravel session middleware may append other cache directives, so automated acceptance asserts the required `no-store` + `private` semantics rather than whole-header string equality. Livewire update endpoint cache behavior outside that route group remains governed by its own framework/middleware (do not claim it was covered by the host middleware). Preserve the Runflare HTTPS scheme override and its `finally` reset; no trust-all proxies or CSP/frame blocking that would break Telegram Web.
2. **Privacy-safe operational diagnosis:** On Dashboard read, explicit service-usage and uncertain wallet/renewal payment failures, replace `report($exception)` (which can include upstream credential URLs/responses in exception message or stack) with a single bounded warning `denagate_live.operation_failed` containing only an allowlisted constant operation key and the PHP exception class. The UI continues to show its existing generic failure/uncertain-payment language. Do not include `Throwable`, request, user/order identifier, URL, secret, receipt data, payload or exception message in logs. No retry of uncertain debit/Provider mutation.
3. **Accessibility:** Keep the local Vazirmatn and the existing Livewire+Vite bundle. Respect `prefers-reduced-motion` in the Live-only layout, and remove the full-page `aria-live` which could announce all private dashboard content on each refresh; preserve the bounded loading `role=status` and existing alert statuses and keyboard focus affordances. Visual layout and main navigation remain unchanged for independent Polish.

### Release acceptance matrix

| Area | Automated evidence required | Operator/client evidence required |
| --- | --- | --- |
| Security | Current reseller RBAC, Telegram HMAC, revoked-role and private session regression suites; exact host-only no-store/no-referrer headers; unchanged Admin/Sales isolation | Open fresh `/live` in Telegram; revoked user denied |
| Privacy | Secret-bearing exception isn't in HTML/logs; connection URL/QR are explicit only; no unapproved network fetch on service list/detail; no external redirects | View/hide a test subscription's link/QR and return to the bot |
| Reliability | One-use wallet confirmation, stale/price-change/insufficient credit tests; no regression in real Actions; full PHP 8.4 Pest, PHPStan, Pint, MySQL migrate/rollback | Separately authorized disposable financial/Provider E2E **still OPEN** (not covered by this phase) |
| Accessibility | Reduced-motion CSS, local font licensing/existence, bounded loading status, accessible focus, responsive bottom safe-area, CI-built Livewire/Vite JavaScript | Check real Telegram iOS/Android/Desktop layout, back buttons, keyboard, safe area, contrast and nonbreaking text |
| Rollback | No schema/package/ENV changes; source diff scoped; prior `/live` remains | Operator can set `DENAGATE_LIVE_ENABLED=false` without touching Admin/Sales Bot webhooks |

**Operator preflight and post-deploy smoke, no live payments:**
- Confirm [PR for 6B] is merged to the current `master`; source CI + **post-merge** PHP 8.4/Pint/PHPStan/MySQL/Vite are successful. Distinguish this from the hosting platform's actual deployed revision, which must be checked separately.
- In private Telegram Sales Bot, an active reseller enters through `/live` and the reseller-only inline entry from home/account. A member/revoked reseller cannot access the panel. Confirm no Admin Bot menu or callbacks change.
- Open the Mini App on Telegram mobile and desktop. Switch overview, credit, service list/details, reports 7/30, and shop; confirm user-specific content and back/return links are correct; test stale data refresh and empty list; only explicit button reveals QR or refreshes Provider usage. Do **not** use a real wallet charge/renewal/provider write as part of UI smoke.
- Confirm publicly served `https://live.denagate.ir/` and authorized dashboard are HTTPS, have `Cache-Control: no-store, private` and `Referrer-Policy: no-referrer`; script/font assets load; no mixed-content error. Observe controlled browser Network/Console only with test data, do not copy private link or raw signed `initData` into tickets/logs.
- If a read fails, verify the UI displays a safe retry message and operator logs contain the `denagate_live.operation_failed` key with class/operation only. If ambiguous payment state exists, review persisted order and wallet **before** any retrial; do not infer success from a debit or manually retry Provider PATCH.

**Rollback:** Immediately disable `DENAGATE_LIVE_ENABLED` through operator-approved deployment procedure and clear config cache/redeploy as needed; preserve existing bot webhooks, Sales orders, provider subscriptions, journals, customer roles and notifications. If only 6B causes regression, revert its isolated PR; headers and reduced-motion styling are additive and require no DB rollback. No financial/provider data may be reversed by this source rollback.

**Release status semantics:** Once this PR is merged and post-merge CI green, report **Mini App planned development/source phases complete** and begin a **new, separate UI/UX Polish review**. Do **not** declare full commercial/Production/real-device/renewal/provider sign-off unless actual smoke and controlled financial/provider acceptance evidence have been separately recorded. The deleted LIVE-5B web receipt phase remains excluded.

## Post-release feature — Reseller service pause/resume in Mini App (2026-10-31)

**Explicit approval:** Operator agreed to a small separate feature before UI/UX Polish. This is *not* a retroactive LIVE-6B scope expansion and does not alter previous Live phase completion. First UI surface: authenticated reseller Mini App detail only; Sales Bot and Super Admin UX unchanged.

**Reuse, not new architecture:** Reuse the existing `RemnawaveSubscriptionLifecycle::disable/enable/subscription`, persisted `provisioning_operations` and existing asynchronous `ProviderQueueConnection`. New `ManageResellerSubscriptionStatus` is an independent owner-scoped authorization policy; the pre-existing `ManageFulfilledSubscriptionStatus` is **Super Admin only** and remains intact. One bounded `ChangeResellerSubscriptionStatusJob` (tries=1) executes the original lifecycle, no raw Provider URL, id, credential or extra retry. No migration/new package/financial action.

**Authorizations and safety:**
- Independent **database-backed** Spatie setting `reseller_service_status.enabled=false` (also requires `DENAGATE_LIVE_ENABLED` and existing global `REMNAWAVE_WRITES_ENABLED`). Only an active trusted Super Admin in private Admin Bot may manage «مدیریت سرویس توسط نماینده» under Settings via a one-use 3-minute confirmation. Missing/unreadable settings fail closed. **Do not enable in Production based solely on source CI**; operator must explicitly authorize real Provider update/rollback/smoke. With the setting OFF, no new UI/action/job may mutate a service. Existing Admin status permissions are unaffected.
- Current active reseller + exact `sales_orders.buyer_user_id`, fulfilled managed order (`plan_id`), completed fulfillment intent, valid local `provisioning_order_links` and `sales-order:{id}` subscription with remote mapping/state active are mandatory. These checks are repeated after a fresh identity-verified Provider GET and again inside the worker before the PATCH. No remote ID from client. Only `ACTIVE` → `DISABLED`, or `DISABLED` → `ACTIVE`, while not expired/exhausted; changes to time/volume are forbidden.
- Resume additionally requires that the **latest completed local lifecycle status operation** on that subscription is `disable` and its stored idempotency key embeds **this same buyer ID and order ID** from this feature. Admin `mvp04` status edits, an unrelated lifecycle update, unavailable reads, uncertain `reserved/needs_review` and arbitrary remote disable cannot pass this local provenance check. An out-of-band change directly in Remnawave not passing through DenaGate cannot be conclusively attributed by local history; this remains a documented limitation, so operator must coordinate external administrative locks and may leave the reseller feature disabled until an authoritative external policy is available.
- Mini App status buttons appear only after the reseller explicitly retrieves current Provider usage. A new 3-minute server-side confirmation is bound to owner/order/operation and consumed via `Cache::pull`. Confirmation sends one asynchronous provider job; no remote PATCH is made inside Livewire. Repeated taps cannot reuse the token, and the persisted lifecycle handles duplicate idempotency keys. Any ambiguous PATCH becomes `needs_review`; no blind retries. The UI displays request queued (never claims actual success) and requires a later explicit status refresh.
- Actual expiry/traffic countdown continues while a service is disabled. No extra wallet debit, renewal, fresh subscription, quota reset, link rotation, notification flood or always-on polling. Keep provider-write and owner/logging privacy guardrails.

**Verification matrix:** PHP 8.4 full suite; PHPStan; Pint; MySQL migrations/rollback; existing Vite/Livewire build; focused tests: default-off gate; valid reseller-only two-step flow; one queue event and no HTTP PATCH on Livewire; real HTTP-fake Provider disable/enable with unchanged resource tuple and single idempotent operation per request; unknown/admin/foreign/revoked denial; ambiguous PATCH `needs_review` and no retry; no unintended billing writes. These fake-HTTP assertions are **not** a live Provider verification.

**Operator release / rollback:** Once code and post-merge CI pass, retain feature flag OFF until a separate real staging/safe-sandbox acceptance: confirm exact owned subscription, private role/owner checks, actual queue worker, controlled disable+re-enable, unchanged quota/expiration, administrative block and recovery from failed PATCH. To disable without affecting existing Sales/Admin Bot operations: select «غیرفعال» under Super Admin → Settings → «مدیریت سرویس توسط نماینده» and confirm; no redeploy/config refresh required. If the Settings DB is unavailable, reseller writes fail closed. Never turn off the shared `REMNAWAVE_WRITES_ENABLED` globally just to roll back this feature. No database cleanup is necessary; keep `provisioning_operations` audit trail and unresolved entries for review. After this independent feature, resume a separate UI/UX Polish scope.
