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

## HTTPS origin-scheme regression patch (2026-10-30)
- **Observed production evidence:** `https://live.denagate.ir/` returned 200 over publicly valid TLS, but the Telegram login form action was rendered as `http://live.denagate.ir/auth/telegram`. Runflare forwards the TLS-terminated request over HTTP, and the web entry could consequently submit mixed-content HTTP.
- Only `EnsureLiveEnabled` for the Live host forces HTTPS **URL generation** for form, redirect, and asset URLs while rendering its response. The URL generator is reset in `finally`; neither global `APP_URL` nor Admin/Sales routes, tokens, or webhook behavior are changed.
- **Edge HTTP→HTTPS remains Runflare's responsibility** (observed 308 redirect). Do not add a Laravel HTTP redirect based on the untrusted upstream request scheme; this can cause a reverse-proxy redirect loop. Do not configure trust-all proxies.
- Regression: emulate a trusted public HTTPS connection that arrives at Laravel with HTTP scheme, assert HTTPS form action and auth/dashboard/logout redirects, valid reseller sign-in and no forced-scheme leak. CI is not an actual Telegram client smoke.
- After deploy, check `curl.exe -sS https://live.denagate.ir/` contains `action="https://live.denagate.ir/auth/telegram"` and send `/live` in the Sales Bot. Confirm a real reseller sees the dashboard. Keep the feature gate available for immediate rollback.

## Telegram Mini App 403 HMAC compatibility patch (2026-10-30)
- Incident: After HTTPS was fixed, a real Telegram Mini App GET and immediate form POST reached Laravel but produced 403.
- Root-cause risk found during source audit: previous bot-token HMAC data-check-string removed both `hash` and `signature`. Official Telegram validation with **bot token** excludes **only hash**, whereas the third-party Ed25519 verification path excludes both. Real Mini App `initData` may include `signature`.
- The patch includes any present `signature` exactly as supplied in the sorted HMAC input. It does NOT accept an unverified signature, alter signing secrets, skip the `auth_date` limit, weaken reseller RBAC or store/log Telegram initData.
- Tests: signature-present signed payload works; changed/removed signature and wrong bot token remain denied; existing signature-absent data remains supported and reseller session remains owner-checked.
- Real Telegram smoke after deploy remains mandatory: close old Mini App, send fresh Sales Bot `/live`, open its new private Web App button; acceptance is the reseller dashboard rather than 403.

## Phase 2A — Reseller Mini App visual foundation (2026-10-30)
- Approved scope: presentation-only Apple-style minimal **light** theme on DenaGate Live, primary background `#F6F8FA`, white surfaces, brand teal `#14B8A6`, dark text and accessible teal navigation states. Both desktop sidebar and native bottom tabs have the four pre-existing Livewire destinations.
- Self-host the official Vazirmatn variable WOFF2 font as `public/fonts/vazirmatn-variable.woff2`; retain upstream SIL Open Font License at `public/fonts/OFL-Vazirmatn.txt`. Imported from pinned upstream commit `6e553e33489a8f9dfaccc76860a2e3f3c1e66de7`. No Google Fonts runtime dependency, font npm dependency or extra application runtime package.
- Apply the same theme to the no-Vite Telegram login entry so that it remains visible and functional before authentication; never modify or bypass raw Telegram `initData`, HMAC, CSRF, reseller role or HTTPS-scoped middleware.
- Keep dashboard read-only placeholders explicit. Do **not** display fabricated sales, service, wallet figures; actual Application Action read integrations remain **Phase 2B**.
- Regression covers entry and dashboard styling/font, native bottom-tab navigation, action names, authenticated access and existing financial ownership/auth tests. CI, asset build and real Telegram smoke are separate. Revert the visual commit for UI rollback without changing business data.

## Phase 2B — Actual reseller-owned dashboard reads (2026-10-30)
- Scope is strictly **read presentation** for an already verified, active reseller session. The Livewire presenter only invokes `ViewWallet`, `ListMyServices::counts/handle`, `ManageResellerServiceLabels::forOrders` and `ReadResellerSalesReport` (7/30 days) through the existing Action boundaries, with no direct database queries or provider API calls in the presenter.
- Overview: current wallet balance, total linked services/local recorded active count, and the last 30-day **settled** sales total. Credit: wallet balance and last ten top-up requests. Services: at most six owner-linked paid/fulfilled records per page, optional owner-scoped customer labels, keyset 'more' and reset; **local state is not live network availability**. Reports: 7/30-day bank/wallet settled totals and counters; pending and total services are **current**, not period-filtered.
- The existing `ViewWallet` may create a missing, empty wallet exactly once via existing `WalletAccess::customerWalletId` idempotent bootstrap; it does not generate postings, top-ups, orders, payments or provider calls. This is an established Action side effect, not a new financial write path.
- Never display remote credentials, customer phone, provider identifiers, raw exception messages or any fake metric. Do not show any engineering, roadmap or phase-status text in customer-facing HTML; friendly empty/error states only.
- Keep HMAC+CSRF+Sales Bot identity/session, host-scoped HTTPS and fresh reseller role guards intact; arbitrary Livewire input cannot select a buyer ID. Unknown report windows and invalid pagination cursors are ignored/normalized; all reads remain owner-scoped. Real Telegram acceptance and source CI are separate.
- No schema migration, new external dependency, independent API, subscription mutation, renewal or top-up creation in this increment. Rollback by reverting only this read presenter/view and tests.


## Livewire interaction regression — Vite runtime mitigation (2026-10-30)
- **Reported live symptom after LIVE-3A:** reseller dashboard server-renders, but no `wire:click` tabs or purchase buttons react; ordinary HTML logout still works. This strongly suggests browser Livewire runtime failed to initialize, but no authenticated browser Network/Console or edge access log has yet confirmed a specific HTTP error.
- Livewire 4's default `@livewireScripts` fetches a dynamically routed `/livewire-{hash}/livewire.js` asset, which can fail behind edge/Nginx routing even while HTML and static Vite CSS render. Avoid inventing a Runflare setting or weakening any security middleware.
- Narrow mitigation: bundle the **existing installed Livewire 4 ESM runtime** in `resources/js/live.js` using existing Vite, switch only Live layout to `@livewireScriptConfig`, retain one Livewire-supplied Alpine instance. This removes dependency on the dynamically served JavaScript file while preserving the standard hashed **update** route, `EnsureLiveReseller`, CSRF, session, Telegram HMAC and feature flag.
- CI must run a **real production Vite build** and require the `resources/js/live.js` bundle in the generated manifest, in addition to PHP 8.4, PHPStan, Pint, MySQL, and source regression.
- **Acceptance is not CI alone:** open fresh Sales Bot `/live` in Telegram, test all four tabs + purchase catalog + quote, then verify Livewire POST to its hash-based `/update` path succeeds over HTTPS. If browser requests fail, investigate edge routing, cookies, redirects, and CSP using redacted status codes; do not blindly relax auth or proxy trust.
- Rollback: revert the small runtime/layout/Vite and verification PR. No DB, financial, provider, bot or production environment changes authorized.
