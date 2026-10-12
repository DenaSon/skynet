# SkyNet — Agent Instructions

SkyNet is a Laravel 13 application being developed as a **modular monolith**.

## Current tooling
- PHP 8.4.1+, Laravel 13, Pest 5, Larastan 3, Laravel Pint.
- Check with `php artisan test`, `vendor/bin/phpstan analyse --no-progress`, `vendor/bin/pint --test` (or `composer quality`).
- Never commit local Composer mirror URLs to `composer.lock`; lock files must install on GitHub-hosted CI.

## Start here
1. Read `docs/000-CONTEXT.md` before changing code.
2. Read the relevant architecture/module/roadmap documents.
3. Inspect the actual code, merged PRs, issues, migrations and most recent CI; documentation never overrides observed behavior.
4. **Current checkpoint (2026-10-12):** F10-A/B/C/D, F10-E1 (#26) and F10-E2 (#27) are merged. F10-E3 Telegram presenter is merged via [PR #28](https://github.com/DenaSon/skynet/pull/28), commit `3dd9e61974478f35c0a776855a5abe1f3836f6a8`; PHP 8.4/8.5 [master CI #38223868886](https://github.com/DenaSon/skynet/actions/runs/38223868886) green. The next independent increment is F11 settlement/dispatch. Never treat 218 mapped Remnawave routes as 218 authorized Application APIs.
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
- **Remnawave is selected as the external subscription panel API**, pinned to OpenAPI v3.4.4; F10-A/B/C/D are merged. The SkyNetwork transport/network-control-plane architecture (SSH/WireGuard/Core/Edge/agents/tunnels) is **not** selected by this Remnawave decision. Do not invent those implementations without a separate architectural decision.
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
- Existing referral, Identity, Sales and Provisioning must not be coupled to Billing infrastructure. No refunds, wallet debit, rewards, payouts or payment gateway until future explicit requirements and E2E.
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
- `REMNAWAVE_WRITES_ENABLED=false` by default. No action in Telegram/checkout invokes provider creation/update. `sales_orders` are `pending_payment` only; a Billing wallet top-up is not a purchase. No wallet debit, paid-order transition or durable provisioning outbox exists.
- Every create checks the requested Internal Squad set against provider response; reject associative UUID arrays before a write. Protect subscription URLs like bearer tokens; no links in list DTOs or logs. Keep failed/ambiguous writes in `reserved`/`needs_review` until read-only reconciliation; never replay them blindly.
- **Next:** Design F11 verified payment settlement, idempotent durable dispatch and isolated staging E2E. The new Telegram subscription UI is read-only; it cannot settle, provision, debit or administratively mutate a service.
- Do not implement broad payment/admin write interfaces or grant reseller cross-account access by role alone. Preserve Identity fresh checks, Sales order ownership, one-use root confirmation and audit in all new Application actions. Full PHP 8.4/8.5 CI required before merge.
