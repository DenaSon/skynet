## Sales reseller Phase 08 — reports, live health and customer search (2026-10-27)

Use Rich RTL report headers for reseller-authorized current 7/30-day Persian range, actual approved turnover/transactions and outstanding pending count, with foldable new/renewal breakdown and current total/named services; never confuse all-time service totals with period-bound turnover. Keep the existing filter, refresh, status and Home buttons. Health emphasizes provider-verified **page-only** outcomes; clearly distinguish 🟢 active, 🔴 expired, 🟠 attention and ⚪ unknown, and separate local recorded count from up-to-six remote GETs. Preserve each original owner-scoped detail callback, page cursor and refresh. Customer search results should be short, show escaped customer label and recorded service summary/status (not live Provider truth), and leave the existing name-entry/edit confirmations native. Provide explicit simple fallback callbacks for all three pages and report Telegram Rich errors, with no new actor access, unbounded scans, private subscription token exposure or financial/provider writes. Operator live-client signoff remains required. [Roadmap](032-SALES-RICH-UX-ROADMAP.md).

## Sales reseller builder Phase 07 — Rich quote, native selections (2026-10-27)

Keep reseller-only numeric volume/duration selection, «حجم | مدت» free-text form, example copy, cancel/back and temporary draft **native**. Convert only the final quote to concise right-to-left Rich: prominently show the plan and selected dimensions, public price, **calculated published** reseller discount and final payable price. Add one green **in-content** «🛒 ادامه خرید» callback using the existing `sales:checkout:preview` handler, not a new payment flow; keep its original native keyboard equivalent. An optional «نکته پیش از خرید» panel reminds users that quote is not a locked price and checkout revalidates. Explicit `builder:quote:simple` re-runs buyer/plan/quote authorization; failed Rich requests fall back to text. Do not use fixed 35% marketing copy for a quote or change Admin Bot behavior. Preserve invalid/stale/expired/cross-actor input denials and financial idempotency. Operator must accept actual client display before Phase 08. [Roadmap](032-SALES-RICH-UX-ROADMAP.md).

## Sales Phase 06 — contextual trial status and support, safe native transitions (2026-10-27)

Use RTL Rich with actual 250 MiB/2-hour eligibility terms **only before** trial intake and accurate *live-read* status/quota/time prominently for active tests. Pending and uncertain states must show their real non-final wording, not success. Fold conditions and help into small details; keep existing status refresh, link, buy, direct support and Home buttons. Explicit `sales:trial:simple` returns original HTML content. **Do not Rich-edit or replace native Contact, one-time masked-phone confirmation, QR/photo/private URL, or terminal-trial reply-keyboard reset**: users must still lose the test button after traffic/time exhaustion. Sales support now shows short expandable troubleshooting/tutorial context and existing configured direct operator, service list and help CTAs, with `sales:support:simple` and historical `sales:help:simple:support` preserving ordinary HTML. Never create a ticket system or change permissions/provider/billing. Real-client checks separate from CI. [Phase 06 plan](032-SALES-RICH-UX-ROADMAP.md).

## Sales Phase 05 — finance-adjacent read-only presentation (2026-10-27)

Use Rich messages only for authenticated **read views**: `sales:wallet` shows current verified credit before optional last-5 top-up history; keep pending top-up and real resume/top-up CTAs visible. `sales:checkout:orders` shows each owned order's recorded status clearly (pending, paid-awaiting-fulfilment, fulfilled, or needs review) with the existing native view/resume action; never treat payment confirmation as live subscription activation. `sales:subs:renew` preserves provider/owner quote checks and displays only draft terms/price; an existing native explicit confirmation remains the sole entry into payable renewal preparation. The visible simple fallback and failed Rich API delivery must remain safe. Never restyle or add new side effects to checkout, receipt intake, wallet debit confirmation, financial journaling or provider operations in this phase. Keep Admin Bot unchanged. Client visual acceptance gate is separate from CI. [Roadmap](032-SALES-RICH-UX-ROADMAP.md).

## Sales Rich UX Phase 04 — account and referral clarity (2026-10-27)

Retain short native-HTML Home and six-item paginated owner-scoped service list; avoid Rich for static menus. The Sales account view shows actual role, historical service counts ("فعال در سوابق", not remote live status), paid purchase count and current wallet read prominently. Fold the sum of successful payments and Persian registration date/user ID into optional Rich details. Referral view shows earned/rewarded/registered counts, eligible remaining capacity/reward amount and the real invite URL, with eligibility/trial/reseller exclusions in optional details. Preserve copy/share native Telegram buttons and `sales:account:simple` / `sales:invite:simple` plain fallback; report Rich transport errors. No changes to referral rules, financial or provider actions. Live-client acceptance remains separate from CI. [Phase 04](032-SALES-RICH-UX-ROADMAP.md).

## Sales Rich catalog Phase 03 patch — direct green purchase in list (2026-10-27)

Operator-reviewed screenshots showed that repeating all plan details on an intermediate screen introduces needless taps. On both Sales ready-buy and Sales catalog lists, render each plan's price and terms once, followed immediately by a green in-content «🛒 خرید» button targeting the already-validated `catalog:detail:{id}:{page}` checkout handler. **Never** route a prior read-only preview callback into checkout: keep legacy `sales:catalog:preview` readable for messages already sent, but do not emit new preview links. Continue exposing unchanged native two-column purchase buttons as Telegram compatibility fallback until actual client review; retain escape/price/role correctness and payment gates. This patch does not modify financial behavior or admin screens.

## Sales plan catalog — Phase 03 Rich callback pilot (2026-10-27)

In Sales-only ready-buy and published plan list, put the actual buyer-quoted **final price** next to sanitized plan name and volume/duration without requiring expansion; show reseller's public price, calculated discount and payable price without hardcoded discounts. Keep five-item paging and original two-column price/spec buttons with unchanged checkout callback even when the Rich presentation adds read-only in-message «جزئیات این پلن» buttons. The **new** `sales:catalog:preview` callback is read-only and must never alias the existing `catalog:detail` checkout route. The Rich plan detail uses an in-content `<tg-button type="callback_data">` to that pre-existing checkout route and retains its native button as a fallback. Always revalidate plan publication/role/current quote/verified Squad/wallet/bank and idempotency in the existing checkout, NEVER trust HTML or the quoted amount. Explicit simple page/detail callbacks and reported Telegram API errors must leave the old flow usable; empty states remain lightweight. This is a real-client pilot: evaluate narrow mobile layouts, duplicate button density and cross-client rendering before considering removing any native button. No Admin Bot or financial behavior changes. [Acceptance contract](032-SALES-RICH-UX-ROADMAP.md).

## Sales tutorials Rich UX Phase 02 — scoped contract (2026-10-27)

For Sales-only tutorials, use a concise RTL Rich topic index and expandable step lists for Android/v2rayNG, iOS/V2Box, Windows/v2rayN, macOS/V2Box, purchasing, troubleshooting, member panel onboarding and reseller plan-builder guidance. Keep the page title and important client name or initial 750,000-toman credit and 35% reseller discount visible without expansion. Existing native download, service, wallet, request, builder, Back and Home actions remain usable. The explicit sales:help:simple:{page} route and Telegram error fallback preserve accessible HTML. Recheck ordinary-member setup and reseller builder authorization even for stale callbacks. In-content Rich callback buttons remain a separate Phase 03 experiment, not part of this phase. Operator live-client acceptance is needed before Phase 03; do not restyle Admin Bot or change payments or provider rules.

## Sales Rich detail Phase 01 — implementation checkpoint (2026-10-27)

For the scoped Phase 01 change, `sales:subs:view` renders the owner-verified Rich service detail directly, and the deprecated `sales:subs:rich` callback remains routed for old messages. The explicit `sales:subs:simple` callback renders normal Telegram HTML and refreshes in simple mode; it is also the fallback when Rich API editing fails. There is no more trial «✨ نمایش جدید» button. Rich header shows actual status, remaining quota and expiry above the three existing expandable sections. Preserve member/reseller permissions, sanitized customer/plan names, existing actions and no connection credentials inside Rich. The earlier opt-in-pilot paragraph below is historical and superseded **only for the scoped service-detail presentation**. Operator's actual client acceptance is still required before Phase 02. [Roadmap](032-SALES-RICH-UX-ROADMAP.md).

## Sales/reseller Rich UX — approved *plan*, gated rollout (2026-10-27)

The nine-phase [Sales and Reseller Rich UX roadmap](032-SALES-RICH-UX-ROADMAP.md) governs **planned** (not yet implemented) improvements to the Sales Bot and its reseller-role pages. Only the previously merged opt-in service-detail pilot has actual operator-reported Telegram client validation; it remains opt-in until Phase 01 is separately implemented and accepted. The operator will inspect real Telegram UX after **each** phase's green-CI PR merge and must explicitly approve starting the next phase. Admin/Super Admin restyling is excluded.

**Permanent design guidance for these Sales scopes:** show status, final quoted price, balance and primary action without requiring a `<details>` expansion; use Rich expandable blocks only for secondary data. Prefer in-content contextual CTA buttons (`RichMessageButton`/`callback_data`) on supported clients, but **do not presume** they work everywhere: pilot them in Phase 03 and preserve native InlineKeyboard fallback. Native multi-step forms, Contact input and all sensitive payment confirmations remain unchanged. Every callback must recheck role, owner, current quote/plan readiness and transaction safety. Preserve old callback navigation, copy, RTL, Persian calendar and safe escapes. Never add public credentials to a Rich body.

**Current CI release contract (PR #253):** full PHP 8.4 test suite plus PHPStan/Pint on every PR; conservative MySQL/Redis trigger on PR changes, full MySQL/Redis on master and nightly, PHP 8.5 full suite nightly. Run additional sensitive checks when warranted. Historical PHP 8.4/8.5-per-PR statements elsewhere in this document describe earlier policy and are superseded for future Sales UI PRs; they are not evidence of live acceptance.

## Sales service Rich Messages — opt-in client validation (2026-10-27)

- The existing owner-authorized Sales service detail remains the default. Its «✨ نمایش جدید» action opens an opt-in Rich Message preview through the same authorized read Actions. The preview keeps essential live status/remaining/expiry visible, with collapsible usage, purchased-plan/order and device-help sections. No extra provider call is authorized by merely expanding a section.
- Use escaped operator/customer names, RTL Rich HTML, and the current renewal, connection, subpage, reseller-label and navigation callbacks. Credentials are never embedded in rich content; opening the original connection action always re-verifies access. A simple-view button is the explicit return route; on an unsupported Rich API request, fall back to the legacy detail layout and report the Telegram error.
- The pilot requires actual iOS/Android/Desktop client verification before making Rich the default or applying it elsewhere. Legacy text/detail screens, privacy rules and expired/provider-unavailable states remain authoritative. No migration, billing/provider write, ENV change or new dependency.

## Sales Panel onboarding tutorial (2026-10-27)

Within Sales tutorials, place «🧩 راه‌اندازی پنل فروش» below «🛒 آموزش خرید» for ordinary members only; hide it from resellers and recheck access on every guide callback. Use three spaced, bold numbered headings with restrained emoji: confirmed credit (minimum 750,000 tomans), request through existing terms and identity steps, then approval and starting sales. Put the retained-credit/no-setup-fee explanation in a Telegram HTML blockquote. Use existing «💰 افزایش اعتبار» (success) and «📝 درخواست پنل فروش» (primary) actions, plus tutorial Back/Home. The under-one-hour turnaround is the operator's service commitment, not automatic timed approval; the 35% offer is approved copy, not a pricing change. Keep the existing credit and request gates authoritative.

## Trial completion presentation (2026-10-26)

After a provider-confirmed terminal trial, send one final «🎁 تست رایگان شما به پایان رسید» message with existing Buy and catalog buttons. Trial-only warnings are strictly under 10 MiB and at five minutes remaining; never send the paid 24h, 1h or 350 MiB warnings. Pre-schedule the five-minute event on the existing queue; recheck provider state before delivery. Unknown provider state remains unavailable; unknown Telegram final-send results require review rather than a duplicate message. Trial success/end failure labels remain simple Persian in the existing Admin notification list.

## Free-trial Contact flow (2026-10-26)

The persistent Sales Reply keyboard offers one optional «🧪 تست رایگان» entry to eligible ordinary members; hide it once the trial ends. Remove Trial and Tutorial from the home/account inline menu. Put «📚 آموزش» in the persistent Reply keyboard for both ordinary users and resellers; trial eligibility still excludes resellers. Activation/end messages refresh the persistent keyboard; the end message removes Trial and retains native Buy/catalog navigation. Use a temporary private `request_contact` Reply keyboard only while collecting the native self-contact; afterward restore the persistent Sales keyboard. Show a masked, read-only number with explicit one-use confirmation. Trial status uses MiB plus hours/minutes, a separate owned link/QR action, and no renewal. No engineering terms or raw phone in stored drafts/callbacks. Paid paths and Admin navigation remain unchanged.

## Two-column preset rows (2026-10-26)

- Each preset row contains a plain-text toman price button first (left) and specification button second (right); both reuse the same existing plan-selection callback. No inert price cells or copy-only surprises.
- Specifications use a sanitized name of at most 14 characters plus compact volume/duration (or unlimited duration). Full names/specifications and bold prices remain in the message body above the keyboard.
- Use the actual buyer quote, including reseller discounts, and preserve ascending-price order and pagination. Ready-list buttons share their existing primary style; ordinary catalog rows retain their existing neutral style. Navigation remains separate. Telegram controls exact widths and client RTL presentation; confirm the final mobile rendering after deployment.

## Preset list price hierarchy (2026-10-26)

- In the Sales catalog, omit the generic price-change, buyer-price and checkout/recheck explanation lines. Keep any specific reseller-price or legacy unavailable-purchase notice.
- Bold each final amount together with its toman unit via Telegram HTML; escape sanitized plan names. Button labels remain plain native Telegram text with volume/duration.
- Both preset lists use ascending current buyer-quoted final prices before pagination, including reseller discounts. Equal-price plans retain the existing catalog priority. Display simplification never removes server-side repricing, publication, ownership or readiness checks.

## Preset plan selection labels (2026-10-24)

Ready-plan Buy and the full preset catalog use the same plain-text button label: `پایه · 10 گیگ · 15 روز`. Read volume and duration from the current buyer-authorized quote; time-only plans show `نامحدود`. Shorten only names longer than 20 characters, preserving the complete volume/day suffix. Strip control/bidi-override characters and do not HTML-encode inline button text. Existing callbacks, pagination, native styles, prices and readiness/ownership checks remain unchanged.

## CF-02 — DNS management UX

- One private root-only «🌐 مدیریت DNS» entry under Admin server overview; no new persistent main Reply button. Zone selection uses only the approved Cloudflare allowlist.
- Page through record names/types in small groups; do not display TXT or other sensitive DNS values. Only subordinate A records with public IPv4 expose edit and Proxy actions. Apex records remain read-only.
- Single-field prompts for subdomain and IPv4, native copyable sample domain, clear Back/Cancel. Show old/new IP and DNS Only/Proxied mode with a warning that Proxy can disrupt direct connections. Apply a one-use confirmation; if outcome is uncertain do not replay automatically, direct operator back to fresh read.
- Reuse CF-01 Application authorization and DNS transport; no additional menu for Sales or role expansion. Native Telegram semantic colors for positive actions and concise Persian labels; no framework/phase names in Telegram text.

# DenaGate — Telegram UI/UX Identity (Normative)

**Status:** Permanent, normative presentation contract for every future Telegram feature, including Super Admin. Adopted 2026-10-13. [PR #48](https://github.com/DenaSon/skynet/pull/48) implements shared language/copy components, grouped administration and guided Sales forms. Broad customer/finance workflow redesign and actual live Telegram smoke remain separate work.

## Product promise

DenaGate is a practical Persian Telegram bot. A person can administer products, plans, users and prices without knowing databases, frameworks, or internal architecture. Preserve **every existing capability and Action**: simplification must never remove functions, bypass authorization, or change price, money, order or provisioning rules.

## Prelaunch branding guard — 2026-10-22

Admin product and plan list titles, the web login heading and the operator's Telegram account-link instruction now use Laravel `config('app.name')` rather than the retired ParsaGate brand. The application fallback and `.env.example` identify DenaGate. Static Artisan metadata is worded neutrally where modified.

Keep historical documentation, remote `SkyNetwork ref:` ownership markers, existing helper environment variable identifiers, synthetic account email domains, and persistent database identifiers unchanged: these are internal compatibility contracts, not UI copy. Verify the deployed `APP_NAME` separately; changing this setting can also change default Redis/cache prefixes and the session cookie name. Any production config adjustment must preserve queue and session continuity. GitHub CI is not live Telegram acceptance.

## Visible language

- The public-facing product name in new/changed UI is **DenaGate** (updated 2026-10-20, UXR plan). Older ParsaGate text is migrated only in the current feature scope. Retain technical identifiers and historical records; do not rebrand infrastructure or stored references.
- Warm, direct, concise Persian. One screen means one goal or decision. Use short headings, labeled values, one primary call to action, clear Back/Cancel.
- No engineering vocabulary in Telegram: milestones F09/F10/F11, UX labels, Laravel, Nutgram, Remnawave, API, CI, PR, E2E, database, migration, DTO, provider, revision, implementation status, operation codes, or developer commentary. Engineering detail belongs in code, documentation, logs and audits. Human record identifiers are allowed when needed for copy/reconciliation.
- Say what a feature can actually do. For an unpurchasable plan, say 'خرید این پلن فعلاً فعال نیست.' Do not simulate a purchase CTA.
- A validation error must explain the next step, not print internal exceptions. Sensitive confirmation names the object and its exact effect; avoid verbose boilerplate.
- Present money in clearly labeled tomans; calculations still use integer rials in Sales/Billing.
- Emojis are functional landmarks, not decoration. Status words: active = '🟢 فعال', inactive = '⏸️ غیرفعال', draft = '📝 پیش‌نویس', archived = '📦 بایگانی‌شده'.


## MENU-01 — native Sales command menu (approved 2026-10-22; supersedes Sales ReplyKeyboard requirement)

The operator explicitly approved replacing the **Sales-only** permanent four-button ReplyKeyboard with Telegram's native `☰ Menu` command list. Existing Sales `/buy`, `/subscriptions`, `/account`, `/help` are the four menu destinations (the first is a minimal alias to the existing authorized Buy presenter). Sales `/start`, `/menu` and stale Home text remove the previous keyboard via `ReplyKeyboardRemove`; Sales inline submenus and all previously accepted text labels remain available. This scoped override supersedes the previous four-button persistent requirement in items 5, Navigation and UXR-01 below **for Sales only**. The previous UXR-01 and PR #139 descriptions are historical code/acceptance checkpoints, not new instructions to reinstate the Sales ReplyKeyboard. Admin/legacy role-aware keyboard and commands are unchanged. Menu commands are installed only by a separately invoked, token-guarded `sales:telegram:menu:register` after deploy, not on every webhook. A Telegram native client determines icon and location; the app cannot move the emoji button.

## Permanent Telegram visual and writing standard — all bots (approved 2026-10-21)

**Binding design rule, not a new feature phase:** Apply to every *new or modified* screen in both Sales and Admin Telegram bots, including privileged screens. Prefer clean, accessible, minimal Persian UI; keep unchanged paths working. Do not launch a broad UI rewrite without a separate approved scope.

1. **Native semantic colors, sparingly:** Nutgram/Telegram `ButtonStyle::SUCCESS` (green) for the principal positive action, `PRIMARY` (blue) for useful navigation/selection, `DANGER` (red) only for rejection/destructive actions, and default neutral for ordinary Back/Cancel/account/help. Not every button needs a color. Native Telegram clients own rendering; no arbitrary hex/RGB, no false promise of identical appearance on older clients or themes. Existing DenaGate teal/navy branding is for brand graphics or supported web surfaces, not a configurable Telegram-button palette.
2. **Relevant icons only:** Use one recognizable Unicode emoji when it improves scanning (🛒 خرید, 📋 سرویس‌ها, ✅ تأیید, ❌ رد, ⚠️ هشدار, 🏠 خانه). Keep the label understandable without the emoji or background color. Prefer Unicode over `icon_custom_emoji_id`; custom emoji IDs need eligible accounts/bots and explicit assets, never fake them.
3. **Text hierarchy and spacing:** One screen = one intent; short bold heading, blank line between logical groups, brief sentences and compact lists. Highlight important figures and actual prices with selective `<b>…</b>`, not entire paragraphs; clearly label prices in toman. Use short `<blockquote>…</blockquote>` for a genuinely useful note/caution, not for decoration. Use Telegram-supported HTML `parse_mode: 'HTML'`, with `TelegramUi::escape()` for all dynamic/customer/operator-provided content before inserting into markup.
4. **Copy and input:** Use `<code>` for short identifiable values and copyable examples. Provide native `CopyTextButton` when practical (length and privacy constraints); never paste secrets into public or shared chats. Report unavailable/unknown/awaiting states truthfully, without technical errors, misleading zero values or decorative success indicators.
5. **Navigation and actions:** Small persistent Reply Keyboard for *primary destinations* where still supported (Admin/legacy); the Sales Bot uses its MENU-01 native four-command hamburger list, not a persistent keyboard. Inline Keyboard for nested objects, paging, Back/Cancel, read/refresh and one-time sensitive confirmations. The Admin primary keyboard stays role-aware. Button presses must work; historic labels/callbacks remain routed and freshly authorized. Avoid duplicated greeting messages on callbacks and avoid editing media as text.
6. **Accessibility and review:** Status must remain legible in words, not color alone. Ensure readable RTL Persian wording, restrained emoji, concise labels, a valid return path and clear error recovery. Review layouts and serialized native styles on PHP 8.4/8.5; verify actual Telegram client rendering separately after deployment. No additional packages, UI framework, permissions or payment/provider side effects for visual polish.

**Reference implementation (source-only):** [PR #139](https://github.com/DenaSon/skynet/pull/139) merged on `master` as `0f843898a`: Sales two-row Reply menu, green Buy/blue Services, styled Inline Buy/plan selection, old «🛒 خرید سرویس» text still accepted. The PHP 8.4/8.5, Pest (642 tests / 4,030 assertions), PHPStan, Pint and disposable MySQL PR checks passed; **real Telegram color/route smoke is not recorded**. The rule is global for *future scoped work*; PR #139 did not restyle Admin or every existing message.

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

- Persistent ReplyKeyboard (when opt-in enabled, on Admin/legacy) only has small role-aware primary destinations. Sales uses native commands per MENU-01. Admin subfunctions are grouped, not placed in a wall of buttons.
- InlineKeyboard is for object actions, selections, Back, Cancel and confirmations. Stale historic Reply labels plus old commands and callbacks MUST remain routable and re-authorized; never delete an Action to simplify a menu.
- Navigation is contextual; Back returns to a parent, Cancel makes no mutation. No inert buttons. Never assume hiding a button replaces server authorization.

**WUX-03 extension (2026-10-25):** In the Sales Bot, top-up resume reads only owner-scoped SQL `pending` requests, never trusts the callback ID or a stale cache alone, and never makes a second financial request. Starting a new top-up while one is pending requires an explicit separate choice. Back/Cancel or order navigation disarms the appropriate temporary photo intake; /cancel and navigation also invalidate the current wallet debit confirmation token. Existing historical purchase-order resume remains in «سفارش‌های من»; new purchases are explicit and remain distinct. No broad restyling or business-logic change.

**WUX-02 override (2026-10-25):** In the Sales Bot, an ordinary buyer's bank payment page never arms photo intake or auto-sends ForceReply. The green explicit «ارسال رسید پرداخت» action revalidates current order/payment/ownership gates and alone arms a ten-minute photo draft. The old `sales:payment:start` callback is payment *display* only. A bank+wallet eligible buyer sees both options without an extra form. Preserve legacy/Admin entry and the original receipt-submission Action. This supersedes only the earlier UXR-02 ForceReply/photo-entry timing; phase 3 state-resume polish is not part of this change.

## UXR-02 — Sales-only payment presentation (2026-10-20)
- Preset selection from Sales home or catalog opens the one-screen payment directly, with fresh buyer/price/Squad validation; no separate preflight/preview/confirmation page. Existing callbacks remain safe, Admin and legacy routes remain independent.
- Render the **recorded order price** in bold tomans, with plan/traffic/duration and only real configured destination details. Escape all dynamic text in Telegram HTML; use native CopyTextButton for exact bank card and amount. Do not ship sample or inferred payment details.
- A short optional ForceReply is a UX hint for photos, **never** proof of ownership or payment; photo submission verifies current private Sales account, owned payable order, 10-minute scoped state and duplicate media through existing Actions. After receipt: «رسید دریافت شد؛ منتظر بررسی مدیر باشید». No Sales approval or provider writes.
- A repeat tap resumes the same owned pending order; «خرید جدید» is explicit. Expired/non-photo input and Home/Cancel show safe way forward without wiping Admin bot input. Financial and UXR-03 scope remains unchanged.

## UXR-01 — Sales presentation contract (2026-10-20)

- **Historical UXR-01 rule, replaced by approved MENU-01:** Sales formerly installed a persistent two-column Reply menu on `/start`/entry: «🛒 خرید سرویس», «📋 سرویس‌های من», «👤 حساب کاربری», «💬 پشتیبانی». MENU-01 now clears it and registers those destinations as native commands. The separate legacy/Admin hybrid-menu opt-in still does not control Sales. Existing callbacks edit their page instead of sending another home greeting. Reply and inline keyboards use separate messages, as required by Telegram.
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
- Follow the current CI policy recorded above: full PHP 8.4 Pest, PHPStan and Pint before merge, conditional MySQL/Redis for PRs and nightly PHP 8.5. GitHub CI is not proof of live Telegram E2E; separately record which screens are manually verified.
- Preserve Back/Cancel, old callbacks and commands, and the enabled/disabled behavior of all flags.
- Release increments: UX-01 shared language/design vocabulary; UX-02 grouped navigation; UX-03 guided admin forms; UX-04 user catalog/builder/shop/order polish; UX-05 users/RBAC/finance polish; UX-06 exhaustive regression and live smoke.
- A PR may finish only a subset. Mark incomplete flows explicitly in docs/000-CONTEXT.md, rather than calling the entire redesign done.

Review this standard before every future Telegram UI feature.

## CF-01 — optional DNS in the existing server wizard

- After server name/IP, offer «انتخاب دامنه» only when a scoped Cloudflare token, explicitly allowed zones and an enabled DNS write switch exist; «ادامه بدون دامنه» must preserve the original IP-only steps.
- Ask for one selectable domain and one copyable/example subdomain. Never use Cloudflare as a substitute for SSH/Node IP or show token/zone IDs to users.
- Only after a freshly connected Node, show the exact FQDN/IP/DNS Only preview and require one-use confirmation before creating a DNS record. A matching existing A is reused; different records are not silently overwritten. Failure/uncertainty is not success and never automatically repeated; a separate button checks the remote state read-only.
- Create the existing Remnawave Host disabled with the verified FQDN, preserving manual Host enablement, Back/Cancel, legacy Node paths and clear Persian error messages. CF-02 standalone DNS UI is separate.

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


## UXR-03 Sales/Admin payment-to-connection message contract

- A customer-submitted receipt is **awaiting bank review**, never a successful payment. Admin receives the protected receipt image, short plan/order/amount context, and only two direct decision buttons when the gated mode is enabled. The decision is the operator's final explicit attestation of a separately verified real bank transfer, not bank API verification.
- A committed approval may show «پرداخت تأیید شد؛ اشتراک در حال آماده‌سازی است» **only while automated provisioning is actually enabled and verified**. An uncertain/failed create is not described as active. Rejected receipts may be resubmitted through the existing purchase flow.
- Only after the real RW result and owned order/subscription mapping are committed, send a fresh «✅ اشتراک شما فعال شد» Sales message with «🔐 نمایش کد اتصال». The callback contains only an order identifier; a new owner/authorization check precedes the private connection link. Receipt/photo and connection credentials never appear in public chats, logs or callbacks.
- Legacy payment and fulfillment management commands remain available to authorized operators for exceptional/manual recovery; simple Persian is shown to Telegram users, with internal mechanism and rollout flag names restricted to developer documentation.


## UXR-04 — Account and owned services

- The Sales account displays a modest summary of the active linked identity, actual membership date, all paid/fulfilled sales-order totals (not unpaid orders) and registered owned services. All money remains calculated in rials and labelled in tomans; reseller wallet stays reseller-only and re-authorized.
- «📋 سرویس‌های من» lists only actual linked services, not orders awaiting payment or activation. Use the plan/name, traffic and duration in short readable lines; a button opens details directly. The stored local `active` state is labelled «فعال در سوابق» and must not be misrepresented as real provider usability or expiry. The customer sees verified current usage, quota and expiry only after the owner-authorized provider read succeeds.
- Show «🔐 نمایش کد اتصال» only after the verified link/usage read succeeds. Use the existing private ownership Action at the final click too. Offer «آموزش اتصال» and «پشتیبانی» without invented operator handles or tickets.
- Short escaped HTML headings, labelled lines, bold monetary totals, a single brief blockquote when useful, clear navigation and descriptive status words take precedence over decorative UI. Failed reads show unavailable/retry, never artificial zero statistics.


## UXR-05 — Official subscription browser page handoff

- «🌐 صفحه اشتراک» is a separate, optional action near «🔐 نمایش کد اتصال» in the Sales-only service detail and success message. It appears only after an operator configures the exact live Remnawave Subscription Page hostname; otherwise the legacy private connection flow is unchanged.
- Page callbacks carry only order ID and cursor. On every click, recheck private buyer, owned order, active provider identity and expiry through existing Application Actions; accept only the exact HTTPS host returned by RW, then show a protected private Telegram message with the real browser-open URL and native copy when permitted. Never fabricate or log subscription credentials, append short UUIDs, or hand out panel management URLs.
- The **official** Subpage Builder is responsible for DenaGate/Persian branding, mobile use, QR/import, real usage/expiry and customer instructions. Laravel does not render the web page. Missing external deployment/provider readiness must be described as unavailable, not activated. See [docs/027](027-UXR05-SUBSCRIPTION-PAGE-RUNBOOK.md).
\n\n## CLOSE-01 — Single decision payment review\n\n- Admin receives a receipt photo with only «✅ تأیید پرداخت» and «❌ رد رسید», never «بررسی تأیید», typed `/bankconfirm`, or another confirmation screen. Payment decisions happen exactly once on root-private callback; repeating/forging cannot duplicate a journal.\n- If customer notification or automatic RW creation is unavailable, approval must show a short actionable message, **without changing** the claim/order financial state. This prevents the old paid-order/manual-`/fulfillment` trap. Pending order fulfillment detail is status/recovery only.\n- Rejection notifies buyer and does not create RW. Only confirmed remote completion is described as active and yields the private Sales connection action. Finance/provider internal details and token names stay out of Telegram messages.\n

## NAV-01 — Admin media return and Sales true home (2026-10-21)

- Admin's existing `menu:admin` callbacks must always return to the authorized management menu. When the callback is attached to a photo or other media, do **not** attempt `editMessageText` on media; answer the callback and send a new text menu while leaving the media intact. Text-origin callbacks retain in-place `TelegramUi::editText` behavior. This avoids Telegram 400 and repeated update retries after receipt-photo navigation; do not hide unrelated 400 failures or skip RBAC.
- Sales `menu:home` and `sales:home` mean the **main Sales menu**, not the ready-plan catalog. The minimal home displays Buy, My Services, Account and Support, plus an all-plans shortcut; the persistent two-column Reply menu remains unchanged. Buy opens the existing buyer-validated, paginated ready-plan view, and legacy `sales:home:{page}` callbacks still open its original page. The current checkout/quote, purchases, owned services, Support, credentials, notifications and provider behavior stay untouched.
- Test source navigation across text and media, Sales /start /menu/callback, stale offer pagination, active role authorization and no business-side changes. Operator Telegram acceptance remains separate from fake Nutgram/CI.

## Sales native button styling (2026-10-21)

- The four existing Sales primary destinations remain a persistent two-column Reply keyboard, while nested plan selections, account/service details, help, Back and confirmations remain Inline. No new router, Admin change or callback contract.
- Sales Buy uses the native Telegram `success` (green) style and a simple green-dot label; Services uses `primary` (blue). Account and Support stay neutral. The matching Home and plan-selection inline buttons use native `success` / `primary` respectively. Existing keyboard text «🛒 خرید سرویس» remains an accepted alias for older client keyboards.
- Telegram controls colors; older clients may render default colors. Custom emoji ID icons are not used because they require eligible bot ownership/Premium. Ordinary Unicode emoji remain supported. Nutgram 4.50.3 exposes `ButtonStyle` natively; no dependency, feature flag, migration, finance or provider changes.
- Source tests cover serialized styles, four-button layout, historical text, and preserved callback routing. Real Telegram presentation is an independent post-deploy check.

## Targeted customer guide and live subscription detail (2026-10-21)

- Sales guides use verified direct 2dust GitHub releases for Android `v2rayNG` and Windows `v2rayN` and official Apple App Store for iOS/macOS `V2Box`. Never mention the backend provider brand or link to its documentation in buyer messages. Preserve existing guide callbacks and home/support destinations; live imports still need device verification.
- For authorized owned linked services, detail performs a fresh read-only provider GET even when remote subscription status is expired, disabled or limited; show actual live status/expiry/traffic and truthful unavailable state on failures. Only ACTIVE, unexpired, non-exhausted credentials can be delivered; `GetSubscriptionLink` remains strict and revalidated. Local aggregate expired totals are not invented. No write, schema, sync or new provider operations.

## Reseller checkout — username-only customer identity (2026-10-27)

The Sales Bot collects one customer-owned *display label* before a reseller's new paid managed-plan purchase. Accept a one-line, non-empty 1–60 character name including single-word usernames; this is NOT a Telegram username or provider identity. The minimal prompt contains exactly a bold heading «👤 نام کاربری مشتری», the sentence «نام کاربری دلخواه مشتری را وارد کنید.» and a blockquote «این نام برای شناسایی سرویس در پنل فروش شما استفاده می‌شود.»; no example or step number. Keep Cancel / Orders navigation. Then show the existing order and wallet payment confirmation without a mobile step. Payment must still verify the order owner's username, authorized reseller identity, exact settlement, balance, immutable order and provider readiness; do not change wallet/renewal/provider logic.

Reseller service detail, name-only search, and reports should not solicit, display or count customer phone numbers in newly rendered UI. Existing customer labels and historic nullable sales_service_customer_labels.phone values remain untouched. Already-delivered legacy phone editing callbacks stay valid for compatibility until a separately approved cleanup; never corrupt existing records. No migrations, destructive cleanup or phone-value backfill in this scoped change. Any future physical removal of phone must be separately approved with a live-data inventory and safe rollout.

## Sales-only credit terminology (2026-10-27)

All newly rendered customer and reseller Sales Bot messages use **«💰 اعتبار من»** for the account entry and account heading, **«اعتبار»** for available funds, payment, top-up and referral credits, and **«افزایش اعتبار»** for funding actions. Apply this to Sales checkout, reseller-panel request, Sales notifications and Sales/legacy reseller navigation. Keep the underlying wallet Actions, Billing ledger, callback data, Cache keys, routing, settlement validation and accounting/audit identifiers unchanged. Previously issued «کیف پول» Reply buttons and callbacks must continue working; keep their accepted input labels, not their visible placement. Do not rename Admin Bot finance/review wording. This is wording-only: no new migrations, balance changes, provider writes or notification recipients.

## Admin purchase receipt — buyer tracking patch (2026-10-27)

Admin Bot purchase-receipt notifications and protected receipt photos use a shared compact, plain-text caption with local buyer display name, internal user ID, linked numeric Telegram ID (or «متصل نیست»), receipt/order IDs, plan, amount and the bank-verification warning. This is account metadata, NOT verified bank payer identity. Normalize untrusted display name and plan into one bounded line. The durable ReceiptSubmitted photo, photo-unavailable text fallback, legacy sender and manual bank:show must remain consistent. Existing purchases.view / purchases.review authorization and delivery fences apply; no broad support profile shortcut. Preserve the approval/rejection callbacks, photo protection, cross-bot transport, finance/provisioning logic, database schema and recipients. No migration, new dependency or live provider/financial action.
