# DenaGate Live — UI/UX Polish Roadmap (P0–P4)

> **Decision: 2026-10-31.** This is the normative handoff/checklist for the **new Polish sequence**, not the historical LIVE-0/1 phases documented in [033](033-DENAGATE-LIVE-MINIAPP.md) or the original product Epics in [034](034-DENAGATE-LIVE-EPICS-ROADMAP.md).
>
> **Approval state (2026-10-31):** P0 merged as [#320](https://github.com/DenaSon/skynet/pull/320) (`7e8efcdf`), P1 merged as [#321](https://github.com/DenaSon/skynet/pull/321) (`65e786ce`), and the operator separately authorized **P2A, P2B, P3 and P4** in sequence. P4 has a separate, limited code/QA PR; physical Telegram-client sign-off is not implied. No Polish PR authorizes production financial/Provider operations.
>
> **Source-of-truth order:** latest `master` code, `AGENTS.md`, `docs/000-CONTEXT.md`, `docs/005-ROADMAP.md`, `docs/017-TELEGRAM-UX-IDENTITY.md`, `docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md`, `docs/033-DENAGATE-LIVE-MINIAPP.md`, `docs/034-DENAGATE-LIVE-EPICS-ROADMAP.md`, then this Polish-specific scope. Refresh the branch/implementation inventory before each PR; historical docs do not override current code.

## 1. Goal, runtime and immutable boundaries

Deliver a **premium, minimal, Persian-first, Apple-inspired, Telegram-native-feeling** reseller Mini App: visual consistency, comprehensible copy, predictable interactions, responsive RTL, and accessible behavior. Do **not** add feature scope while polishing.

**Current verified source stack (2026-10-31):** Laravel 13 / PHP 8.4+, **Blade + Livewire 4**, **Tailwind CSS 4**, Livewire's bundled **Alpine**, **Vite 8**, Telegram WebApp SDK, local **Vazirmatn Variable** font; same Laravel modular monolith, MySQL and shared Application Actions. DenaGate Live currently uses bespoke Blade/Tailwind classes, **not** React, Vue, Inertia, Mary UI, DaisyUI or an additional component library.

- Theme baseline: **light**, `#F6F8FA` canvas, white surfaces, brand `#14B8A6`, readable ink `#1B2935`, `Vazirmatn`, RTL. Keep the four persistent destinations: **نمای کلی، سرویس‌ها، اعتبار من، گزارش‌ها**; purchase remains a contextual flow, not an automatic fifth tab.
- Do not change business behavior, identity, authorization, CSRF/Telegram HMAC, owner checks, session rules, prices/discounts, accounting/ledger, payment/renewal semantics, idempotency, Remnawave reads/writes, queue/job semantics, API/webhooks, provider/global write gates or bot menus in a Polish PR.
- **No new npm/Composer dependency**, SPA framework, new independent API/backend, DB schema/migration, ENV flag, production config, unnecessary abstraction, or broad PHP/Blade rewrite. Reuse code and existing design/telegram infrastructure.
- Existing operator-editable settings: prefer `spatie/laravel-settings` only when a genuine operator-managed setting is approved ([settings standard](004-STANDARDS.md#application-settings--environment-configuration)). Ordinary static UI copy stays in code; **no new settings keys** in this roadmap.
- Never display developer vocabulary (phase, PR, backend, provider/internal names), raw exceptions, network secrets, credentials or unverified live/financial claims in user UI. Preserve safe on-demand link reveal, QR, renewals, pause/resume and all existing actions.
- No real payment, credit mutation, production Provider write, feature-flag activation or other sensitive operation is authorized by this roadmap. Source CI ≠ real Telegram/financial/Provider acceptance.

## 2. Phase sequence and PR boundaries

| Phase | PR | Result | State / prerequisite |
| --- | --- | --- | --- |
| **P0 — UI Foundation** | [#320](https://github.com/DenaSon/skynet/pull/320) | 10 scoped Tailwind primitives + minimal design tokens and usage guidance | Merged `7e8efcdf`; PR/post-merge CI green; device QA separate |
| **P1 — UX Writing** | [#321](https://github.com/DenaSon/skynet/pull/321) | Consistent Persian labels, truthful statuses, compact copy and focused tests | Merged `65e786ce`; PR/post-merge CI green; device QA separate |
| **P2A — Visual Refinement: overview/shop/credit** | [#322](https://github.com/DenaSon/skynet/pull/322) | Use P0 primitives consistently on overview, quote, checkout and credit | Merged `cc973ea3`; PR/post-merge CI green; device QA separate |
| **P2B — Visual Refinement: services/reports** | [#323](https://github.com/DenaSon/skynet/pull/323) | Use primitives for service list/detail, connection, usage, pause/resume, renewal, reports | Operator-approved; independent scoped PR/CI gate; device QA separate |
| **P3 — Interaction & Feedback** | [#324](https://github.com/DenaSon/skynet/pull/324) | Action-scoped disabled/loading feedback, contextual confirmations, accessible hints, safe copy recovery | Merged `01daf79d`; post-merge CI successful; device QA separate |
| **P4 — Telegram Mobile Experience & Final QA** | [#325](https://github.com/DenaSon/skynet/pull/325) | Verify mobile safe area; preserve client/browser fallbacks and four-tab interactions; explicit device QA matrix | Independently approved; code CI evidence in PR; **physical iOS/Android/Desktop acceptance pending** |

**Total target: 5 phases, 6 small PRs** (P2 split into A and B). Never implement all phases in one PR; every phase has independent review, regression checks, rollout notes and revert path. Request approval before changing these boundaries.

## 3. P0 — DenaGate UI Foundation (approved scope)

**Objective:** Add a **small design vocabulary**, not a new component framework. Prefer `resources/css/app.css` with existing Tailwind v4 `@theme` tokens and `@layer components` for reusable classes, scoped to `.denagate-live`. Respect existing Vite inputs, current Livewire + Alpine initialization and `prefers-reduced-motion`. Keep `resources/views/live/entry.blade.php`'s independent no-Vite login usable.

**Exactly these 10 initial primitives** (names are the approved public CSS contract):

| Class | Role / desired behavior |
| --- | --- |
| `dg-card` | White content surface; subtle border/shadow, consistent radius/spacing |
| `dg-card-accent` | Restrained teal-tinted summary/highlight card |
| `dg-stat` | Legible RTL/LTR numeric metrics and currency typography; no fake data |
| `dg-btn-primary` | Primary CTA with readable text, hover/focus/disabled treatment |
| `dg-btn-secondary` | Secondary/neutral CTA with consistent sizing and focus |
| `dg-field` | Inputs/selects with consistent border, touch height, focus and validation affordance |
| `dg-status` | Semantic status label; variations must be accessible by **text and color** (never color only), not inaccurate “live” claims |
| `dg-heading` | Consistent Persian section/page heading hierarchy |
| `dg-empty` | Small, readable empty state with optional contextual existing CTA |
| `dg-nav-item` | Responsive menu item with accessible current/hover/focus state |

**Suggested base tokens** (final CSS naming/values may be adjusted within the P0 PR after visual and contrast checks): `dg-primary: #14B8A6` for brand accent, `dg-action: #087F73` for accessible white-text actions, `dg-canvas: #F6F8FA`, `dg-ink: #1B2935`, `dg-border: #E7ECEF`; keep radii/spacing/shadows minimal and avoid an elaborate token taxonomy. Ensure contrast for small text (target WCAG AA 4.5:1), visible keyboard focus, >=44px touch targets where appropriate, no motion dependence.

**P0 in scope:** CSS definitions, minimal token guidance/examples, focused rendering/build/regression tests. **P0 explicitly out of scope:** broad dashboard markup conversion, rewriting labels, changing action behavior, adding animation dependencies, changing existing four-tab navigation, modifying global Tailwind styles outside Live or changing `entry.blade.php` functionality. Apply the primitives across screens only in P2A/P2B, once validated.

**P0 acceptance:**
1. All ten selectors exist once in the Live-scoped stylesheet, are documented with intended use and do not leak into unrelated app/bot interfaces. No new dependency or unrelated code.
2. Existing Live pages and no-Vite Telegram sign-in render as before; font remains local and `@vite`/bundled Livewire JS works. `npm run build` emits correct Vite manifest.
3. Primary/secondary buttons, field, navigation and semantic status have legible default/focus/disabled states, RTL-friendly spacing and accessible contrast; `prefers-reduced-motion` remains respected.
4. Existing UI routes, buttons and Livewire interactions are preserved; source regression tests + PHP 8.4, PHPStan, Pint pass, with Telegram device visual check separately recorded.
5. Scoped PR + rollback by reverting CSS/docs only. No production flags/Provider/billing writes.

### P0 implementation contract and handoff (2026-10-31)

- **Source PR:** [#320](https://github.com/DenaSon/skynet/pull/320). The PR's checks, final diff and GitHub merge commit are the authoritative release references; do not infer a live-device pass from CI. **Production Telegram iOS/Android/Desktop visual smoke remains pending operator verification.** Later phases still require explicit approval.
- **Changed surfaces:** `resources/css/app.css`, focused `tests/Feature/Live/LiveUiFoundationTest.php`, and the existing Vite-build verification step in `.github/workflows/tests.yml`; no changes to Blade, Livewire Actions, the standalone no-Vite `entry.blade.php`, authentication, navigation or financial/Provider behavior. Revert #320 for CSS/test/docs rollback.
- **Design tokens:** Tailwind v4 `@theme` exposes `--color-dg-primary` (#14B8A6), `--color-dg-action` (#087F73), `--color-dg-canvas` (#F6F8FA), `--color-dg-ink` (#1B2935), and `--color-dg-border` (#E7ECEF). Namespaced tokens do not restyle existing pages; all ten component selectors are prefixed with `.denagate-live` and live in `@layer components`. The accessible action teal, not bright brand teal, is used behind white button text.
- **Use only when adopting in P2:** `dg-card` for normal white content and `dg-card-accent` for one restrained tinted highlight; `dg-stat` **only on the numeric/currency fragment** (LTR isolate with tabular digits); `dg-heading` for readable section titles; `dg-empty` for empty content with truthful text and any existing recovery CTA.
- **Actions/fields:** `dg-btn-primary` for the single main action; `dg-btn-secondary` for neutral/back; `dg-field` on native input/select/textarea elements. Buttons and fields have 44px minimum touch height, keyboard `:focus-visible`, hover and native `:disabled`; fields expose an invalid border via `aria-invalid="true"` and disabled styling. Real disabled behavior and authorization must still be enforced by HTML/Livewire/Application Actions, **not by CSS**.
- **Truthful status/navigation:** `dg-status` needs an explicit human-readable status label, optionally `data-tone="success|warning|danger"`; the tone alone never claims provider health or settlement. `dg-nav-item` uses `aria-current="page"` for the active destination. Avoid relying solely on color. Focus outlines, usable text contrast, RTL-compatible layout and the existing reduced-motion CSS are preserved.
- **Evidence boundary:** PHP 8.4 Pest, PHPStan, Pint, MySQL/Redis as selected by CI, and the Vite production manifest are verified from the linked GitHub checks after the final PR head. Browser/client interaction and visual contrast on actual devices require separate operator smoke; no live checkout or Provider mutation is authorized by P0.

## 4. P1 — UX Writing & Content Polish (planned)

- Audit every user-visible text path in `resources/views/live/dashboard.blade.php`, `layout.blade.php`, `entry.blade.php` and the corresponding Livewire-presented notices. Establish one consistent vocabulary: **اعتبار، شارژ اعتبار، سفارش، پرداخت، سرویس، اتصال، تمدید، وضعیت**. Choose concise professional yet friendly Persian and use a consistent register.
- Standardize page headings, labels, contextual hints, primary/secondary CTAs, success/failure/loading, confirmation, return/cancel, empty states and inaccessible actions. Improve readability without overlong explanations or decorative emojis.
- Specific review candidates: `مشاهده پیش‌فاکتور` → `انتخاب پلن`; `محاسبه پیش‌فاکتور` → `مشاهده قیمت نهایی`; `تأیید و ثبت سفارش` → `ثبت سفارش بدون پرداخت` **only when it truly does not debit**; `نمایش لینک و QR` → `مشاهده اطلاعات اتصال`; `موارد بیشتر` → `نمایش سرویس‌های بیشتر`. These are **suggestions subject to verifying actual branch/action state**, not mandatory blind replacements.
- **Status truthfulness is mandatory:** distinguish locally recorded active vs provider-verified active, pending top-up awaiting receipt vs receipt submitted for review, order registered vs wallet charged vs queued fulfillment vs remotely completed, and “payment accepted” vs “renewal applied”. No wording can claim current network availability from local records or successful Provider mutation from enqueue.
- Review existing `billing_topups` states and workflow before labeling `pending`; do not invent a new state or change DB semantics in a copy-only phase. If accurate differentiation cannot be supported by current view data, report the gap rather than alter business logic without approval.
- **Acceptance:** a before/after text matrix across all sections; stable labels for same states on different screens; appropriate empty/error texts without leaking secrets; no false payment/Provider claims; focused rendering and status regression tests; no changes to Actions, business semantics, DB or bot copy outside approved scope.

### P1 delivery checkpoint — UX Writing (2026-10-31)

**PR:** [#321](https://github.com/DenaSon/skynet/pull/321). The exact PR and subsequent master checks are the evidence for source/CI and merge; real Telegram-client UX acceptance remains separate. Changes are limited to Live-facing text in `resources/views/live/dashboard.blade.php`, the independent `entry.blade.php`, four safe Presenter error messages and focused tests. **No behavioral change, schema, provider/finance write, new setting, dependency or P0-class adoption.**

| Surface | Before | After / truth boundary |
| --- | --- | --- |
| Overview | «فروش تأییدشده» | «فروش تسویه‌شده» (existing settled-only report) |
| Plan preset | «مشاهده پیش‌فاکتور» | «انتخاب پلن» (selects plan; no payment) |
| Plan builder | «تنظیم و محاسبه قیمت» | «تنظیم پلن» (selects builder; price is separate) |
| Quote | «محاسبه پیش‌فاکتور» | «مشاهده قیمت نهایی»; quote remains nonbinding |
| Create pending order | «تأیید و ثبت سفارش» | «ثبت سفارش بدون پرداخت»; existing Action still creates pending only |
| Recent orders | mismatched paid/fulfilled wording | Same recorded payment/preparation wording as order details; never claim remote activation merely on `paid` |
| Credit / new top-up | pending displayed «در انتظار بررسی» | pending = «در انتظار ارسال رسید»; submitted = «در انتظار بررسی واریز»; approved/rejected unchanged, no credit assumed |
| Service status | «فعال در سوابق داخلی» vs «فعال در سوابق» | consistently «فعال در سوابق»; on-demand GET result is labeled separately from local records |
| Connection | «نمایش لینک و QR» | «مشاهده اطلاعات اتصال»; credentials stay hidden until explicit authorized click |
| Services pagination | «موارد بیشتر» | «نمایش سرویس‌های بیشتر»; existing owner-scoped six-item keyset unchanged |
| Renewal | «پرداخت ثبت شده؛ در انتظار اعمال تمدید» | «پرداخت ثبت‌شده؛ تمدید در انتظار اعمال»; `fulfilled` remains distinct |
| Reports / entry | repeated/wordy helper text | compact settled-sales and Telegram-entry wording; no change to HMAC/submit |

**Bounded collections / separately gated pagination:** Services already use six-item owner-scoped keyset pages with a first-page reset. Recent new-plan orders use `OrderReader::recentNewPlans` (10 max), and wallet top-ups use `ViewWallet` (10 max); these two **do not currently expose pagination**. The available preset/builder catalogs are complete lists of authorized offers, so their long-list presentation also merits assessment if the catalog grows. The operator requested compact lists and pagination for expandable collections; **P1 preserves all bounds and existing actions**. Any new order/top-up/catalog pagination requires an independently approved P2 design or separate scoped Action/pagination PR (including ownership, cursor and reset tests), not a hidden P1 read/architecture change.

**Source acceptance evidence:** updated textual assertions, regression for actual pending→submitted top-up state and unchanged wallet journal, existing owner/security/renewal status tests, PR PHP 8.4 Pest, PHPStan, Pint, Vite build and MySQL/Redis CI. Exact results/merge SHA are recorded in the linked PR and GitHub Actions; **do not claim live-device acceptance based on mocks**. Rollback = revert #321. **Next phase: P2A remains unapproved until operator go/no-go.**

## 5. P2 — Visual Refinement (planned; two PRs)

**P2A — Overview, Shop, Credit:** adopt P0 classes for hero, KPI summary, plan selection/price preview, order/payment confirmation layout and credit/top-up request cards. Improve typography, spacing, number/currency LTR alignment and visual priority of the single main CTA. Preserve plan ordering, current prices/discount and existing payment confirmation flow.

**P2B — Services, Reports:** adopt P0 classes for service directory, detail hierarchy, customer label, safely hidden connection link/QR, on-demand usage, optional pause/resume control, renewal states and settled-sales reports. Preserve availability gate and current visibility/owner checks. Do not expose links before explicit owner-bound reveal. Visually distinguish recorded vs verified provider statuses; show 7/30-day revenue separately from current service/pending counts.

**Acceptance (both):** no removed or hidden existing actions, no change to button callback/livewire method semantics, correct desktop/mobile RTL, long labels/numbers and small screens, light premium consistency without excessive effects, accessible focus/touch regions, no sensitive exposure. Rendering tests and production Vite build; operator screenshots/device smoke separately.

### P2A delivery checkpoint — Visual Refinement (2026-10-31)

**Independent PR:** [#322](https://github.com/DenaSon/skynet/pull/322). Status and merge SHA are verified against the PR/Actions; do not claim a completed release or real-client acceptance before evidence is available. Changes are **Blade presentation only** in `resources/views/live/dashboard.blade.php`, with focused rendering/contract tests. The existing P0 CSS classes, Livewire runtime and all Laravel Application Actions remain unchanged.

- **Overview:** one restrained accent hero, neutral KPI cards, consistent 44px+ secondary navigation actions, accessible high-contrast primary shopping CTA and isolated LTR/tabular numeric summaries.
- **Shop:** consistent neutral price-selection cards, accessible selected-plan inputs and quote/confirmation areas. **Payment remains a separately confirmed action** with unchanged `wire:click` methods and server-side idempotency. The order-state chip is neutral/textual, not a false green activation claim. Current ten recent orders use more compact readable rows, not an unbounded feed.
- **Credit:** one accent wallet summary, same verified ledger balance, straightforward bot top-up handoff, accessible refresh action, and concise ten-record top-up rows with text-plus-color status: `approved` success, `rejected` danger, `pending/submitted` warning, unknown neutral. No status is used as authorization or proof of credit.
- **Strict scope:** no layout/entry rewrite, no new component/asset/dependency, no financial or Provider changes, no order/service/report data read changes, no four-tab navigation edits, no `P2B` service detail/report styling. Preserve all screen affordances and existing no-Vite sign-in.
- **Compact list and pagination decision:** Existing service paging remains six records per cursor; order history and wallet top-ups remain independently bounded to **ten**, so P2A reduces row spacing without pretending to expose all history. **New pagination is not included**: it requires changing `OrderReader::recentNewPlans` or `ViewWallet`/read Actions (and authoritative cursor/ownership/reset tests). The growing plan catalog likewise requires an intentionally scoped discovery/paging contract rather than silently slicing client-side and hiding offers. Carry this requested enhancement to its own approval gate, potentially when P2B is planned.
- **Verification and rollback:** review the exact PR diff, PHP 8.4/Pest rendering and checkout regressions, PHPStan, Pint, production Vite manifest and MySQL/Redis CI. Check Telegram iOS/Android/Desktop after deployment separately (responsive long numbers/names, active buttons, quote, bank-wallet handoff without real unapproved transfers); revert #322 to roll back CSS-class adoption only. P2B remains unapproved.

### P2B delivery checkpoint — Services & Reports (2026-10-31)

**Independent PR:** [#323](https://github.com/DenaSon/skynet/pull/323). Latest master at authoring: P2A merge `cc973ea3`. PR checks, merge and master CI are authoritative for code acceptance, not Telegram-device acceptance.

- **Scoped visual change:** Only the P2B service and report branches of `resources/views/live/dashboard.blade.php` adopt existing P0 `dg-card`, `dg-card-accent`, `dg-heading`, `dg-btn-primary`, `dg-btn-secondary`, `dg-field`, `dg-empty`, `dg-status` and `dg-stat` primitives. Overview, quote, purchase, wallet and entry/layout/nav markup remain byte-for-byte outside this region. No PHP/Action/CSS change, dependency, migration, setting, API or financial/provider write.
- **Service directory:** Six items per **existing owner-scoped keyset page**, with the original **نمایش سرویس‌های بیشتر** and **بازگشت به اولین صفحه** actions/visibility. Cards use less vertical padding, readable wrapping and >44px touch controls. No full feed, no unbounded queries, no change to item ordering, selection security or private URL visibility.
- **Local/remote truthfulness:** For locally recorded `active`, show a **neutral** "فعال در سوابق" label; `provisioning` / `needs_review` are warning, `failed` danger. Only after a deliberate owner-verified GET may the separately labeled `وضعیت بررسی‌شده` show green for verified active. Checked expired/disabled/exhausted are danger; checked limited is warning. These are presentation-only status tones, never new business states or proofs of current connectivity.
- **Details/connection:** P0 typography and accessible buttons/field for owner label, explicit link+QR reveal, copy, hide, on-demand usage and status controls. Preserve the exact conditional gate: no credential shown or remotely fetched on opening/listing; QR and URL remain visible only inside the previously guarded reveal state. Existing explicit status request/confirmation/queued notice remains unchanged.
- **Renewal:** Consistent quote, payment, cancellation and error surfaces. Preserve snapshot/one-use confirmation, quote recheck, charge, fulfillment and remote-apply distinction; no premature "completed" from wallet debit or enqueue.
- **Reports:** Keep 7/30-day paid/settled revenue as an accent summary, and six original metrics in compact neutral cards. Date range remains visibly distinct from **current** service and pending-order counts; original time-zone and data semantics are untouched. No additional transactions loaded.
- **Pagination backlog:** User requested compact list design and paging for every potentially growing list. Services already have real cursor pages. Credit top-ups (max 10) and recent orders (max 10) remain bounded but *not navigable beyond those ten*; catalog also lacks paging. New paging would change shared read Actions and require separate user-approved scope with owner-bound cursor tests. No invisible client-side slicing was added in P2B.

**Tests & review:** existing owner isolation, empty/list/cursor reset, genuine on-demand usage, QR secrecy, label edit, status confirmation, renewal checkout and 7/30 report tests augmented with expected new UI styles and local-vs-verified tones. PHP 8.4 Pest / PHPStan / Pint / Vite production build / MySQL CI must pass on final PR head before merge; verify master CI separately. Device-level responsive/Telegram iOS-Android-Desktop and production operations are pending operator smoke. **Rollback:** revert PR #323; no data migration. **Next:** P3 unapproved until separate go/no-go.

## 6. P3 — Interaction & Feedback Polish (planned)

- Per-action loading/disabled cues for plan selection and quote, order creation, wallet checkout, credit refresh, service usage/connection reveal, label editing, renewal and pause/resume. Prevent confusing duplicate taps in UI **without replacing server-side idempotency**.
- Confirmation must state specific affected service/order and financial amount when relevant; cancellations/back restore a predictable screen without discarded paid state. Success appears **only after verified server result**; queued processing gets a truthful pending status.
- Improve field validation hints, copy result feedback, empty/error recovery and keyboard-focus behavior; avoid nested/conflicting Livewire/Alpine state, global spinners, automatic retries or background polling.
- **Acceptance:** test fast double-taps, slow/failed updates, cancellation, stale confirmation/revoked role and errors with existing fail-closed rules; no double charge, new order, duplicate Provider request or misleading success, no extra packages or business logic changes.

### P3 delivery checkpoint — Interaction & Feedback (2026-10-31)

**Scoped PR:** [#324](https://github.com/DenaSon/skynet/pull/324). Base = P2B merged master `54393f77`. Completion, CI and merge status must be verified from GitHub; this note alone is not a successful deploy or real-device sign-off.

- **Per-action request state:** all existing Livewire `wire:click` buttons in the Live dashboard get explicit `wire:loading.attr="disabled"` and a scoped `wire:target`. High-latency, write or sensitive controls show a delayed inline progress label (quote, order, wallet, label, credential reveal, on-demand usage, pause/resume, renewal, paging, reporting). The previous catch-all loading pill is replaced with a **navigation-targeted** `role="status"` announcement, preserving the existing accessibility baseline without an all-action spinner. No polling, new state machine, JS dependency, background retry or side effects in loading UI.
- **Race prevention:** primary submit/confirm and the relevant cancel controls are disabled during the corresponding payment/status/renewal request. This is presentation-level defense only. Existing one-use owner-bound cache confirmation, unique intent, transaction idempotency, role verification and queued Provider actions remain the authoritative security boundaries; no server-side business change.
- **Specific confirmations:** new-order wallet review names the **exact order and product** alongside the pre-existing amount/current/remaining balance; status confirmation names the **exact owner-bound service and order** and explicitly says result requires later verification; renewal wallet review names its current service and renewal order. No status becomes successful merely on enqueue.
- **Field help:** buyer/customer label and builder day/GB inputs have visible hints linked with `aria-describedby`, preserving existing validation, prices and error notifications.
- **Copy and recover:** the guarded subscription reveal keeps the URL+QR owner gate unchanged. The Alpine copy control disables while copying, shows success only after Clipboard API/fallback succeeds, displays a readable failure message when copy is unavailable and allows manually selecting the readonly link. It makes no provider call or automatic copy on reveal.
- **Minimal code scope:** `resources/views/live/dashboard.blade.php` plus focused tests and this document only. No PHP Application Action/Livewire class mutation, migration, CSS, bot, dependency, SDK or API changes. All `wire:click` parameter values, four-tab navigation, six-item services cursor, ten-item order and wallet bounds and existing fallback links remain untouched.
- **Proof:** specific P3 markup/accessibility/feedback tests and existing DB-backed service status double-submit and queue-once, stale payment/cancel/no double debit, owner isolation, connection secrecy, and renewal tests must pass with full PHP 8.4 Pest, Pint, PHPStan, MySQL/Redis, and Vite production CI. These test request state and business invariants; they **cannot simulate client double tapping/clipboard permissions or prove Telegram iOS/Android/Desktop interaction**. Operator device smoke and P4 remain pending. Rollback = revert PR #324.

## 7. P4 — Telegram Mobile Experience & Final QA (planned)

- Test Telegram iOS, Android and Desktop separately: viewport/safe area, fixed bottom navigation, software keyboard and scroll/focus, RTL and very long content, theme/light baseline, WebApp entry/return, readable copy, back/close.
- Evaluate Telegram-native BackButton and optional Haptic Feedback **only if version support, fallback and measurable UX benefit are verified**; these are **not preapproved implementation requirements**. Respect reduced motion and avoid mandatory gestures.
- Validate all four tabs and contextual purchase path, authorized customer-name edit, link reveal/copy/QR, credit/bot handoff, reporting, eligible renewal and optionally enabled pause/resume **without executing unapproved real financial or Provider writes**.
- **Acceptance:** capture device/version and pass/fail evidence, browser status/network failures without secrets, accessibility/keyboard checks, fixed regressions only within scope, code review + successful CI. Keep any unresolved payment/Provider E2E explicitly pending; do not declare live operational acceptance from fake tests.

### P4 acceptance record — Mini App device QA (2026-10-31)

**Independent PR:** [#325](https://github.com/DenaSon/skynet/pull/325) from `master` `01daf79d`. Scope: exactly one mobile bottom-navigation safe-area attribute change, a focused feature/source regression suite, and this living audit; no new JavaScript runtime, component, settings, dependency, CSS primitive, permission, backend Action, financial flow, Provider operation, or DB migration. The final review and CI/merge state are tracked on the PR and workflow, **not inferred from this document**.

**Code-audit finding and minimal remediation:** Existing markup used `env(safe-area-inset-bottom)` but not the Telegram-specific `--tg-content-safe-area-inset-*` CSS variables defined in the [official Telegram Mini Apps platform documentation](https://core.telegram.org/bots/webapps). The bottom tab nav now resolves bottom/left/right against the browser's `env()` and Telegram's `var(..., 0px)` values, keeping an 8px baseline, while honoring physical RTL inline-start/end orientation. Original mobile tab destinations, `lg:hidden`, `min-h-14`, navigation positioning, `pb-32` content clearance, desktop sidebar and full authentication route remain unchanged. No speculative fullscreen, native BackButton or Haptic integration was introduced without actual client interoperability evidence. The UI remains a deliberate light theme.

**Automated checks** (run by CI on PR SHA, then on resulting master SHA separately): PHP 8.4 full Pest; PHPStan; Pint; production Vite bundle/manifest and font/RTL/reduced-motion assertions; MySQL migration/rollback and real Redis worker. New tests cover source fallback declarations, responsive 4-tab markup, an actual authenticated reseller's tab transitions and 7-day report selection, no billing/provider operations during navigation, and the signed Telegram entry's browser fallback. Existing suites cover signed initData, Livewire owner/RBAC/session updates, checkout idempotency, credential reveal secrecy, reported limits and local/provisional statuses. **Tests do not emulate true keyboard, clipboard permission, Telegram viewport transitions or touch input.**

**Required operator device acceptance:** Record exact Telegram app version + OS/device, screen width/orientation, test date, outcome, redacted screenshot or short observation and relevant error codes; never copy user IDs, bot tokens, subscription URLs, live credentials, bank data or raw initData into the report.

| Client | Device / Telegram version | Tested? | Outcome |
| --- | --- | --- | --- |
| Telegram on iOS | Not supplied | No | **PENDING** — manual client smoke |
| Telegram on Android | Not supplied | No | **PENDING** — manual client smoke |
| Telegram Desktop | Not supplied | No | **PENDING** — manual client smoke |

**Operator smoke flow (non-mutating by default):**
1. From the Sales Bot's authorized Mini App entry `/live`, open a fresh session. Confirm HTTPS auth (no 403 or HTTP mixed-content form), no white screen, and return-to-bot behavior; the plain-browser entry must show the Sales Bot login instruction instead of bypassing Telegram.
2. Switch each of the four tabs and contextual purchase screen, including Overview ↔ Shop, recent orders and service list paging. Check 320–430px portrait and an available landscape width, with browser bottom safe-area and Telegram bottom controls/notch; confirm no horizontal scroll or clipped tabs and labels.
3. Use `نام کاربری مشتری`, builder day/GB and service customer-name input only with **no paid order confirmation**. While keyboard is open, verify that the focused field, validation helper, CTA and page scroll stay usable. Cancel edits and confirm prior state is retained.
4. Open an **owned** eligible service detail; on-demand usage/reveal/copy/QR/hide should happen only on explicit tap; verify privacy before reveal and after hide. Avoid sharing credentials in evidence. Check that locally recorded status and freshly checked Provider status are not conflated. Usage is read-only; status enable/disable and renewal **must not be executed on Production** without fresh operation-specific approval.
5. View wallet/current-balance and settled report 7/30 days; confirm multi-digit values, Persian typography, RTL/LTR ordering, loading/errors and focus. Do **not** authorize a live wallet debit/top-up or renewal simply to test UX.
6. Test dark-mode Telegram shell with the product's intentional light baseline, reduced-motion settings if applicable, back/close and reopening app, slow-network failure/retry and fallback bot link. Inspect only sanitized console and HTTP status codes on failure (especially the Livewire update request).

**Evidence/closure rule:** P4 **source/code gate may pass**, but the *whole UI/UX Polish is not end-to-end accepted* until iOS + Android + Desktop passes/failures are recorded with device-specific evidence. Client tests and any actual payments/Provider writes remain explicitly pending. No real Telegram device was available through this assistant session; public `https://live.denagate.ir/` could not be independently opened by the available external-fetch tool. Do not assert a 200, usable remote sign-in, deploy completion or real-device pass. **Rollback:** revert PR #325's view/tests/docs; emergency disablement remains `DENAGATE_LIVE_ENABLED=false` (operator-controlled, not modified in this phase).

### Post-P4 approved visual patch — DenaGate brand identity (2026-10-31)

**Scope:** One independent reviewable PR after P4 source merge `1f93aed9`, operator-approved for branding only. Replaces the placeholder `D` marks with the **actual user-supplied** 49×52 DenaGate logo (locally size-optimized PNG at `public/images/denagate-logo.png`) in the desktop sidebar, mobile header and independent no-Vite Telegram entry. Adds an **illustrative vector-inspired** `denagate-mark.svg` as an optional standalone mark; it is a hand-drawn approximation, **not** a verified vector master of the official supplied raster. The PNG is the one used in the product.

- User-visible title/entry/footer become **DenaGate** instead of *DenaGate Live*. Technical route, feature identifiers and backend naming retain their existing values.
- The **existing** scoped `dg-status` primitive with success tone and the text **فعال** is reused beside authenticated sidebar and mobile headings. Its green dot uses Tailwind's `motion-safe:animate-pulse` (subtle and disabled when `prefers-reduced-motion` is enabled). No new CSS primitive is introduced; the original approved set of ten is unchanged. It indicates that the panel UI is available **not** measured network, Provider, account, or service availability. Do not substitute this indicator for actual health data.
- Only markup/CSS/public assets/test/doc changes; no dependencies, JavaScript initialization, auth/session change, financial/provider operation, migration, new page or navigation refactor. Brand PNG has no remote network dependency. Entry stays independently renderable without Vite, and Telegram `initData`/CSRF flow is unchanged.
- Acceptance checks: file signatures, raster dimensions, intentional independent SVG type, all three UI placements, mobile/desktop title, RTL+four tabs unchanged, no fake network status, reduced-motion fallback, signed entry and authenticated reseller rendering. Run full PHP 8.4 Pest, Pint, PHPStan and Vite build; inspect final PR and post-merge CI; physical Telegram client P4 matrix **still pending**. Rollback = revert only this brand patch PR, no data migration.

### Final operator-approved post-P4 report polish (2026-10-31)

**Independent PR:** [#327](https://github.com/DenaSon/skynet/pull/327), based on merged brand patch `17150866`. Pure display refinement inside **only** `@elseif ($section === 'reports')` in `resources/views/live/dashboard.blade.php`, plus a focused report regression test and this documentation. No code, database, migration, package, settings, runtime CSS, shared P0 primitive, Application Action, Provider, payment, checkout, nav, service list or credit change. Rollback = revert PR #327.

**Layout / UX acceptance contract:**
- **Header/period selection:** Reseller-focused subtitle and compact 7/30-day period selector, with original `selectReportDays` Livewire action, `wire:loading.attr="disabled"`, `aria-pressed`, accessible `role=group`, and a **single** scoped delayed report loading notice.
- **Primary KPI:** Restrained DenaGate green gradient/card for `revenueRials / 10` in **toman**, retaining numeric LTR isolation and the actual existing Persian `from`/`to` dates in **Tehran**. No unverified comparison, trend direction, revenue prediction or proxy for turnover.
- **Historical window:** Exactly the original `sales` (خریدهای جدید), `renewals` (تمدیدها), and `transactions` (تراکنش‌های تأییدشده) counts, grouped with a caption explicitly tied to the selected 7- or 30-day period.
- **Current snapshot:** Exactly the original `pending` (سفارش‌های در انتظار پرداخت), `services` (کل سرویس‌های ثبت‌شده), and `namedServices` (سرویس‌های دارای نام) counts, visually separated and **not** represented as historical-window figures. Distinction is visible in group headings and explanation.
- **Responsive quality:** Compact two-column mobile cards, three-column desktop cards, wrapped Persian labels, clear number hierarchy, high-contrast text and existing font, button size, focus, RTL and reduced-motion baselines. No icon library, JS, CSS or chart engine.
- **Automated proof:** New tests exercise authenticated reseller report page, both 7/30 switches, semantic grouping/labels, original fields and conversion expression, no billing/order/provider writes, and no remote HTTP calls. All pre-existing action/ownership, signed entry, report and operational tests must pass in full PR CI and post-merge master CI. **Physical iOS/Android/Desktop validation of the new report appearance remains operator smoke, not assumed from automated tests**.

### Operator-approved compact six-KPI follow-up — 2026-10-31

**Reason:** Real Telegram mobile screenshot showed an awkward 2-column wrapping pattern (two cards + one card per group) and unnecessarily tall report counters after PR #327. **New acceptance overrides PR #327's report-metric grouping and 2-column mobile grid only.** The owner explicitly requested **two rows of three smaller cards, without separate group headings**.

- Exactly six **unchanged**, owner-scoped report counters in one `grid-cols-3` responsive row flow: `sales`, `renewals`, `transactions`, then `pending`, `services`, `namedServices`, resulting in **3 + 3 cards** on mobile; compact `p-2.5`, 11px wrapped labels, 18px tabular numbers and sufficient height to avoid truncation, with existing DenaGate primitives.
- No separate visible headings or duplicated metric groups. Keep one accessible section label and use subtle green/gray dots as a visual distinction rather than group titles; retain the **existing explanatory sentence** distinguishing period-based sales from current account snapshot to avoid implying that six metrics share a timeframe.
- Preserve settled-revenue hero, date range, 7/30-day selection, Livewire actions, report values, owner authorization and all other screens exactly. No backend queries, CSS primitives, migrations or runtime JS. Only report Blade, focused regression tests and this roadmap are touched.
- CI acceptance: six counters represented once in source, precisely one three-column grid, no discarded button states/loading, authenticated reseller report rendering and switch, no financial writes/HTTP calls, PHP 8.4/Pest + PHPStan + Pint + Vite + MySQL/Redis. Real-device acceptance for 320–430px Telegram WebView readability remains operator-side after deployment. Rollback independently by reverting this follow-up patch.

## 8. Mandatory per-PR delivery and completion contract

1. Read the latest master code and the sources above; identify precise files, current UI state, constraints and **one phase only**. If changes require new architecture, settings, dependencies or business behavior, **stop for explicit approval**.
2. State exact **in scope / out of scope**, affected interactions and rollback; implement smallest reversible code/diff. Do not rewrite the whole `Dashboard.php`/`dashboard.blade.php` just to refactor styling.
3. Add focused feature/render/action regressions appropriate to the phase. Run **PHP 8.4 tests, PHPStan, Pint**, `npm run build` for CSS/JS/Blade asset changes, MySQL/Redis only when relevant; report any unavailable checks honestly. Inspect exact PR diff and CI before merge; master merge auto-deploys.
4. Perform/report actual Telegram-client checks separately after deploy. Do not confuse source pass, CI, deploy, browser/client smoke, financial correctness and Provider mutation verification.
5. Update **this one living document** at the end of each phase: PR link, merge SHA, checks, operator acceptance, pending items, risks and next authorized phase. Report **انجام‌شده / تست‌شده / باقی‌مانده / ریسک‌ها**. Keep earlier phases marked as historical, not silently overwritten.

**Release discipline:** P0/P1/P2A/P2B/P3 are merged; P4 was independently approved with a separate bounded code PR and client QA matrix. No new phase is authorized. Approval of one phase never authorizes the next. Do not label the whole Polish “complete” until every phase meets its own acceptance criteria and remaining real-device checks are recorded.
