# DenaGate Live — Telegram Mini App rollout

**Scope:** Independent, additive web presenter over existing Laravel 13; no new Sales/Billing/Provisioning APIs, business writes, provider operations, migrations, or schema changes.

## Phase 0 (operator)
- Custom domain: `https://live.denagate.ir` points to the SAME Laravel deployment.
- TLS and public DNS require independent operator smoke verification. Connectivity from the assistant environment was unavailable on 2026-10-30; do not mark verified.
- Keep existing `APP_URL`, Admin/Sales webhook URLs, and bot secrets unchanged.

## Phase 1A (this PR — no extra Composer dependency)
- Default-disabled host-scoped entry, Telegram Mini App HMAC verification using **Sales Bot** token, strict five-minute `auth_date` window, active Telegram binding, existing reseller role, Laravel session regeneration, repeated role check, logout.
- Sales Bot opt-in `/live` launcher only; do not alter persistent keyboards or rich message callbacks.
- Minimal, non-commercial HTML shell solely to exercise real Mini App authentication.
- No new user registrations or credentials. `initDataUnsafe` is never trusted.
- Tests must reject missing/expired/tampered data, other bot signatures, inactive/non-reseller actors, stale sessions and disabled feature. Mock tests do not count as live Telegram acceptance.

## Phase 1B (stacked PR on 1A)
- Composer-resolved Livewire 4 with a committed lockfile, existing Tailwind/Vite and bundled Alpine (no second Alpine install).
- Mobile-first minimal Persian Livewire layout with section navigation only; no simulated balances, fake usage or Sales/Billing writes.
- Livewire update requests must re-run the Live feature flag and the current active reseller authorization middleware.
- Run full CI, PHPStan, Pint and a Vite production build. Real Telegram sign-in is separate live acceptance. No deployment flag change.

## Phase 2
- Read-only reseller-owned wallet balance, sales report and service list via existing Application Actions.
- No direct database read in Livewire presenters where an Action contract exists.

## Phase 3
- Existing quote/order/settlement/renewal actions only; preserve owner checks, idempotency and business feature gates.
- No new payment or provisioning semantics.

## Phase 4
- Controlled Telegram iOS/Android/Desktop smoke, opt-in button in compact reseller navigation, deployment review and rollback.

## Deployment / rollback
- `DENAGATE_LIVE_ENABLED=false` is the default; keep OFF until DNS/TLS + actual Telegram launch are verified.
- Require existing `TELEGRAM_SALES_ENABLED=true` and the existing secret `TELEGRAM_SALES_TOKEN` in Production for auth; never store a token in code.
- When explicitly approved, set `DENAGATE_LIVE_ENABLED=true` and refresh cached config through the hosting platform. This enables both the host routes and the private `/live` command.
- Set back to `false` for rollback; this does not change Sales Bot callbacks, orders, financial ledger or provider flags.
- No independent direct-browser login is in scope.
