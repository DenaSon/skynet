# DenaGate — Sales UX roadmap (UXR)

Parent tracker: [#115](https://github.com/DenaSon/skynet/issues/115). The operator approved the five-phase plan and **authorized UXR-01 (merged in PR #128) and UXR-02 implementation via a separate PR on 2026-10-20**. UXR-03–05 still require their own start instructions. These identifiers are separate from earlier SB/MVP/UX phases.

## Baseline

The operator reports earlier tests complete: fresh VPS → connected Node/Host → real Shadowsocks client; quote/order/receipt/review and activation for multiple accounts. Later accounts required manual `/fulfillment`. The missing Admin receipt alert remains [#117](https://github.com/DenaSon/skynet/issues/117); old tests passing does not fix it. Port 2222 and database quote-cache fixes are already merged (#112/#113); F10-E tracker #24 is completed. Deferred Host Profile display debt #111 is outside this plan.

Reuse the existing identity, dual-bot boundaries, pricing/checkout Actions, receipt review/ledger, fulfillment outbox, provider lifecycle and owner-only subscription reads. No generic framework or parallel system.

| Phase | Scope / issues | State |
| --- | --- | --- |
| UXR-01 | Navigation, ready-plan home, support/guides, ready-node label: #120, #122, #125; start #126/#127 | Implemented in source; CI recorded in implementation PR; new live smoke pending |
| UXR-02 | Direct plan → payment → photo, copy/resume: #116; finish #127 | Implemented in UXR-02 branch; CI and live acceptance separately verified |
| UXR-03 | Receipt alert/decision → automatic RW activation → customer notification: #117/#118/#119 | Implemented across PRs #130/#131/#132; CI and LIVE acceptance remain separate |
| UXR-04 | Real account statistics and recognizable services/details: #123/#124; finish #126 | Planned |
| UXR-05 | Compatible official RW Subscription Page and delivery integration: #121/#119 | Planned |

## UXR-01 implementation

- A Sales-only two-column persistent Reply keyboard: **خرید سرویس / سرویس‌های من / حساب کاربری / پشتیبانی**. Entry sends the keyboard greeting and separate inline plan message; normal inline navigation edits one page. The Admin/legacy keyboard opt-in and router are preserved.
- Home reads `ListQuotedPresets` and existing `CheckPlanSquadReadiness`; at most five published, buyer-visible, currently verified presets per page. Prices are canonical integer-rial quotes displayed as tomans, including the existing reseller discount. Empty/off states are explicit. Selection uses the existing plan detail and checkout path; no order is created by browsing.
- Basic account retains own services/order history, Sales invitation deep link and the existing freshly role-checked reseller builder. No new statistics, public wallet or invitation rewards. Sales uses its own configured bot username.
- Support reads `TELEGRAM_SALES_SUPPORT_USERNAME`, validates the username and links directly to that Telegram account. Missing/invalid config means no contact button, with a truthful unavailable message and working guides. The actual operator username has not been supplied; runtime setup and opening the real contact remain operator acceptance items.
- Static guides: Android, iPhone/iPad, Windows/Mac, purchase and basic connection problems. Official Hiddify [downloads](https://hiddify.com/) and [connection instructions](https://hiddify.com/app/How-to-use-Hiddify-app/) were checked during implementation. Guides describe the current purchase flow until phase 2 changes it. Subscription credentials appear only in the existing explicit owner-authorized delivery flow.
- New/changed UI uses **DenaGate**, escaped HTML, bold title/price, sparse emoji, useful short blockquotes and spacing. The remaining old templates are intentionally completed in later phases rather than broadly rewritten.
- Primary Reply labels, Home and Cancel win over pending builder input and retire only the current bot's builder/receipt UI draft. Unknown text/callbacks get safe navigation guidance. Fresh private/active identity and existing role/ownership checks remain mandatory.
- Live subscription usage reads acknowledge callbacks before provider access and show best-effort typing. This is presentation feedback only; no new remote calls, writes or retry loop.
- Admin Node list puts **افزودن سرور جدید** first and renames manual registration to **ثبت نود آماده**, explaining that the two paths are alternatives. Actions, callback IDs, port 2222 fix, installer and confirmations are unchanged.

## Verification and live acceptance

The implementation PR must pass the full existing PHP 8.4/8.5 Pest, PHPStan and Pint pipeline plus disposable MySQL migration/rollback jobs. Focused regressions cover four-button rendering/escaping, real quote/readiness/paging, reseller access, bot-scoped cancellation, safe fallback, invitations, contact validation, guides/back and acknowledgement/typing order. Fake Telegram/HTTP tests do not prove real Telegram keyboard behavior or operator contact.

Post-deploy smoke (no provider write required):

- [ ] Private member `/start`: four buttons, ready-plan names/prices, paging/Back, empty state when applicable.
- [ ] Reseller: correct quote discount, builder available; role revocation takes effect without `/start`.
- [ ] All four destinations, invitations, every guide and Back/Home work; repeated callbacks edit without chat spam.
- [ ] Home/Cancel exits pending input without clearing the other bot's state; unrelated text/unknown button gives guidance.
- [ ] Real configured support account opens correctly. Without configuration, guides still work and contact is unavailable.
- [ ] Admin Node menu clearly distinguishes new server from preinstalled node registration.

Record live results separately; earlier-version acceptance is not evidence for these changed screens. No migration, dependency or finance/provider flag change is required. Rollback is a source revert; existing orders/provider state and callback contracts do not need rewriting.

## UXR-02 implementation and acceptance

- **Sales Bot only:** home/catalog preset selection and existing builder preview callback use the existing CalculatePlanQuote / ListQuotedPresets / CheckPlanSquadReadiness / CreatePendingPlanOrder authorization, price-revision and immutable snapshot checks. No preflight/pre-invoice/confirm page in the normal path; old `sales:checkout:preview` and `sales:checkout:commit` callbacks remain safely handled. Admin catalog, bank review and legacy routes remain unchanged.
- Fresh selection takes the buyer-scoped lock on the **existing cache store** and searches an identical durable pending order snapshot first. Repeated callbacks show the same order; explicit `sales:checkout:new` creates a separate order. An old order retains its recorded amount; a fresh purchase recalculates current pricing and cannot silently reuse an obsolete snapshot. Owned purchase history continues the exact unpaid order.
- The payment message displays escaped plan, quota, duration, recorded rial-to-toman amount and **configured real** bank card details, with native CopyTextButton for the exact 16-digit card and amount (toman integer). Sales payment creation fails closed if the bank review gate or unambiguous card number is unavailable. Set `BILLING_PAYMENT_CARD_NUMBER` to the operator's actual card; existing `BILLING_PAYMENT_INSTRUCTIONS` is also shown escaped and supports extraction only when it contains one unambiguous 16-digit card. **No default account is shipped or Production value changed.**
- The same payment step accepts a private photo without pressing another button; a short separate Nutgram ForceReply prompt helps open the image picker, without supplying payment authority or identifying the order. The existing Sales-scoped 10-minute cache pointer and ManualBankPayments Action still revalidate owner/order/review state, reject duplicates, encrypt media, and record a claim **without marking paid**. Text/documents, expired input, stale callbacks and cancel/home provide a safe next action; only the current bot's drafts are cleared.
- The existing financial ledger, receipt queue/outbox, Admin approval flow and dormant fulfillment are untouched. **#117 / UXR-03 notification, bank approval/rejection simplification, automatic RW creation and customer activation message are not part of this phase.**
- **Source acceptance:** focused DB-backed cache/lock + Nutgram fake regressions for selection/direct receipt, exact copy buttons and escaping, role/owner isolation, idempotent repeat vs explicit purchase, repricing, invalid/expired/canceled input and no Billing/provider writes; PHP 8.4/8.5 Pest, PHPStan/Pint and disposable MySQL CI are required before merge. CI is not Telegram live smoke.
- **Operator live acceptance remains OPEN:** Sales private real-member and reseller selections; actual `BILLING_PAYMENT_CARD_NUMBER` or an unambiguous real instruction card; copy into a bank app, receipt photo without replying, unaccepted/rejected/expired states, callback replay, Home and Admin Bot legacy functionality. Keep all Production flags unchanged; do not infer notification delivery or real provider fulfillment from source tests. Rollback by reverting the UX code; no schema changes.

## Remaining phase boundaries

**UXR-02:** select a ready plan and go directly to one payment message with volume/duration, exact amount/destination, native copy and direct receipt photo via lightweight ForceReply. Continue an unfinished owned order, rechecking current price/ownership/state. Remove extra UI confirmation pages while preserving backend validation/idempotency. No Admin decision or automatic RW creation yet.

**UXR-03:** diagnose the missing cross-bot receipt notification; show the receipt and only approve/reject. Record the real operator decision once with existing financial/audit boundaries, never fabricate a bank transaction reference. Use existing queue/outbox to start creation automatically after committed approval; `/fulfillment` remains an exception tool. Notify preparation and confirmed activation with connection access. Duplicate clicks/jobs or notification failures must not duplicate money/account creation; ambiguous provider results remain for review.

**UXR-04:** account name/ID/join date and valid own purchase/service counts; existing authorized reseller wallet only. Service list with recognizable labels/accurate status and active details with connection, help/support, real usage/expiry. Errors are not zero statistics; one page is not the total count. Complete consistent formatting #126.

**UXR-05:** evaluate/deploy a compatible **official RW Subscription Page** next to the existing panel, with chosen subdomain/HTTPS, DenaGate/Persian text/support, status/usage/expiry and supported copy/QR/import. Preserve old client subscription formats. Confirm browser and real client behavior; do not assume that a JSON API URL is already a web page. No custom customer dashboard or automatic panel upgrade.

Free trial, renewal, extra protocols, ticketing, Mini App, loyalty/rewards and public wallet are not in these five phases. Use current Nutgram helpers/native keyboard features; no new dependency or global Conversation rewrite. #126 remains open until phase 4; #127 until phase 2. New source/CI and real Telegram/RW acceptance stay separate.


## UXR-03 — Implementation and safe rollout checkpoint (2026-10-21)

Tracked issues: #117 (Admin receipt and final decision), #118 (automatic queued fulfillment), #119 (verified activation + owner-only delivery). Three small, independently tested PRs: [#130](https://github.com/DenaSon/skynet/pull/130), [#131](https://github.com/DenaSon/skynet/pull/131) and [#132](https://github.com/DenaSon/skynet/pull/132). Do not report source complete or merge until all CI checks actually pass, and never report live success based on fakes.

1. **Admin receipt:** Sales photo stays encrypted in the existing claim and is transferred between tokens only by the existing bounded Telegram-media bridge. The durable receipt notification has two direct Admin actions. Legacy `/payments`, `/bankconfirm` and review callbacks remain available. The root must inspect an actual received bank transfer before clicking; no automated bank verification is claimed. Old sender compatibility is preserved when the new gate is off.
2. **Single-click finance is explicitly opt-in:** `SALES_ONE_CLICK_BANK_REVIEW_ENABLED=false` by default. Direct approval creates the existing unique order settlement, single Billing journal and operator audit and records `MANUAL-CLAIM-{claim_id}` as an **internal review evidence key, NOT a bank-issued transaction number**. This marker prevents duplicate review of the same claim/order, **but cannot establish that one real bank transfer was not presented twice using different receipt images**. Existing verified bank-reference entry remains available. The operator must explicitly accept and operationally mitigate this residual cross-order bank-evidence risk before enabling one-click finance live.
3. **Automated fulfillment:** `SALES_AUTO_FULFILLMENT_ENABLED=false` by default, in addition to the existing OFF-by-default RW write + live Squad-verification gates. Only committed approved bank orders enqueue the **existing** `FulfillPaidPlanOrderJob` after commit. The reviewer ID is carried through and freshly authorized at worker execution. The job revalidates actual Billing settlement, immutable order, Squad mapping, unique operation and provider-write controls. The scheduler command `sales:fulfillment:dispatch` recovers previously missed *approved pending bank* intents in batches; it performs no RW network call. Ambiguous CREATE is never replayed, and Admin `/fulfillment` remains available for exceptions.
4. **Activation notice:** Only a completed RW CREATE with a committed order/subscription link emits unique `SubscriptionActivated` through the existing `telegram_notification_deliveries` table. The Sales recipient must have a positively started reachable bot contact, and sender rechecks buyer, order, outbox and active linked subscription before sending a fresh message. Callback `sales:subs:link:{order}:0` contains no credential; the existing Sales presenter invokes the owner-scoped, fresh-authorized subscription link Action. Sending failures never cause another RW write. The page-style connection UX remains UXR-05.

### Operator setup and acceptance — NOT automatically applied by merging

- Verify worker, scheduler and durable notifications with **both real** Admin/Sales bot tokens, and observe a Sales receipt photo delivered with the direct buttons to the trusted root. The durable sender needs `TELEGRAM_NOTIFICATIONS_ENABLED=true`; its historical suppressed intents must not be replayed.
- Keep `SALES_ONE_CLICK_BANK_REVIEW_ENABLED`, `SALES_AUTO_FULFILLMENT_ENABLED`, `REMNAWAVE_WRITES_ENABLED` and `REMNAWAVE_SQUAD_BINDINGS_LIVE_VERIFIED` unchanged until a separately authorized, controlled end-to-end verification. Bank screenshot ≠ independent proof of deposit. Never toggle test or Production controls from a PR.
- Verify actual operator-reviewed **bank transfer**, correct amount and one-to-one attribution before activation. Demonstrate duplicate callback/revoked-root/rejected receipt behavior, and specifically assess duplicate-bank-transfer presentation under the internal-review-key mode.
- Use controlled RW staging with explicit permission before any provider POST. Confirm one CREATE, one order link, customer preparation message, **later** success message and private connection button. Confirm timed-out provider CREATE moves to `needs_review` and does not generate an activation notice or retry WRITE.
- Exercise the scheduled recovery while the queue worker is healthy, and verify delivery states (`pending`, `sent`, `dead`, `unavailable`) from sanitized metadata only. Preserve old orders, credentials and links. If necessary disable the new gates before source rollback.

New CI includes DB-backed/fake-Telegram/provider HTTP contract assertions only. **Real bank reconciliation, true Telegram delivery and live Remnawave creation are independent acceptance gates.**
