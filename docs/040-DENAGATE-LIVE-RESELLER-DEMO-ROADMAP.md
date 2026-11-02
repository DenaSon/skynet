# DenaGate Live — Reseller Demo Mini App (approved product/architecture plan)

> **Decision date:** 2026-11-02. **Status:** Plan and documentation approved; **implementation NOT started**.
> **Scope authorization:** Documentation only in this PR. Each implementation phase requires an explicit follow-up approval, isolated PR, successful checks, and a separate real Telegram acceptance gate.
> **Source of truth:** Latest `master`, `AGENTS.md`, `docs/000-CONTEXT.md`, `docs/005-ROADMAP.md`, `docs/017-TELEGRAM-UX-IDENTITY.md`, `docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md`, `docs/033-DENAGATE-LIVE-MINIAPP.md`, `docs/034-DENAGATE-LIVE-EPICS-ROADMAP.md`, `docs/037-DENAGATE-LIVE-UIUX-POLISH-ROADMAP.md`, and `docs/039-DENAGATE-LIVE-REVIEW-FOLLOWUP.md`. Reinspect source before coding; historical checkpoints are not today's runtime evidence.

## 1. Product decision and goals

Deliver a **separate, interactive, Persian-first, Apple-style, read-only preview** of the DenaGate reseller experience. Ordinary eligible Sales Bot users can inspect the experience **without applying, depositing money, receiving the reseller role, or accessing an existing reseller's information**.

The preview must aid conversion into the **unchanged** Sales Panel request flow, while protecting the 10+ existing active resellers and their historical orders, balances, customers, subscriptions and access. The Demo is a presentation feature, **not** a reseller membership state, role, payment instrument, or simulation of real transactions.

**Approved:** a Sales Bot `👀 مشاهده دموی نمایندگی` entry, a separate host-scoped `https://live.denagate.ir/demo` Mini App, example-only operational metrics and navigation, **live published reseller tariff quotes** from canonical Sales tables, and a clear `خروج از دمو` action.

**Not approved by this plan:** changes to the existing Sales Panel admission threshold or approval procedure, including the discussed 1,000,000-toman first top-up; self-activation/deactivation or role switching; any new `demo` Spatie role; production business state writes; or implementation before the respective phase gate. The existing reseller discount is whatever each **currently published plan price** specifies (often 35%), **not a Demo constant**.

## 2. Current technical baseline / non-negotiable isolation

- Existing production stack: **Laravel 13 / PHP 8.4+, Blade + Livewire 4, Tailwind CSS 4, bundled Alpine, Vite, Vazirmatn, Nutgram Sales Bot**, in the **same modular monolith**. Use existing `live.denagate.ir`, SSL, design tokens and RTL primitives; **no separate service, SPA, dependency or frontend theme**.
- `routes/live.php`: the existing `/dashboard` is protected by `EnsureLiveReseller`. `LiveAuthController::login` and `Dashboard::resellerId` enforce the real `reseller` role and real user ownership. **Do not weaken, reuse as Demo, or conditionally bypass these guards.**
- Existing buyer-aware `ListQuotedPresets` / `CalculatePlanQuote` check `ResolveSalesBuyer::isReseller`; **do not impersonate a real reseller, set a Demo role, or call a privileged checkout route to fabricate reseller quotes**.
- Existing Sales published price records are in `sales_products`, `sales_plans`, `sales_plan_prices`. Actual published discount is `reseller_discount_percent` per active price; price `method`, `revision`, `minimum_total_rials` and other pricing constraints must be respected.
- Demo has its **own route/controller or small Livewire read-only presenter**, with a narrow, dedicated **read-only published reseller catalog projection**. No reuse of the operational Live `Dashboard` class or its order, wallet, service or provider actions. Share *visual primitives* only.
- Demo route is under the existing Live host / enabled gate. No new schema, role, migration, provider config, bot secret, global middleware alteration or generic Settings system. Do not add a new ENV flag merely for Demo. A new adjustable feature toggle or security complexity requires separate approval.

## 3. Access and interaction contract

1. An active, Telegram-bound ordinary Sales Bot member sees `👀 مشاهده دموی نمایندگی` **adjacent to the existing Sales Panel application/status entry**, not as additional persistent-keyboard clutter. Preserve `sales:panel:request`, its wallet minimum and pending-review flow; no change to the current application form, native contact or admin review. Existing resellers continue using `📱 اپلیکیشن` and their live real dashboard.
2. Launch the Demo as a Sales Bot Telegram Web App at the fixed HTTPS Live host/path. A direct external browser load may show an informational landing/error, **not** authenticated Demo prices. Never trust URL query user IDs, frontend role flags, `initDataUnsafe` or unsigned client claims.
3. Reuse the existing Sales Bot Telegram `initData` HMAC validator, five-minute age bound, active Telegram binding and private session/CSRF principles. A narrow Demo entry/session is allowed but must be isolated from real reseller session and **cannot gain `EnsureLiveReseller` rights**. Verify session/auth on all server-side Demo price reads, including Livewire requests; reject stale/tampered/other-bot initData.
4. The user navigates the Demo's five conceptual areas: **نمای کلی**, **سرویس‌ها**, **اعتبار من**, **گزارش‌ها**, and **پلن‌ها**. Preserve the familiar compact Live navigation pattern (four main destinations and contextual plan entry) where practical. Demo actions can navigate example screens but **never submit** checkout, settlement, renewal, status changes, support messages, customer search on real records or provider calls.
5. All operational figures (balances, customer counts, sales reports, service names/statuses, graphs and transaction history) are deterministic, harmless **sample fixtures embedded in presentation code**, unmistakably labeled `نمونه` / `حالت دمو`. Never query real users, wallets, orders, ledger, customer labels, subscription URLs or provider credentials for these sections. Example figures never mix into real reports.
6. Price cards explicitly distinguish **real current published tariffs** from example operational data: `قیمت به‌روز`, `قیمت عمومی`, `تخفیف نمایندگی`, `قیمت نماینده`. Do not promise stock, activation, availability beyond the verified published catalog or a guaranteed future selling margin.
7. Always offer `خروج از دمو`. In Telegram WebView, use `Telegram.WebApp.close()` through the existing SDK; in a normal browser show safe navigation to the **configured and validated Sales Bot** (no arbitrary redirect). Exiting must not change a role, wallet, or another active Live session.
8. If the viewer is ineligible, the Sales Bot/Live host is disabled, the signature fails, or published prices are unavailable, **fail closed** with short Persian recovery guidance. The existing reseller dashboard must remain completely usable and unmodified.

## 4. Live pricing data contract (critical)

**Goal:** Updating a published plan, price or discount in the real Admin/Sales system updates the Demo's next **server-side read** without touching Demo fixture/code. This is *data synchronization*, not automatic mirroring of future UI features or new pricing algorithms.

The read-only catalog must:
- Read **only** current active products, active reseller-visible plans (`public` / `both` / `reseller`) and active, **current-revision** published `sales_plan_prices`. Do not expose inactive/draft/stale or Admin-only data, internal squad UUIDs, provider URLs or hidden configuration.
- Include plan name, kind, mode, traffic, duration and public price, applicable **plan-level** `reseller_discount_percent`, discounted amount and displayed reseller price. Compute in **integer rials** with the same published **fixed/formula** method, half-up discount rounding and `minimum_total_rials` floor as the existing quote engine. Format tomans only at the presenter boundary. **Never hardcode 35% or fixed prices in the Demo.**
- Restrict the initial *live quote* to **published preset plans with complete selectable quantity/duration**; expose custom builder capabilities as clearly labeled explanatory Demo UI only, without invented, supposedly live prices. Real builder quote previews are **out of scope** until explicitly authorized. Never advertise an invalid price for a variable-quantity plan.
- Use a **small bounded, deterministic read projection** and no new public price API. Keep the canonical quote/order/settlement code untouched if possible. If shared pure pricing arithmetic must be extracted, scope it to a tiny independently tested, behavior-preserving change; otherwise verify strict parity with the canonical pricing calculation in regression tests. Do not duplicate a mutable discount policy in UI code.
- Perform **fresh reads on page load/explicit refresh**; do not add persistent price cache or polling in this MVP. Published price removal, revision change or empty catalog must show an honest empty/unavailable state, never stale cached values or fabricated fallback prices.
- Provide visible price freshness wording such as `قیمت‌های منتشرشده در زمان نمایش`; never imply a Demo quote is an accepted order or a price reservation. Actual checkout must independently use the existing authenticated buyer/role-specific pricing and revalidation.

**Security/business caveat:** Showing real reseller prices to prospective members is intentional product disclosure. Only the signed/eligible Demo audience receives the rendered catalog; assess this disclosure in QA. No username or actual reseller account is borrowed to produce it.

## 5. UX content and restrained marketing CTA

- Design: DenaGate Live light/Apple-inspired minimal visual identity, RTL, Vazirmatn, existing `dg-*` primitives, small mobile cards and clear `حالت دمو` marker across screens. No technical terms, developer phase IDs, Provider names, or financial guarantees in user-facing copy.
- Sample dashboard: a few compact example counters; sample services: 3–6 fictitious records; sample credit and reports: obviously fabricated statistics. Reuse existing style primitives, not existing actual data fetching/Livewire actions.
- Plans: published and server-validated real tariff cards with actual configured discount; don't list draft or unpublished offers.
- CTA: `🧩 درخواست نمایندگی` returns to the **existing** request flow; users must still meet its *current* financial and approval policy. Never show the proposed 1,000,000-toman requirement as an effective rule in this Demo rollout.
- Explicit close button `خروج از دمو`; keep back/return navigation intuitive. The original Sales Bot menu, real reseller web app, order confirmation flows and Finance/Admin UI remain unchanged.

## 5A. DEMO-00 — shared visual shell (separately approved 2026-11-02)

**Authorized scope:** Extract the existing real Dashboard's wrapper, desktop sidebar, header, bottom mobile navigation and footer into one reusable **anonymous Blade component** (`resources/views/components/live/shell.blade.php`). The real `dashboard.blade.php` must render unchanged business content inside this shared component. Navigation labels, `showSection` callbacks, four-tab mobile order, colors, logo, Vazirmatn, Tailwind `dg-*` classes, accessibility and safe-area padding remain behaviorally and visually unchanged. Pass a **presentation-only** `demo` prop (default `false`) to distinguish example labeling in the future Demo; this is not an identity or authorization flag.

**Not included in DEMO-00:** New `/demo` route, Demo controller/page, fake data, pricing read, Sales Bot entry, new JS/error handler, auth/session changes, refactoring active Dashboard business logic or any finance/Provider read/write. Keep existing `live.layout`, Vite and CSS source unchanged. Explicitly preserve all current Dashboard functionality, roles, order/payment management, permissions and workflows.

**Acceptance:** Existing reseller HTML navigation remains renderable on mobile/desktop and Livewire buttons still work. New component can be rendered in a small isolated **test fixture** with `demo=true`, displaying a visible `حالت دمو` marker and no active-reseller badge, without registering a new route. Tests inspect the real rendered shell, compare labels/markup and exercise existing reseller dashboard navigation; run PHP 8.4, PHPStan, Pint and Vite CI. Real Telegram-client acceptance remains separate.

**Rollback:** Revert the independent DEMO-00 PR; no schema, config, Data/Provider, public-route or business-state rollback needed. DEMO-01 may then adopt the common shell under its separate approval.

## 6. Phase boundaries and acceptance gates

| Phase | Independently reviewable deliverable | Explicitly excluded | Gate |
| --- | --- | --- | --- |
| **DEMO-00 — shared visual shell** | Extract current sidebar/header/footer/mobile navigation into one reusable Blade shell; Dashboard preserves its exact visual/behavioral contract; Demo-only label prop is inert until used | New Demo route, runtime logic, auth, pricing, Role, schema, JS/CSS changes | Explicitly approved for implementation 2026-11-02; independent PR, PHP/Livewire tests, Pint/PHPStan, Vite |
| **DEMO-01 — isolated shell** | Host-scoped `/demo`, signed Telegram viewer auth, secure read-only Live/Blade shell, five example-only sections, navigation, exit and Persian recovery; tests for access and no business writes | Published price reads, Bot CTA, operational Dashboard refactor, Role/RBAC changes | Separate operator approval, PR, PHP tests, PHPStan, Pint, Vite build, security review |
| **DEMO-02 — live published tariffs + bot entry** | Bounded read-only reseller preset tariff projection from canonical published records; actual public/discounted reseller prices and consistency tests; Sales Bot Web App entry near reseller-request CTA; navigational `درخواست نمایندگی` action | New payment threshold, auto-approval, real checkout, provider reads/writes, operational data or additional builders | Separate operator approval, PR, all relevant CI/MySQL and fake-Telegram tests |
| **DEMO-03 — client acceptance / controlled closeout** | Actual Telegram iOS and Android WebView checks; review Desktop/browser fallback, authentication and logout, RTL, live price refresh after an authorized test tariff change, and existing reseller regression; document evidenced results | New product features, unsolicited tariff changes, real financial/provider activity | Operator real-client acceptance; after scope-appropriate PR merges and successful CI, only |

**Do not start DEMO-02 merely because DEMO-01 exists; do not equate mock/CI success to Telegram acceptance.** If demo launch visibility requires a safe staged release, operator must approve exposure and verify the actual HTTPS host before making the Bot entry available. No live price edit or production financial action is authorized for QA by this document.

### Mandatory regression matrix

- **Identity:** valid/expired/tampered `initData`, other-bot token, missing Telegram binding, inactive account, role changes while reading, stale/malformed session, unauthorized direct URL and Livewire POST. Demo never invokes existing role assignment or real `Dashboard` authorized routes.
- **Pricing:** fixed/formula; non-35% discount; integer rounding; floor; zero/invalid published values; disabled product/plan/price; revision mismatch; reseller-only audience; new published preset appears; withdrawn preset vanishes; list bounds; no leaked internal identifiers. Verify price parity against canonical quotes using real **test fixture roles only**.
- **Business isolation:** assert no writes to `users` roles, `sales_panel_requests`, `sales_orders`, `billing_*`, `provisioning_*`, fulfillment outbox, notifications or Provider. Auth/session transport may perform normal technical session writes; this is **not** a business mutation.
- **Existing customers:** previously active resellers maintain their real `/dashboard`, wallet, orders, service management, renewal, reports and Bot buttons; their historical business rows remain untouched. Demo does not require migration/backfill and cannot overwrite existing reseller session state.
- **UX/client:** Persian responsive RTL layout, unambiguous Demo/sample labels, accurate real tariff labels, app close and browser fallback, bot back navigation and no dead controls. Physical Telegram checks must be explicitly operator-confirmed.

## DEMO-01 implementation handoff (2026-11-02)

**Source implementation:** isolated GET `/demo` login entry, POST `/demo/auth/telegram` with existing Sales Bot HMAC validator and a member-only Telegram binding, protected GET `/demo/preview`, and POST `/demo/exit`. Session keys `denagate_demo.user_id` and `denagate_demo.telegram_id` are separate from `denagate_live.user_id` and the web guard. Preview reads revalidate the active member and current Telegram binding. `EnsureLiveReseller` and the real Dashboard remain unchanged.

The Demo presents five fixture-only sections through the shared `<x-live.shell>` with Alpine navigation and the existing Live layout/assets. It has **no Livewire operational component**, no real account/ledger/order/Provider reads, and no Demo Bot entry or live price reads. The browser exit is a CSRF-protected POST that clears only Demo keys; `?closed=1` closes the Telegram WebView when available. Browser fallback stays on a safe explanatory entry. The existing real app continues to use Livewire callbacks unchanged.

**Phase distinction:** this milestone is not an authorization to expose a Sales Bot CTA, read published reseller prices, or implement the proposed 1,000,000-toman credit admission rule. DEMO-02 and DEMO-03 require their own approvals. Automated regression/CI success establishes source behavior only; actual Telegram iOS/Android acceptance remains outstanding until operator confirmation.

## 7. Risks, rollback and implementation handoff

| Risk | Prevention and evidence |
| --- | --- |
| Real data leakage | Separate Demo presenter and read surface; fixture-only nonprice areas; narrow authenticated price projection; explicit security tests |
| Accidental transaction or Provider call | No operational `Dashboard` reuse or checkout/service/billing code paths; no mutating callbacks/Livewire actions; assert zero business writes/outbound Provider requests |
| Price drift or inaccurate discounts | Read active current-revision plan prices; integer published calculation with parity tests; empty/fail-closed when invalid |
| Access bypass / public reseller price exposure | Reuse Sales-signed `initData`, active linked member binding, session checks, host restriction, no public unprotected price endpoint; intentionally approved prospect visibility |
| Regression for active resellers | No role, schema, legacy routes or existing Laravel web-session contract modifications; regression suite on actual reseller access |
| UI drift from future Live redesign | Reuse visual primitives; future new features do **not** automatically appear in Demo—review deliberately |
| Deployment / client mismatch | Docs-only PR first, two scoped feature PRs with revert paths, CI plus real-client acceptance before claiming complete |

**Rollback:** revert DEMO-02 to remove published pricing and the Bot launcher; revert DEMO-01 to remove the separate route/shell. The existing reseller Mini App stays untouched. If the Live host has a deployment-level incident, its existing `DENAGATE_LIVE_ENABLED` policy still applies; do **not** toggle it casually because that would also disable the real reseller app. No database rollback, historical data edits or remote Provider operations are needed.

**Implementation first move after approval:** refresh latest `master`, inspect `routes/live.php`, `ValidateTelegramInitData`, `LiveAuthController`, `EnsureLiveReseller`, `Dashboard`, `resources/views/live/*`, current Sales Bot entry and published pricing actions; then deliver only **DEMO-01** under its gate.

## 8. Decision record (explicitly deferred)

- The proposed first reseller credit of **1,000,000 tomans**, prior discussion of 900,000 tomans, free self-activation, free role switching and changing manual application review are **separate, undecided business changes**. **Do not implement** them with the Demo.
- The existing Sales Panel request balance gate, approved applications and 35%-where-published reseller terms continue as-is. Future business-policy approval must include existing-reseller grandfathering and safe handling of paid/unpaid orders.
- No fake `demo` role, test customer wallet, shadow orders, extra DB schema, new settings store or parallel purchase engine.
