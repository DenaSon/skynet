> **Phase 01 source checkpoint — 2026-10-27:** Source PR under verification promotes `sales:subs:view` Rich by default, preserves the historical `sales:subs:rich` alias and provides `sales:subs:simple` fallback. The visible trial button is retired. **Operator review of the deployed default-route Rich UI is still pending.** Do not start Phase 02 until explicit acceptance. Other phases remain planned only.

# DenaGate — Sales and Reseller Rich UX roadmap (2026-10-27)

**Status: approved for planning and phased review only.** This document authorizes **no feature implementation** by itself. The operator requested CI optimization first (merged in PR #253), followed by documentation of the UX phases. Implementation of Phase 01 and each subsequent phase requires an explicit instruction; do not begin the next phase without reviewing the deployed Telegram client experience and recording the operator's acceptance.

## Scope and baseline

- **In scope:** customer-facing Sales Bot pages and the `reseller` role's panel **inside the same Sales Bot**. This is not a separate reseller bot. Shared authorized Application Actions, business rules and the current Telegram routes are authoritative.
- **Out of scope:** Super Admin/Admin Bot restyling, new financial/provider behavior, checkout rule changes, RBAC grants, schema changes, new dependencies and broad architectural refactors. Maintain compatibility with unchanged Admin surfaces even if a shared transport helper is touched.
- **Starting point:** Rich service detail pilot from [PR #252](https://github.com/DenaSon/skynet/pull/252), with `sales:subs:rich` opt-in and explicit simple view. The operator reported live successful rendering/expansion on one Telegram client on 2026-10-27. This is not proof of support on all iOS, Android or Desktop versions.
- **Current UI contract:** [017-TELEGRAM-UX-IDENTITY.md](017-TELEGRAM-UX-IDENTITY.md). Existing actions, menus, Back/Home, copy buttons, RTL text, Persian dates, toman amounts, price sorting, pagination, owner checks and stale callback handling must be preserved.
- **Key experience rule:** choose the **best interaction for each screen**, not Rich Messages everywhere. Important status, price, balance, and decisions remain visible without expanding a section. Use expandable content for supporting details; put primary actions beside their context whenever supported. Forms and financial confirmations remain native, explicit and safe.

## Release protocol — mandatory for EVERY phase

1. Recheck latest `master`, current implementation and related tests before a scoped change. Identify screenshots/callbacks and failure/fallback states up front.
2. Make a **small, independent PR** for only that phase. Preserve stable callbacks or continue accepting old callback payloads. No data migration, financial or provider write without separate approval.
3. Before merging: run the **entire PHP 8.4 test suite**, PHPStan and Pint through the approved CI workflow, plus relevant route, private-chat, owner, member/reseller, error and backward-compatibility regressions. MySQL/Redis checks run according to the conservative CI path gate (and always for sensitive changes). PHP 8.5 runs nightly; CI success is not live Telegram acceptance.
4. Review PR diff, security, reversibility and required checks; only then merge to `master`, which triggers automatic production deployment.
5. **After deploy:** operator checks the exact changed page in a real Telegram client: RTL and Persian copy, Rich expand/collapse, inline CTAs, navigation/Back, stale callbacks, pricing and role-specific visibility as applicable. Record clients tested and remaining platform gaps. Verify legacy/fallback paths.
6. Provide a phase report with **انجام‌شده / تست‌شده / مشاهده واقعی / باقی‌مانده / ریسک‌ها**. Fix defects within the same scope and repeat verification. **Only operator acceptance unlocks the next phase**; a green CI, merged PR or deployment alone is insufficient.

When client support is absent or a rich edit fails, use the existing accessible text/InlineKeyboard path; do not hide essential actions inside a Rich-only control. Unknown Telegram errors must be reported, not masked as success. Existing photo/QR messages must not be edited as text.

## Phase 01 — Promote service detail from pilot to default

**Surface:** `SalesSubscriptionsUi`, Sales service list → owned service detail (both member and reseller).

- Change the normal owned service-detail entry to render Rich by default; remove the visible trial-only «✨ نمایش جدید» button.
- Keep the existing pilot callback routable for old messages and an explicitly accessible simple-view/fallback path when the Rich API/client does not work. Avoid cyclic rich/simple buttons.
- Reduce header density; show verified status, remaining quota and expiry prominently. Retain collapsible usage, order/plan, and connection help.
- Preserve same-subscription renewal, owned connection link/QR, subscription-page, customer label, refresh, service list and Home actions, including unsuccessful/unknown provider reads.
- Rich content must not expose connection tokens/URLs or cause provider mutations merely by being expanded.

**Acceptance:** tapping any owned service opens Rich directly; old `sales:subs:rich` works; simple fallback is usable; member/reseller/foreign-ID guards, stale and provider-error states work in tests and on the reviewed client. **Pilot success does not mark this phase complete.**

## Phase 02 — Unified tutorials (first full Rich redesign)

**Surface:** `SalesSupportUi` tutorial index and existing guides.

- Put Android, iOS/iPadOS, Windows, macOS, purchase, connection troubleshooting and eligible-role guides in a compact, structured learning experience. Group steps in `<details>` where this reduces navigation.
- Show the relevant existing verified app download link and «سرویس‌های من»/purchase/support CTA close to the corresponding guide; do not create fake download actions.
- Ordinary members can open panel setup: confirmed initial credit >= 750,000 toman, existing request/terms, review under the operator's stated service commitment, and current 35% reseller policy. Clarify that approved credit is retained, not a setup fee.
- Active resellers see their plan-builder help; ordinary members must not gain access through old guide callbacks. Retain Back, tutorials, support and Home.
- Preserve correct client-specific guidance, restrained emoji, Persian spacing and no internal tech/phase terms in customer messages.

**Acceptance:** real navigation and CTAs for all four platforms, purchase, troubleshoot and each role's relevant guide; no unauthorized panel/setup exposure; practical content length and collapse behavior accepted by operator.

## Phase 03 — Catalog and plan detail with in-content purchase actions

**Surface:** ready-to-buy list in `SalesHomeUi` and `PlanCatalogUi` browse/detail.

- Keep buyer-quoted plans sorted by current final price ascending, with existing five-plan pagination, readable plan name, volume, duration and visible **final price** in toman before any expansion.
- Trial Telegram Rich Message **inline content buttons** (`RichMessageButton` / `InputRichBlockButtons`, with `callback_data`) on a limited page first. Attach a clear «انتخاب پلن» or «ادامه خرید» action to the relevant plan/price, not a remote write.
- Reuse the existing catalog selection or validated checkout preview route; always recalculate current price, role, plan publication and Squad/provider readiness server-side. Never trust Rich HTML, button text or callback data as a price authority.
- Ordinary buyers see their payable price; resellers see public price, actual approved discount and their own payable quote without leaking the other role's privileges.
- If in-content buttons are unsupported or clutter narrow mobile screens, fall back to the existing two-column native price/specification buttons. Do not remove these until operator live acceptance.
- Plan detail may add expandable purchase information when useful; do not add an unnecessary tap for basic specifications.

**Acceptance:** both roles, mixed page sizes, discounts, stale/changed prices, disabled checkout, HTML escaping, genuine in-content button callback routing, and older Telegram client fallback. CTA cannot bypass the existing payment confirmation.

## Phase 04 — Home, account, referrals and service browsing

**Surface:** `SalesHomeUi`, service list, member account and referral summary.

- Keep home short: clear account role, actionable Buy/Services/Account, current safe wallet summary and reseller-only shortcuts where authorized.
- Account exposes important statistics first; membership/purchase details can be folded. Do not imply locally recorded subscription status is live provider state.
- Referrals show earned/count/remaining reward prominently and put policy/eligibility detail behind an optional section; preserve correct exclusion of trial and reseller purchases, copy and Telegram share buttons.
- Service list remains concise and paginated, with readable owner-scoped navigation into Phase 01 Rich detail.

**Acceptance:** member/reseller output, real balance/counts, policy copy, invite link copying, safe owner scoping and old menu commands.

## Phase 05 — Wallet, order history, payment and renewal presentation

**Surface:** wallet and recharge history, `SalesCheckoutUi` read pages, renewal preview and existing payment navigation.

- Surface current credit, latest top-up status and the next valid action without scrolling; put historical details in collapsible sections only when it improves readability.
- Order lists show clear pending/approved/failed status with context-sensitive resume/detail actions.
- Payment and renewal may have richer **read-only previews**, but receipt submission, wallet debit confirmations, single-use tokens, payment gates and cancel flows stay separate and explicit.
- Preserve exactly one wallet debit, receipt ownership, idempotency, stale callback and all current Billing/provider guards. Never show payment completed merely because a button was pressed.

**Acceptance:** insufficient credit, pending/duplicate receipts, resume/cancel, repeated confirmations and approved/failed states unchanged. Sensitive code changes trigger real MySQL/Redis CI.

## Phase 06 — Free trial and support

**Surface:** trial status/help, customer support shortcuts.

- Show the existing trial's real status, quota and remaining time before any expandable policy text; keep private one-time Contact sharing, confirmation and link/QR transport untouched.
- Support shows concise troubleshooting/guide shortcuts and the configured direct support action; do not build a ticketing system.
- Hide trial entry after a terminal trial per existing rules. Keep failure/unknown state honest and preserve all current notification behavior.

**Acceptance:** before trial, pending, active, ended and error states; no second trial or owner bypass; no QR/photo edit regression.

## Phase 07 — Reseller plan builder

**Surface:** `ResellerBuilderUi` selection and price quote.

- Keep volume/duration selection and custom numeric input as safe native buttons/forms; Rich displays final preview in a consistent quote hierarchy.
- Show public price, **actual computed** reseller discount and final payable price, with a contextual CTA to the existing validated checkout.
- Preserve all plan bounds, temporary draft expiration, reprice, no-purchase states and role guards.

**Acceptance:** reseller only; standard/custom GB and duration, invalid input, stale quotes, public-versus-reseller pricing and no duplicate orders.

## Phase 08 — Reseller reports, service health and customer management

**Surface:** `ResellerSalesReportUi`, `ResellerServiceHealthUi`, owner-scoped customer search and label operations.

- Reports: key counts/revenue/date range visible; fold supporting breakdown of new orders, renewals and pending orders. Keep 7/30-day filters and refresh.
- Health: critical/attention statuses visually clear; preserve bounded page-level live verification, pagination, refresh and each service-detail action. Never treat local history as provider-confirmed live status.
- Customer search and detail: short results, actionable service links, consistent Back; keep text-entry and name-edit confirmation accessible and simple.

**Acceptance:** only reseller's records, stale/foreign IDs denied, unknown provider status reported, no stored data or privilege changes.

## Phase 09 — Cross-screen coherence and acceptance closure

- Audit action labels, empty/error states, hierarchy, RTL, Persian date/toman/GB presentation, navigation, inline buttons, rich fallback and current role restrictions across phases 01–08.
- Verify actual clients available to the operator (iOS, Android and Desktop when possible); report each tested version and any unverified client instead of claiming universal support.
- Keep existing Admin Bot untouched and run shared-helper regressions. Fix **only verified** regressions in small reviewed PRs, not a broad rewrite.
- Complete the acceptance matrix and record explicit operator sign-off. No work outside this roadmap without separate scope review.

## Phase tracking

| Phase | Current state | Operator acceptance |
|---|---|---|
| 01 Service detail default | Planned; earlier opt-in pilot merged/tested | Pending |
| 02 Tutorials | Planned | Pending |
| 03 Catalog / in-content CTA | Planned; CTA requires live validation | Pending |
| 04 Home/account/referrals | Planned | Pending |
| 05 Wallet/orders/renewal presentation | Planned | Pending |
| 06 Trial/support | Planned | Pending |
| 07 Reseller builder | Planned | Pending |
| 08 Reseller reports/customer management | Planned | Pending |
| 09 Final cross-screen review | Planned | Pending |

**No Phase 01–09 implementation is authorized by the mere merge of this document.** The operator will explicitly start and accept each phase in order.
