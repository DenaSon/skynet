# DenaGate Live — UI/UX Polish Roadmap (P0–P4)

> **Decision: 2026-10-31.** This is the normative handoff/checklist for the **new Polish sequence**, not the historical LIVE-0/1 phases documented in [033](033-DENAGATE-LIVE-MINIAPP.md) or the original product Epics in [034](034-DENAGATE-LIVE-EPICS-ROADMAP.md).
>
> **Approval state:** **P0 is approved for implementation**; **P1, P2A, P2B, P3 and P4 are planned only** and require the operator's explicit approval before implementation. This documentation PR does **not** implement any Polish phase or authorize production changes.
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
| **P0 — UI Foundation** | 1 | 10 scoped Tailwind primitives + minimal design tokens and usage guidance | **Approved to start**; source changes not yet implemented |
| **P1 — UX Writing** | 1 | Consistent Persian labels, statuses, CTA and empty/error text | Planned; only after P0 accepted and separately approved |
| **P2A — Visual Refinement: overview/shop/credit** | 1 | Use P0 primitives consistently on overview, quote, checkout and credit | Planned; after P1 and separate approval |
| **P2B — Visual Refinement: services/reports** | 1 | Use primitives for service list/detail, connection, usage, pause/resume, renewal, reports | Planned; after P2A and separate approval |
| **P3 — Interaction & Feedback** | 1 | Action-level loading, disabled states, navigation, meaningful success/error feedback | Planned; after P2B and separate approval |
| **P4 — Telegram Mobile Experience & Final QA** | 1 | iOS/Android/Desktop Telegram touch/safe-area/keyboard/back/accessibility audit | Planned; after P3 and separate approval |

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

## 4. P1 — UX Writing & Content Polish (planned)

- Audit every user-visible text path in `resources/views/live/dashboard.blade.php`, `layout.blade.php`, `entry.blade.php` and the corresponding Livewire-presented notices. Establish one consistent vocabulary: **اعتبار، شارژ اعتبار، سفارش، پرداخت، سرویس، اتصال، تمدید، وضعیت**. Choose concise professional yet friendly Persian and use a consistent register.
- Standardize page headings, labels, contextual hints, primary/secondary CTAs, success/failure/loading, confirmation, return/cancel, empty states and inaccessible actions. Improve readability without overlong explanations or decorative emojis.
- Specific review candidates: `مشاهده پیش‌فاکتور` → `انتخاب پلن`; `محاسبه پیش‌فاکتور` → `مشاهده قیمت نهایی`; `تأیید و ثبت سفارش` → `ثبت سفارش بدون پرداخت` **only when it truly does not debit**; `نمایش لینک و QR` → `مشاهده اطلاعات اتصال`; `موارد بیشتر` → `نمایش سرویس‌های بیشتر`. These are **suggestions subject to verifying actual branch/action state**, not mandatory blind replacements.
- **Status truthfulness is mandatory:** distinguish locally recorded active vs provider-verified active, pending top-up awaiting receipt vs receipt submitted for review, order registered vs wallet charged vs queued fulfillment vs remotely completed, and “payment accepted” vs “renewal applied”. No wording can claim current network availability from local records or successful Provider mutation from enqueue.
- Review existing `billing_topups` states and workflow before labeling `pending`; do not invent a new state or change DB semantics in a copy-only phase. If accurate differentiation cannot be supported by current view data, report the gap rather than alter business logic without approval.
- **Acceptance:** a before/after text matrix across all sections; stable labels for same states on different screens; appropriate empty/error texts without leaking secrets; no false payment/Provider claims; focused rendering and status regression tests; no changes to Actions, business semantics, DB or bot copy outside approved scope.

## 5. P2 — Visual Refinement (planned; two PRs)

**P2A — Overview, Shop, Credit:** adopt P0 classes for hero, KPI summary, plan selection/price preview, order/payment confirmation layout and credit/top-up request cards. Improve typography, spacing, number/currency LTR alignment and visual priority of the single main CTA. Preserve plan ordering, current prices/discount and existing payment confirmation flow.

**P2B — Services, Reports:** adopt P0 classes for service directory, detail hierarchy, customer label, safely hidden connection link/QR, on-demand usage, optional pause/resume control, renewal states and settled-sales reports. Preserve availability gate and current visibility/owner checks. Do not expose links before explicit owner-bound reveal. Visually distinguish recorded vs verified provider statuses; show 7/30-day revenue separately from current service/pending counts.

**Acceptance (both):** no removed or hidden existing actions, no change to button callback/livewire method semantics, correct desktop/mobile RTL, long labels/numbers and small screens, light premium consistency without excessive effects, accessible focus/touch regions, no sensitive exposure. Rendering tests and production Vite build; operator screenshots/device smoke separately.

## 6. P3 — Interaction & Feedback Polish (planned)

- Per-action loading/disabled cues for plan selection and quote, order creation, wallet checkout, credit refresh, service usage/connection reveal, label editing, renewal and pause/resume. Prevent confusing duplicate taps in UI **without replacing server-side idempotency**.
- Confirmation must state specific affected service/order and financial amount when relevant; cancellations/back restore a predictable screen without discarded paid state. Success appears **only after verified server result**; queued processing gets a truthful pending status.
- Improve field validation hints, copy result feedback, empty/error recovery and keyboard-focus behavior; avoid nested/conflicting Livewire/Alpine state, global spinners, automatic retries or background polling.
- **Acceptance:** test fast double-taps, slow/failed updates, cancellation, stale confirmation/revoked role and errors with existing fail-closed rules; no double charge, new order, duplicate Provider request or misleading success, no extra packages or business logic changes.

## 7. P4 — Telegram Mobile Experience & Final QA (planned)

- Test Telegram iOS, Android and Desktop separately: viewport/safe area, fixed bottom navigation, software keyboard and scroll/focus, RTL and very long content, theme/light baseline, WebApp entry/return, readable copy, back/close.
- Evaluate Telegram-native BackButton and optional Haptic Feedback **only if version support, fallback and measurable UX benefit are verified**; these are **not preapproved implementation requirements**. Respect reduced motion and avoid mandatory gestures.
- Validate all four tabs and contextual purchase path, authorized customer-name edit, link reveal/copy/QR, credit/bot handoff, reporting, eligible renewal and optionally enabled pause/resume **without executing unapproved real financial or Provider writes**.
- **Acceptance:** capture device/version and pass/fail evidence, browser status/network failures without secrets, accessibility/keyboard checks, fixed regressions only within scope, code review + successful CI. Keep any unresolved payment/Provider E2E explicitly pending; do not declare live operational acceptance from fake tests.

## 8. Mandatory per-PR delivery and completion contract

1. Read the latest master code and the sources above; identify precise files, current UI state, constraints and **one phase only**. If changes require new architecture, settings, dependencies or business behavior, **stop for explicit approval**.
2. State exact **in scope / out of scope**, affected interactions and rollback; implement smallest reversible code/diff. Do not rewrite the whole `Dashboard.php`/`dashboard.blade.php` just to refactor styling.
3. Add focused feature/render/action regressions appropriate to the phase. Run **PHP 8.4 tests, PHPStan, Pint**, `npm run build` for CSS/JS/Blade asset changes, MySQL/Redis only when relevant; report any unavailable checks honestly. Inspect exact PR diff and CI before merge; master merge auto-deploys.
4. Perform/report actual Telegram-client checks separately after deploy. Do not confuse source pass, CI, deploy, browser/client smoke, financial correctness and Provider mutation verification.
5. Update **this one living document** at the end of each phase: PR link, merge SHA, checks, operator acceptance, pending items, risks and next authorized phase. Report **انجام‌شده / تست‌شده / باقی‌مانده / ریسک‌ها**. Keep earlier phases marked as historical, not silently overwritten.

**Release discipline:** P0 is the only approved implementation starting point now. P1–P4 need separate operator go/no-go; an approved P0 does not approve automatic implementation or merge of later phases. Do not label the whole Polish “complete” until every phase meets its own acceptance criteria and remaining real-device checks are recorded.
