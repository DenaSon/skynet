**CLOSE-01 authorized by operator 2026-10-21:** Only the optimized final bank receipt Approve/Reject callback flow may remain in Admin Bot. Retire obsolete `/bankconfirm`, `bank:choose`, `bank:apply` and ordinary manual CREATE confirmation; preserve exceptional read-only provider reconciliation. Do not commit approved money unless automatic fulfillment and customer notices are ready; maintain the financial ledger, real-bank manual attestation, root RBAC, one-create idempotency, default-OFF sensitive flags and owner-only Sales activation link. **No Production flag changes or actual bank/RW testing without separate approval.** Source/CI and real E2E acceptance are separate. See [docs/026](docs/026-SALES-UX-ROADMAP.md). Existing older UXR execution notes below are historical checkpoints.\n\n# ParsaGate — Agent Instructions


**UXR-05 approved execution — 2026-10-21:** The operator authorized the final UX phase #121. Use the official Remnawave Subscription Page, not a new Laravel customer dashboard. Scope is only a default-disabled, exact-host-validated **private** Sales Bot handoff using the pre-existing owner/active provider subscription link Action, plus a read-only deployment/branding runbook [docs/027](docs/027-UXR05-SUBSCRIPTION-PAGE-RUNBOOK.md). Preserve #119 activation messaging and client subscription URLs. Actual DNS/TLS, panel API token, `SUB_PUBLIC_DOMAIN`, Docker/proxy/branding and real provider operations require separately authorized and verified infrastructure work. CI passing is not live page acceptance. The earlier “UXR-05 out of scope” paragraph above is historical for UXR-04 and is superseded **only for UXR-05 source integration** by this checkpoint.


**Current authorized scope — 2026-10-21:** UXR-01/02/03 are merged in PRs #128/#129/#130–#132; UXR-03 source/CI passed, but all sensitive real-bank/Telegram/RW live acceptance and default-OFF rollout gates remain pending. The operator has now authorized **UXR-04 only** per [docs/026](docs/026-SALES-UX-ROADMAP.md): own account summary, correct paid-order and linked-service counts, reseller-only wallet, service list/detail/connection/help/support with escaped Persian UX and full owner checks. UXR-05 remains out of scope. The provisioning table does not store verified remote expiry; do not fabricate expired counts or treat local active state as live provider status. Preserve the Admin/Sales bot separation, all finance/provider guards, legacy routes, and use DenaGate in changed customer UI. Every master merge auto-deploys; default financial/provider flags remain unchanged. Source/CI vs real Telegram/RW acceptance must be recorded separately.

**EPIC-SB (approved 2026-10-17):** Follow [docs/025](docs/025-SALES-BOT-ROADMAP.md). SB-01 adds only an isolated Sales Bot identity/navigation entry, without any checkout, money, provider writes, public activation or Admin route removal. Source/CI and live Telegram acceptance are distinct.

**Latest operator staging/plan checkpoint (2026-10-16):** Remnawave 3.4.4 staging is live and read-only API + scoped direct test-user CRUD were operator-verified; RW-03 remains partial (no Node, no live Laravel idempotency); next **planning-only** Admin Bot scope is SRO-01–03 / MB-05B-live P0 server overview. See [docs/024](docs/024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md). All older 'server unavailable' text reflects its original date. Keep provider writes OFF, root-only/private UI, Sales OFF; this update does not authorize production flags or code.\n\n
ParsaGate is a Laravel 13 application being developed as a **modular monolith**.

## Approved execution roadmap — Multi-Bot foundations while Remnawave staging is unavailable

**Next work is governed by [docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md](docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md), updated 2026-10-15.** The MB-01 architecture/route inventory was accepted in [docs/019](docs/019-MB01-TELEGRAM-ROUTE-INVENTORY.md) and [ADR 0009](docs/adr/0009-dual-telegram-bot-boundaries.md), accepted as documentation-only via [PR #52](https://github.com/DenaSon/skynet/pull/52) and PHP 8.4/8.5 CI. **MB-02 MERGED on `master` via PR #53 (`213458f`); PHP 8.4/8.5 and disposable MySQL migration CI green; real Production schema migration and Telegram smoke remain unverified**; MB-03 router isolation is merged via PR #55 (`e8a780b`; PHP 8.4/8.5/MySQL CI green; live Telegram smoke pending), preserving 113 original routes through the old token, while Sales remains an OFF technical shell with no Admin handlers. **MB-04 MERGED via PR #56 (`8da5e13`)**: verified active Admin/Root starts at dedicated Admin home; buyer/reseller navigation is hidden only in that home, not removed from the original token's legacy routes. All callbacks, permissions, forms, copied examples, linked nonadmin users, live DB and webhook remain until a separately approved cutover; CI and real Telegram smoke must be tracked separately. **MB-05A MERGED PR #57 (`580c64a`)** adds a default-OFF, DB-backed notification outbox for bank receipt/review, retriable delivery and bounded cross-token media bridge; do not claim real BotFather delivery from HTTP fakes. Follow [MB-05A runbook](docs/021-MB05A-NOTIFICATION-OPERATIONS.md). **MB-05B OFFLINE MERGED via PR #58 (`480f2a9`)** introduces root-authorized, fail-closed Admin read DTOs and a plan-revision Squad guard with separate real-panel verification flags OFF by default; live panel status is NOT verified. [Split acceptance matrix](docs/022-MB05B-OFFLINE-READINESS.md). **MB-06A code/CI checkpoint PR #59:** role/route parity, privacy-safe `telegram:ops:status`, bounded 429 retry, disposable MySQL MB-05A/B upgrade/rollback smoke. **Operator Production checkpoint 2026-10-16**: 23 migrations reported `Ran`; six read-only Super Admin areas and receipt-list callback navigation passed; `www` database queue worker observed and Runflare Cron execution operator-confirmed; PHP 8.4/8.5 + MySQL [post-merge CI green](https://github.com/DenaSon/skynet/actions/runs/38871578375). **Operator additionally reported live Telegram role smoke passing for Member/Reseller/ordinary Admin/Super Admin on 2026-10-16; current `master` `56e3826` CI is green. Subsequent operator-confirmed 5/5 live Admin/Reseller revocation checks passed without `/start` and with no new Telegram processing errors. Full MB-06A sign-off remains open** for any unrecorded route-level checks and observed Production job completion; [acceptance runbook](docs/023-MB06A-RELEASE-VERIFICATION.md). Do not conflate this with MB-06B, public Sales Bot, real Remnawave or legacy NVL Core. RW-01–03 remain blocked by missing Remnawave staging; no real provider/sales activation. One Laravel/backend/DB, shared authorized Actions; no unnecessary abstractions. Every `master` merge auto-deploys: keep future code additive, guarded, tested and rollback-safe, provider writes OFF.

## Mandatory Telegram UI/UX identity

**Brand naming (updated 2026-10-20):** DenaGate is the public-facing product name in new/changed Telegram UI. Older ParsaGate messages are migrated only within the approved feature scope; do not perform a broad rebrand. Preserve technical identifiers (repository name, existing stored references, provider ownership markers, DB names, URLs and webhook contracts) to prevent regressions. Production `APP_NAME` and BotFather profiles require independent operator updates.

Read [docs/017-TELEGRAM-UX-IDENTITY.md](docs/017-TELEGRAM-UX-IDENTITY.md) BEFORE any Telegram presenter/menu/form/message change. The user requires extremely simple Persian UX even for Super Admin; do not expose developer vocabulary, milestones, implementation notes or framework names in Telegram UI. Use native CopyTextButton and escaped HTML code for user-editable examples. Preserve all Actions, legacy callbacks/commands, security guards, and financial/provisioning boundaries. Use guided forms with a fast-entry fallback. Test copy text, cancel/back, role revocation and original commands on PHP 8.4/8.5. Keep the normative identity document current for future development.

## Current tooling
- PHP 8.4.1+, Laravel 13, Pest 5, Larastan 3, Laravel Pint.
- Check with `php artisan test`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test` (or `composer quality`).
- Never commit local Composer mirror URLs to `composer.lock`; lock files must install on GitHub-hosted CI.

## Start here
1. Read `docs/000-CONTEXT.md` before changing code.
2. Read the relevant architecture/module/roadmap documents.
3. Inspect the actual code, merged PRs, issues, migrations and most recent CI; documentation never overrides observed behavior.
4. **Current checkpoint (2026-10-13):** F11-CATALOG-01 root-only /products, F09 legacy duration tariff management, cache one-shot previews and Sales append-only audits are delivered via [PR #47](https://github.com/DenaSon/skynet/pull/47) (PHP 8.4/8.5 CI green, 313 tests/1979 assertions; Telegram smoke pending). No existing product/order data is deleted.  F11-B1.1 plan catalog (#37) and **F11-B1.2 managed pricing** ([PR #39](https://github.com/DenaSon/skynet/pull/39)) are merged on `master`; B1.2 squash `027c83c95a3d0bcf89f7ad8682c5a654d5c0e924`, [master CI 38245902790](https://github.com/DenaSon/skynet/actions/runs/38245902790) green (PHP 8.4/8.5 Pest/PHPStan/Pint). NEW plans are **read-only quotes**, not purchasable: B1.4 Nutgram management ([PR #44](https://github.com/DenaSon/skynet/pull/44)) is completed independently of Remnawave; B1.5 read-only preset discovery ([PR #45](https://github.com/DenaSon/skynet/pull/45), CI green) follows B1.4; B1.6 reseller-only builder preview implemented in [PR #46](https://github.com/DenaSon/skynet/pull/46), PHP 8.4/8.5 CI green (305 tests/1898 assertions); live Telegram smoke remains unverified. B1.3 Squad mapping and guarded checkout still gate activation. F09 checkout unchanged, all financial and provider write gates remain OFF. No bank/Telegram/Remnawave E2E or production DB contention tests; production already has real operator identities; never perform destructive migration resets.
5. Make the smallest change that solves the current task, and add or update tests.
6. Update at least one relevant document in the **same PR** as any meaningful development change.

## Identity reminder
- The first Identity slice uses session + CSRF, no public registration or seeded admin credentials.
- See `docs/003-MODULES.md` and ADR 0003 before modifying user roles, access checks, or auth routes.
- Preserve last-active-admin and active-user session guards in tests.

## Telegram-first presenter (F4)
- Telegram handlers live in `app/Interfaces/Telegram` and may call approved Identity/Application actions; never import Identity/Infrastructure models or bypass role checks.
- Only the documented webhook route can run Nutgram in HTTP; verify its independent secret before processing any update.
- Private Telegram ID and single-use link tokens are the only approved identity join in F4.
- Livewire/Mary UI and public REST APIs remain deferred. Shared Application Actions are the intended integration contract; do not introduce provider/network actions in Telegram handlers.
- See ADR 0004 and `docs/006-TELEGRAM-OPERATIONS.md` before Telegram changes.

## Fixed boundaries
- One Laravel application, one deployment unit, and one primary database.
- Modules are organized under `app/Modules/<Module>/`, registered explicitly in `bootstrap/providers.php`.
- Keep domain/application logic independent of HTTP, Livewire, and Telegram interfaces.
- Avoid unnecessary repositories, interfaces, events, or package dependencies.
- **Remnawave is selected as the external subscription panel API**, pinned to OpenAPI v3.4.4; F10-A/B/C/D are merged. The ParsaGate transport/network-control-plane architecture (SSH/WireGuard/Core/Edge/agents/tunnels) is **not** selected by this Remnawave decision. Do not invent those implementations without a separate architectural decision.
- Do not copy the existing Python NVL bot architecture into this foundation.

## Before declaring a task complete
- Run applicable tests / formatters when an execution environment is available. Otherwise say explicitly that they were not run.
- Check module boundaries and security implications.
- Update `docs/000-CONTEXT.md` for status changes; update `docs/002-ARCHITECTURE.md`, `docs/003-MODULES.md`, and/or an ADR for architectural changes.
- Keep `docs/005-ROADMAP.md` consistent with implemented work.
- Record proposed decisions as proposed, never implemented.
- Prefer PRs into `master`; do not make undocumented direct changes to `master`.

See `docs/004-STANDARDS.md` for coding and documentation conventions.

## RBAC foundation (F5)
- Model user roles with Spatie (web guard) instead of `UserRole` enum or a `users.role` column.
- Only explicitly enrolled, active and linked allowlisted Telegram IDs may be trusted Super Admins; never grant root via ENV alone.
- Gate checks are not optional for transport adapters; actions must enforce privilege checks too.
- Protect Super Admin from untrusted HTTP assignment, deactivation and revocation. Security/audit regressions require tests.
- See ADR 0005 and `docs/007-RBAC-OPERATIONS.md`.

## Telegram navigation (F06-A)
- Keep all Telegram keyboard content in the lightweight `MenuRenderer`; handlers authenticate per request and do not cache access decisions.
- Admin and role navigation are authorization-gated even when stale/forged callback data is received.
- Settings, Users and Roles screens in F06-A are explicitly read-only placeholders, not implementations of future domain features.
- Do not add a generic settings table, referral model, privileged enrollment or server command to F06-A.

## F06-B Telegram management
- Keep user list/detail/search/active-state business rules inside Identity Application Actions; Telegram only renders and handles short-lived confirmation UX.
- Do not weaken Super Admin, peer-admin or self-deactivation protections from F05. Audit mutations, require fresh permissions and reject stale requested state.
- Reuse `BrowseUsers` / `ChangeUserStatus` for future Livewire; no new public REST endpoint or generic framework.

## F06-C Telegram role management
- All RBAC reads and writes belong to Identity Application Actions with fresh trusted-Super-Admin authorization. Telegram handlers must not touch Spatie models directly.
- Never expose `super-admin` enrollment/revocation or built-in system-role permission edits in Telegram. Maintain F5 guardrails for peer accounts and the final remaining role.
- Every Telegram RBAC mutation must have a one-time, per-actor cache confirmation, a current DB state check, and an Identity audit event. All handlers remain private-chat-only.
- New permissions are code-defined, not created from untrusted Telegram text. No generic settings/referral/server domain additions in F06-C.

## F07 onboarding/referral boundaries
- Public join: deliberate private-chat consent plus deploy flag, then Identity `member` only. Do not intercept or auto-link existing administrator accounts; protect disabled accounts.
- Onboarding orchestrates Identity/Referral Application use cases in a transaction. Telegram adapter owns only UI and short-lived deep-link state; do not reach into database models there.
- Referral ownership is immutable and attributed only when a **new** member is created; no income/financial reward records at this phase.
- Keep automated CI and E2E verification before production public rollout.

## F08 finance safety
- Billing owns wallet/topup/ledger. No balance column on User; integer rials only. Always post balanced journals in a DB transaction, with globally unique verified bank transfer reference and journal idempotency key.
- Finance operator authority is a **trusted Super Admin**, not an ordinary admin. A reseller can read/request only their own account. Typed transfer proof is not bank confirmation.
- Existing referral, Identity, Sales and Provisioning must not be coupled to Billing infrastructure. No refunds, rewards, payouts or payment gateway. F11-A1 adds an **internal-only, default-disabled** wallet debit + Sales settlement; it is not wired to Telegram/HTTP and must remain disabled until independent bank verification, production DB concurrency and live E2E gates.
- Banking integration, manual verification and live Telegram E2E have not happened merely because feature tests passed. Leave `BILLING_TOPUPS_ENABLED=false` in production until signed off.

## F09-A product catalog and pricing
- Only two Sales catalog tables and DTO-driven, read-only active-buyer quotes; no order, money transfer, provisioning or Telegram purchase UI yet.
- Accept global 1–100 **integer** GB and durations 15/30/60/90 only. Price in bounded integer rials: base + (GB × per-GB); 5% off the **same** rate for active reseller role (0% setting permitted).
- Never seed fake selling prices; configure via operator-only Artisan, no unauthenticated HTTP/Telegram price mutations. Recalculate & snapshot upon F09-C order creation.
- Remnawave is the selected future F10 Provider; do not put API fields, server identifiers or node settings into F09 catalog tables.

## F09-B UI scope
- Keep ShopUi thin: consume F09-A Catalog/Quote Actions, no Eloquent imports, no mutable price logic or Sales DB writes in Telegram.
- Show real calculated price on the **first buying screen**, plus each preset volume and custom preview. Validate the actor/product/rate/volume live even for forged or stale callbacks.
- Pending custom volume is a ten-minute, private-chat, per-user UX context only, never a quote lock. Sanitize plaintext, normalize Persian/Arabic numerals, and never create orders or charge wallets in F09-B.

## F09-C orders and recheck
- Order creation is a Sales Application Action, not a Telegram Eloquent write. Use the buyer-bound opaque checkout token only as a pointer to the immutable quote presented; re-price with `CalculateQuote` under a transaction and reject stale prices or changed permissions.
- Store a DB-unique idempotency key per order; double-click/retry returns the same buyer-owned order, never a second charge or second order. No order row is proof of payment.
- Orders start `pending_payment`, with immutable public/discount/final amounts and volume/duration snapshot. Never call Billing or Remnawave in F09-C. Read owner-scoped only; tests must cover cross-user access and old callback replays.

## F10-D/E provider safety and F11 handoff (current)
- **Merged:** PR #19 catalog/client (218 operations), #20 read-only discovery, #21 node API and telemetry, #23 typed Users/Subscriptions plus SQL-reserved lifecycle. No Remnawave live E2E. Refer to `docs/000-CONTEXT.md` for exact verified checkpoint.
- Provider `Infrastructure/Remnawave` is a transport/DTO implementation, **not an authorization layer**. Telegram/Web never call it directly. The lifecycle `subscription(reference)` method checks local provider mapping but **does not check Identity/Sales order ownership**.
- `REMNAWAVE_WRITES_ENABLED=false` by default. F11-A1 and F11-A2 provide default-disabled financial settlement actions and a pending outbox, but neither Telegram checkout nor an outbox consumer performs Remnawave provider creation. A wallet top-up alone is never a service purchase.
- Every create checks the requested Internal Squad set against provider response; reject associative UUID arrays before a write. Protect subscription URLs like bearer tokens; no links in list DTOs or logs. Keep failed/ambiguous writes in `reserved`/`needs_review` until read-only reconciliation; never replay them blindly.
- **Next:** F11-B1 product/plan-to-Internal-Squad mapping, then F11-B2 safe outbox claim/dispatch and reconciliation, followed by isolated staging E2E. Preserve default-disabled finance and remote-write gates.
- Do not implement broad payment/admin write interfaces or grant reseller cross-account access by role alone. Preserve Identity fresh checks, Sales order ownership, one-use root confirmation and audit in all new Application actions. Full PHP 8.4/8.5 CI required before merge.

## F11-B0 — financial boundary (current checkpoint)

- **No production/customer data exist** as of this development phase. For F11-B0, modify baseline migrations, not data-preserving rename migrations. Initialize a fresh disposable development DB; do **not** apply a destructive migration to any DB containing real data.
- Financial source of truth is **one** Billing journal/postings pair (`billing_journals`, `billing_postings`) for F08 top-ups, F11-A1 wallet purchases and F11-A2 direct bank sales. Direct journals have null wallet and `kind=direct_bank_sale`; all money is integer rials. There is NO separate Sales postings table.
- Sales owns order/claim/settlement reference, audit and pending outbox, but never queries `billing_*` raw tables. Use `Billing/Application/{ClaimVerifiedBankReference,RecordDirectBankPayment,VerifyWalletOrderDebit}`; preserve shared DB atomicity and real Super Admin bank evidence verification.
- See `docs/adr/0008-unified-billing-ledger.md`, `docs/003-MODULES.md` and test coverage. No payment or Remnawave feature flag is enabled by this refactor.

## F11-B1.1 merged on master (#37)

Dynamic Sales plan catalog (volume/time/hybrid, preset/reseller builder, audience, bounds, statuses) is separate from existing F09 pricing/checkout. `ManagePlans` requires fresh allowlisted Super Admin authorization, revision checks, per-root hashed single-use confirmation and mutation audit. `ListVisiblePlans` is read-only and buyer-scoped; **no new plan is purchasable** before B1.2/B1.3. The future `sales_orders.plan_id` FK is nullable and restricts deletion. No bank/Remnawave flags, HTTP/Telegram UI or old pricing behavior change. Merged via [PR #37](https://github.com/DenaSon/skynet/pull/37), squash SHA `c3d52c81c45eb641efd1f744eecd58d98ab5790f`, CI PHP 8.4/8.5 tests, Larastan/Pint green. See `docs/015-SALES-PLAN-CATALOG.md`.

## F11-B1.2 — Managed plan pricing (merged #39)

- Pricing is exclusively Sales-owned under `sales_plan_prices`, `sales_plan_price_confirmations` and `sales_plan_price_audits`, one price rule per B1.1 plan; plan and price each have independent optimistic revisions.
- `ManagePlanPricing` is root-only; create/edit always resets pricing to **draft**, and publish/unpublish requires trusted Super Admin + actor-bound hashed one-use three-minute confirmation. No hidden live repricing of active quotes.
- `CalculatePlanQuote` is read-only and freshly buyer/role/visibility/plan/price authorized; fixed price applies to presets, formula = base + GB × per-GB + days × per-day. Time plans are unlimited (`trafficGb=null`), volume plans do not charge per day. Prices and discount are integer rials, capped, optional minimum-sell floor; one per-plan reseller discount **replaces**, never stacks with, F09's discount.
- `PlanPriceQuote` is an immutable buyer+plan+price-revision snapshot. `RevalidatePlanQuote` rejects changed buyer/role/plan/price/amount. **No B1.2 order creation, wallet/bank transaction, Nutgram route, or provider write.** Existing F09 checkout is unaffected. Plan checkout waits for B1.3 binding and an explicit guarded action. See `docs/016-SALES-PLAN-PRICING.md`.

## GitHub branch hygiene

A narrowly scoped GitHub Actions workflow (`.github/workflows/prune-merged-branches.yml`) deletes **only branch refs backed by a merged same-repository PR into `master`** when the ref still points to its merged PR head. It must never delete `master`, open/unmerged PR branches, or refs with new commits after review. The sole SHA-pinned historical exception is `docs/f11-a1-merged-checkpoint` (PR #31), whose additional doc changes were reconciled through merged PR #32. Workflow uses `contents: write` and `pull-requests: read` exclusively for branch hygiene; audit the run before declaring cleanup. This does not change runtime code or deployment.
