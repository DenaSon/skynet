# DenaGate — Sales UX roadmap (UXR)

Parent tracker: [#115](https://github.com/DenaSon/skynet/issues/115). The operator approved the five-phase plan and **explicitly authorized UXR-01 implementation and merge to master on 2026-10-20**. Later phases require their own start instruction. These identifiers are separate from earlier SB/MVP/UX phases.

## Baseline

The operator reports earlier tests complete: fresh VPS → connected Node/Host → real Shadowsocks client; quote/order/receipt/review and activation for multiple accounts. Later accounts required manual `/fulfillment`. The missing Admin receipt alert remains [#117](https://github.com/DenaSon/skynet/issues/117); old tests passing does not fix it. Port 2222 and database quote-cache fixes are already merged (#112/#113); F10-E tracker #24 is completed. Deferred Host Profile display debt #111 is outside this plan.

Reuse the existing identity, dual-bot boundaries, pricing/checkout Actions, receipt review/ledger, fulfillment outbox, provider lifecycle and owner-only subscription reads. No generic framework or parallel system.

| Phase | Scope / issues | State |
| --- | --- | --- |
| UXR-01 | Navigation, ready-plan home, support/guides, ready-node label: #120, #122, #125; start #126/#127 | Implemented in source; CI recorded in implementation PR; new live smoke pending |
| UXR-02 | Direct plan → payment → photo, copy/resume: #116; finish #127 | Planned |
| UXR-03 | Receipt alert/decision → automatic RW activation → customer notification: #117/#118/#119 | Planned |
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

## Remaining phase boundaries

**UXR-02:** select a ready plan and go directly to one payment message with volume/duration, exact amount/destination, native copy and direct receipt photo via lightweight ForceReply. Continue an unfinished owned order, rechecking current price/ownership/state. Remove extra UI confirmation pages while preserving backend validation/idempotency. No Admin decision or automatic RW creation yet.

**UXR-03:** diagnose the missing cross-bot receipt notification; show the receipt and only approve/reject. Record the real operator decision once with existing financial/audit boundaries, never fabricate a bank transaction reference. Use existing queue/outbox to start creation automatically after committed approval; `/fulfillment` remains an exception tool. Notify preparation and confirmed activation with connection access. Duplicate clicks/jobs or notification failures must not duplicate money/account creation; ambiguous provider results remain for review.

**UXR-04:** account name/ID/join date and valid own purchase/service counts; existing authorized reseller wallet only. Service list with recognizable labels/accurate status and active details with connection, help/support, real usage/expiry. Errors are not zero statistics; one page is not the total count. Complete consistent formatting #126.

**UXR-05:** evaluate/deploy a compatible **official RW Subscription Page** next to the existing panel, with chosen subdomain/HTTPS, DenaGate/Persian text/support, status/usage/expiry and supported copy/QR/import. Preserve old client subscription formats. Confirm browser and real client behavior; do not assume that a JSON API URL is already a web page. No custom customer dashboard or automatic panel upgrade.

Free trial, renewal, extra protocols, ticketing, Mini App, loyalty/rewards and public wallet are not in these five phases. Use current Nutgram helpers/native keyboard features; no new dependency or global Conversation rewrite. #126 remains open until phase 4; #127 until phase 2. New source/CI and real Telegram/RW acceptance stay separate.
