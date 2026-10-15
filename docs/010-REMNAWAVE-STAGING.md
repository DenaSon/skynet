# F10-B — Read-only Remnawave staging diagnostics

**Availability update (2026-10-15):** Remnawave staging VPS is **not currently available**; no live panel test or installation has been completed. RW-01–RW-03 are blocked, with RW-04 permitted only as narrow offline preparation. Provider-independent MB-01–MB-04, MB-05A, MB-05B offline and MB-06A may proceed under [the dependency-replanned execution contract](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md); never infer real-panel compatibility from HTTP fakes.


**Status:** F10-B implemented and merged via PR #20; F10-C/D now also merged (#21/#23). **No real Remnawave server E2E has been performed**; do not infer compatibility from fake tests. See [current context](000-CONTEXT.md).
**API contract:** Official https://docs.rw/api/ exposes OpenAPI `v3.4.4`.
**Server release:** `3.4.5` exists; validate against the pinned API contract before deploying writes.

## Boundaries
- `RemnawaveApiClient` is transport-only; F10-A maps 218 operations, not 218 typed SDK methods.
- `RemnawaveReadApi` wraps only five GET operations: `/api/system/health`, `/api/nodes`, `/api/hosts`, `/api/internal-squads`, `/api/config-profiles`.
- Strict response envelope/collection validation. No raw node address, proxy URL, Xray config or token are included in summary DTOs.
- `ProvisioningServiceProvider` registers a console-only `remnawave:inspect` operator command. No Telegram or public HTTP routes, order transitions, external writes, or migrations.
- `RemnawaveApiClientFactory` reads only trusted configuration. A missing token or URL fails closed. An HTTPS URL is required; no public open proxy is created.

## Safe operator preparation

1. On an **isolated staging panel** with TLS, manually create a **scoped API token** under Remnawave Settings → API Tokens. Prefer a token limited to the five GET operations above, and limit panel network access to the SkyNetwork server. **Do not try to create/manage tokens using that token:** the API Tokens Controller explicitly requires an administrator JWT.
2. Configure the untracked deployment `.env` (example variables are blank by default):

   ```dotenv
   REMNAWAVE_PANEL_URL=https://panel-staging.example.com
   REMNAWAVE_API_TOKEN=<put-staging-secret-in-env-only>
   REMNAWAVE_TIMEOUT=15
   ```

3. Deploy code and refresh config cache if active (`php artisan config:clear`, followed by normal production config cache build).
4. From a trusted shell only: `php artisan remnawave:inspect`. Optionally `--nodes` displays **only** node UUID/name/connection and disabled state, never addresses or secrets.
5. Require success on all five GETs before any provisioning test. An empty resource inventory is valid; a 401/403/5xx, missing response envelope or malformed value is a failure.
6. Rotate the staging token after testing; never paste it into CI, commits, Telegram messages or shared command transcripts.

### Operational notes
- Successful system health + node `isConnected` means the panel sees the node; it **does not** measure reachability or throughput from Iran.
- Keep API traffic over TLS; if using a foreign panel with Iran-based Laravel, explicitly test reachability from the Iran VPS. Enforce network-level panel access controls when possible.
- The CLI intentionally prints only a generic failure message so HTTP exceptions cannot leak credential-bearing URLs or tokens.
- If a deployment uses additional reverse-proxy auth, configure that separately. This command assumes the normal Remnawave Bearer API contract.
- Update the pinned OpenAPI map deliberately. Do not automatically target `latest` without contract tests.

## Verification gates
- Automated: Pest HTTP fakes, PHPStan, Pint on supported PHP versions.
- Staging: actual API token scope check, TLS/certificate, 401/403/429 and request timeout behavior.
- F10-D **already implemented** typed user/subscription DTOs and durable SQL reservations/read-only reconciliation (PR #23). **Not yet implemented:** order-owner Application authorization, user-facing delivery, audit for provider writes, verified payment transitions, outbox, or any real-panel E2E.
- There is **no** purchase, wallet debit or user activation from F10-B.

Related: [API mapping](009-REMNAWAVE-API.md), [ADR 0007](adr/0007-remnawave-client.md), [architecture](002-ARCHITECTURE.md).
