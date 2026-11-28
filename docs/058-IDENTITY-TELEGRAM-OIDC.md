# DenaGate Identity — Telegram OpenID Connect

**Status:** Source implementation prepared in an independent PR; not merged or deployed.
**Date:** 2026-11-28
**First consumer:** Root-only CRM at `crm.denagate.ir`

## 1. Goal and boundaries

Build one reusable Telegram OIDC connector inside the existing `Identity` module. CRM consumes it first. Future web applications may reuse the same application service and verified-identity result without implementing token validation again.

This work does not change:
- Telegram Admin or Sales Bot authentication, tokens, webhooks or Nutgram handlers.
- Reseller bot authentication or onboarding.
- DenaGate Live Mini App authentication or Telegram `initData` validation.
- Existing non-CRM web password login.
- User creation, role assignment or Telegram account linking.

No new database table or migration is required. Existing `telegram_accounts` is the authoritative link between an internal user and the numeric Telegram account ID.

## 2. Telegram OIDC contract

- Issuer: `https://oauth.telegram.org`
- Authorization endpoint: `https://oauth.telegram.org/auth`
- Token endpoint: `https://oauth.telegram.org/token`
- JWKS: `https://oauth.telegram.org/.well-known/jwks.json`
- Response type: `code`
- Scopes: `openid profile`
- PKCE: `S256`
- Client authentication: HTTP Basic using BotFather OIDC Client ID and Client Secret; the token request also includes `client_id`.
- Signing algorithm: `RS256` only. Keep the BotFather OIDC signing algorithm at its default RS256 setting.
- Telegram has no separate UserInfo endpoint; required claims are read from the verified ID token.

The Telegram profile `id` claim is the numeric Telegram account identifier used by `telegram_accounts.telegram_user_id`. OIDC `sub` is a distinct subject identifier and must not be used to match the existing Bot API account link.

Do not request `phone` or `telegram:bot_access`. The existing Bot API token is not an OIDC credential and must not be sent to the OIDC endpoints.

## 3. Shared Identity implementation

The reusable Application service:
- Creates cryptographically random `state`, `nonce` and PKCE verifier.
- Builds the Telegram authorization URL with S256 challenge.
- Exchanges the one-time authorization code server-side.
- Verifies the ID-token signature using Telegram JWKS and the pinned RS256 algorithm.
- Explicitly checks issuer, audience/authorized party, expiry, issued-at, subject and nonce.
- Returns a verified identity value containing the numeric Telegram ID and optional profile claims.

The JWKS response is cached for one hour and refreshed once when the token references an unknown key ID. The authorization transaction is held in the caller's session; no ID token, access token or authorization code is persisted.

The verified Telegram identity is separate from application authorization. The connector never creates users, links accounts or grants roles.

## 4. CRM consumer flow

1. User submits the existing email/password form on the CRM host.
2. The current account must be active and accepted by `SuperAdminTrust`; otherwise the session is invalidated.
3. CRM redirects to a small Telegram challenge page.
4. The user starts the OIDC Authorization Code + PKCE flow.
5. The callback validates one-time state, transaction age, session user and exact redirect URI.
6. The verified token's `id` must match the Telegram account already linked to the same internal user.
7. The current `SuperAdminTrust` check is repeated after the external redirect.
8. Identity audit records the successful second factor; the session ID is regenerated and a factor marker bound to the internal user and the exact linked Telegram ID is stored in the session. Every CRM request compares that ID with the current account link.
9. `EnsureCrmAccess` requires both trusted-root authorization and the factor marker on every CRM request, including Livewire updates.

Logout invalidates the session. A missing OIDC configuration fails closed; password-only access is not an allowed fallback on the CRM host.

## 5. Security controls

- Random 256-bit state and nonce.
- PKCE S256 with a 43-character verifier.
- One-time state consumption using an atomic cache add with a short TTL, in addition to the session transaction.
- Ten-minute authorization transaction lifetime.
- Exact callback URI comparison and session-user binding.
- Server-side code exchange with HTTP Basic authentication.
- No HTTP redirects followed for token or JWKS requests; bounded connection and request timeouts.
- Strict RS256/JWKS key selection, `kid` matching and signature verification.
- Explicit `iss`, `aud`, `azp`, `exp`, `iat`, `sub`, `nonce` and numeric Telegram `id` validation.
- Rate limits for OIDC start and callback.
- No sensitive token, code or profile data in audit records.

## 6. Dependency and persistence

- One approved runtime dependency: `firebase/php-jwt` for JWK parsing and JWT signature verification.
- Laravel HTTP Client handles network requests.
- Laravel Cache stores public JWKS and one-time state-consumption markers.
- Laravel Session stores the pending OIDC transaction and completed second-factor marker.
- Existing `identity_audit_events` records successful verification.
- No schema migration or new account table.

## 7. Deployment checklist

Before merging to `master` (which auto-deploys Production):

1. In BotFather, configure Web Login/OIDC for the existing Admin Bot.
2. Register the CRM origin and exact callback URI: `https://crm.denagate.ir/auth/telegram/callback`.
3. Keep the signing algorithm at RS256.
4. Add `TELEGRAM_OIDC_CLIENT_ID` and `TELEGRAM_OIDC_CLIENT_SECRET` to Runflare secrets. These are separate from `TELEGRAM_TOKEN`.
5. Deploy only after CI passes; then perform a real browser login and verify the persisted `telegram_oidc.verified` audit event.
6. Do not merge while credentials or the callback URI are missing. The CRM deliberately fails closed without them.

## 8. Acceptance criteria

- Trusted root: password alone cannot access CRM; password plus the linked Telegram OIDC identity can.
- Unlinked or mismatched Telegram account: denied.
- Invalid signature, unsupported algorithm, wrong issuer/audience, expired token, wrong nonce, stale/replayed state or wrong session user: denied.
- Revoked SuperAdminTrust or missing second-factor session state: next CRM/Livewire request denied.
- Non-CRM web login remains password-only and unchanged.
- Bot, reseller and Mini App authentication source/routes remain unchanged.
- PHP 8.4 tests, PHPStan, Pint, and required MySQL/Redis CI pass.
- Real Telegram acceptance is recorded separately from mocked provider tests.
