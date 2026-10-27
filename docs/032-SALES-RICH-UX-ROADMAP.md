> **Phase 09 source checkpoint — 2026-10-28:** Final cross-screen audit of the approved Sales/member/reseller Rich UX identified one concrete navigation mismatch: the five-item ready-buy list displayed prev/next buttons without a visible current page number in Rich, while the native version and full catalog did. The Rich ready-buy list now shows **«صفحه N از M»** only when multiple pages exist. The original callbacks, buyer-quoted pricing and simple fallback are unchanged; the existing 1/2, 2/2 and simple navigation are regression-tested. No speculative redesign, new dependency, finance, Provider or Admin changes. Phase 09 source review and CI are separate from the final operator's actual Telegram acceptance.

> **Phase 08 source checkpoint — 2026-10-27:** Sales reseller-only reports, bounded service-health pages and customer search results now use RTL Rich presentation with existing reports/health/service-detail/Back controls. Financial numbers and chosen date ranges remain Application Action sourced; live health covers at most six current owner-owned GETs per page and unknown state is explicit; customer edits and text input remain native. `sales:report:simple`, `sales:health:simple`, `sales:subs:search:simple` revalidate the same owner/role checks and reuse HTML fallback. **Real Telegram acceptance pending; Phase 09 not started.**

> **Phase 07 source checkpoint — 2026-10-27:** Sales-only final reseller builder price quote uses Rich RTL with current computed public price/discount/final price and a green in-content CTA to unchanged guarded checkout preview. Size/day selection, custom 10-minute text form, safe native keyboard checkout and Admin Bot behavior remain. Explicit `builder:quote:simple` refreshes/revalidates the quote; transport failure has native fallback. **Real Telegram visual/button acceptance pending**. Do not start Phase 08 until user approval.

> **Phase 06 source checkpoint — 2026-10-27:** Sales trial eligibility, in-progress and active read pages use concise RTL Rich with important live values outside expandable help. Terminal trial still sends native reply-keyboard update to hide access; one-time Contact and confirmation, QR links, anti-abuse and provider operations stay unchanged. Support gains existing troubleshooting/service/help shortcuts plus configured operator URL, with an explicit simple HTML fallback. **Operator Telegram acceptance pending**; Phase 07 reseller builder must not start without approval.

> **Phase 05 source checkpoint — 2026-10-27:** Sales wallet read view, owner-scoped order history, and owner/provider-verified renewal **preview only** use RTL Rich presentation with explicit simple HTML/text callbacks. The existing receipt, top-up form, bank transfer, wallet payment confirmation and renewal conversion remain native and unchanged for safe explicit finance operations. Payment/order statuses are recorded states, not assumed live provider outcomes. **Real Telegram acceptance pending**; Phase 06 is not authorized by this source change.

> **Phase 04 source checkpoint — 2026-10-27:** Sales Home and the owner-scoped service list stay fast, native and minimally polished. Rich RTL account highlights recorded services, paid purchases, account role and credit, with foldable sum paid and membership details; Rich referral highlights real totals and copy/share URL, with collapsible eligibility and reseller restrictions. Simple HTML callbacks preserve old clients. Operator live Telegram review still required for Phase 04. **Phase 05 is not authorized by this change**.

> **Phase 03 UX correction — 2026-10-27:** After reviewing actual Telegram screenshots, the operator requested direct green «🛒 خرید» buttons **inside each Rich plan row**, without an extra Rich detail step. The button invokes the existing `catalog:detail:{id}:{page}` checkout route (revalidates current buyer price, plan and payment/provider readiness); native two-column fallback and pricing/pagination remain. Previously sent `sales:catalog:preview` callback links stay **read-only** for compatibility and are no longer emitted from the lists. **Real Telegram CTA/checkout and fallback acceptance remain pending; Phase 04 is still blocked.**

> **Phase 03 source checkpoint — 2026-10-27:** Rich quoted Sales ready-list and catalog list retain the existing native two-column buy buttons. In-content buttons open a NEW read-only per-plan quote, where the in-content «ادامه خرید» button uses the existing role/price/provider-validated checkout callback; old direct-checkout callbacks remain unchanged. Three explicit simple-view callbacks and Telegram-error fallback are preserved. No purchase is made merely by opening quote details. **Operator visual and real inline CTA testing on Telegram remains pending after successful CI/merge; Phase 04 must not start before explicit acceptance.**

> **Phase 02 source checkpoint — 2026-10-27:** Sales tutorial index plus device, purchase, troubleshooting, member onboarding and reseller builder guides gain scoped RTL Rich expandable sections with the existing native download/action buttons. Explicit sales:help:simple:{page} preserves plain HTML fallback. No in-content Rich buttons (reserved for Phase 03), payment/provider changes or Admin Bot restyling. **Real Telegram layout and CTA/back verification remain an operator acceptance gate. Phase 03 is blocked until accepted.**

> **Phase 01 source checkpoint — 2026-10-27:** [PR #255](https://github.com/DenaSon/skynet/pull/255) promotes `sales:subs:view` Rich by default, preserves the historical `sales:subs:rich` alias and provides `sales:subs:simple` fallback. The visible trial button is retired. **Operator review of the deployed default-route Rich UI is still pending.** Do not start Phase 02 until explicit acceptance. Other phases remain planned only.

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
| 01 Service detail default | Merged PR #255; user tested default Rich on Telegram iOS | Accepted by operator 2026-10-27; cross-client/Fallback checks remain |
| 02 Tutorials | Merged PR #256, actual Telegram UI operator-accepted | Accepted by operator 2026-10-27; some client variants unverified |
| 03 Catalog / in-content CTA | Merged #257 + green Buy patch #258; operator tested | Accepted 2026-10-27 on tested Telegram client |
| 04 Home/account/referrals | PR #259 merged, tested in actual Telegram | Operator accepted 2026-10-27 |
| 05 Wallet/orders/renewal presentation | PR #260 merged and operator reviewed | Accepted by operator 2026-10-27 |
| 06 Trial/support | Merged PR #261, user reviewed real Telegram | Accepted 2026-10-27 |
| 07 Reseller builder | PR #262 merged and operator reviewed actual Telegram | Operator accepted 2026-10-27 |
| 08 Reseller reports/customer management | Merged PR #263; role-scoped reporting and customer search operator-reviewed | Accepted by operator 2026-10-28 |
| 09 Final cross-screen review | Source patch in PR/CI review; real client checks remain | Final operator acceptance **pending** |

## Phase 09 cross-screen acceptance matrix — source audit, 2026-10-28

| Surface | Primary callback/fallback evidence | Authorization and state safety | Live acceptance |
|---|---|---|---|
| 01 Service details | `sales:subs:view`; `sales:subs:simple`, old `sales:subs:rich` | Owner-only, unknown remote state and protected QR/link remain gated | Phase accepted; other client versions unverified |
| 02 Tutorials/support guides | `sales:help:{page}` / `sales:help:simple:{page}` | Role-specific builder and member onboarding; existing URLs/Back | Phase accepted; other client versions unverified |
| 03 Ready buy and catalog | `sales:buy`, `catalog:list`, green in-message Buy, `sales:buy:simple`, `sales:catalog:simple` | Buyer-repriced order flow; old read-only detail callback preserved; Phase 09 fixes page fraction | Phase accepted; revised pager requires final review |
| 04 Home/account/referrals | `sales:account:simple`, `sales:invite:simple` | Paid vs historical counts, wallet read and native invite copy/share, no new award writes | Phase accepted |
| 05 Credit/orders/renewal | `sales:wallet:simple`, `sales:checkout:orders:simple`, `sales:subs:renew:simple` | Native receipt/payment confirmations, owner checks and idempotent renewal untouched | Phase accepted; real financial/provider end-to-end not claimed |
| 06 Trial/support | `sales:trial:simple`, `sales:support:simple` | One-time private Contact/QR and terminal reply-keyboard hiding preserved | Phase accepted; all provider failure states not live-observed |
| 07 Reseller builder | `builder:quote:simple`, green guarded checkout CTA | Current calculated discount, temporary owner-only text entry and reprice | Phase accepted |
| 08 Reports/health/customers | `sales:report:simple`, `sales:health:simple`, `sales:subs:search:simple` | Only authorized reseller, six bounded remote reads per health page, unknown status and owned customer edit | Phase accepted |

**Acceptance evidence boundary:** Operator has explicitly accepted the delivered screens through Phase 08 in this conversation. Their tested Telegram client details and app versions are not documented for every screen. No Android/Desktop version-specific end-to-end verification was provided; do not claim universal Rich rendering. Prior source CI includes full PHP 8.4, PHPStan/Pint and MySQL/Redis where applicable; each UI regression is tested with fake Nutgram and/or stubbed Provider, **not a live checkout, Provider mutation or client screenshot**. Final Phase 09 client test and sign-off remain open until the operator confirms the deployed corrected pager and fallback. Admin Bot, provider writes, wallet/receipt business logic and schema remain outside this phase.

**No Phase 01–09 implementation is authorized by the mere merge of this document.** The operator will explicitly start and accept each phase in order.
