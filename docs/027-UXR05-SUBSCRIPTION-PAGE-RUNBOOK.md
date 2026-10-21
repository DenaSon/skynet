# UXR-05 — DenaGate official Remnawave Subscription Page

**Scope:** Issue [#121](https://github.com/DenaSon/skynet/issues/121), owned by [docs/026](026-SALES-UX-ROADMAP.md). This is an *operator runbook*, not an executable change to any panel, domain, server, or customer URL. GitHub source + CI and live deployment acceptance are different.

## Architecture decision

Use the **official Remnawave Subscription Page** as a separate small service, not a new Laravel dashboard or alternate subscriptions database. Prefer **bundled alongside the existing RW panel** provided the actual host has suitable Docker network/reverse proxy and capacity; if not, follow Remnawave's official separate-server guide. Reuse the existing RW subscription URL and templates; **never manufacture a new URL from a short UUID, strip/replace the domain, or publish the panel's administrative hostname as a customer URL**.

Official references (verify versions with the live panel before any changes):
- [Overview](https://docs.rw/install/remnawave-subscription-page/)
- [Bundled installation, domain, API token, reverse proxy and Subpage Builder](https://docs.rw/install/subscription-page/bundled/)
- [Browser vs client response templates](https://docs.rw/learn-en/templates/)
- [Optional raw connection keys / HWID caveat](https://docs.rw/install/subscription-page/display-raw-keys/)

## Offline app integration (CI-testable, no provider writes)

- `SALES_SUBSCRIPTION_PAGE_HOST=` is **empty by default**; no new Telegram button is shown. Set an exact **DNS hostname only** once the real official page is verified. Example format: `subscriptions.example.com` (not a live deployment value). No scheme/path/port/wildcard.
- The Sales bot shows «🌐 صفحه اشتراک» beside «🔐 نمایش کد اتصال» in active service details, and offers both callbacks on the durable activation message **only when the host is configured**. Each page click calls the existing `GetSubscriptionLink` Action, which independently rechecks private identity, owned immutable Sales order, active linked RW identity, quota, expiry and permission after provider read. The URL is accepted **only when HTTPS and the exact allowed host match**; no external URL rewrite is performed.
- Open and copy controls appear only in a fresh **private, protected** Telegram message. Provider credential URLs are not placed in callback data, Notification outbox, service lists or application logs. Longer URLs remain selectable as plain text in that private message; Telegram native CopyTextButton is used only at 256 characters or less.
- Misconfigured/missing provider page URL and provider errors fail closed with a retry/fallback to the existing owner-authorized connection action. Previous link delivery, templates, purchases, Admin bot and RW settings remain unchanged. The app does not proxy customer traffic or implement an additional subscription endpoint.

## Operator-controlled external installation and branding (NOT performed by this PR)

1. **Inventory read-only:** identify actual Remnawave Panel version, existing subscription hostname and current `subscriptionUrl` response for a controlled own test user, panel compose deployment, reverse proxy type, trusted proxy chain, DNS/TLS/certificate, current template behavior and rollback. Do not paste secret URL/token into issues, logs or screenshots.
2. **Prerequisite decision:** choose dedicated customer hostname and HTTPS, prefer official bundled setup only when compatible with actual running RW version and Docker network; otherwise document need for separate host. Back up existing compose/proxy/.env securely. No implicit Panel upgrade.
3. **External install requires separate explicit authorization:** set Remnawave `SUB_PUBLIC_DOMAIN` to the chosen hostname **without** `https://`; configure a scoped Subscription Page API token in the page's *server-local* env, not Laravel/GitHub; deploy the official container behind the existing reverse proxy on a dedicated virtual host at the domain **root**, not an unsupported `/subscription` reverse-proxy subpath. Use the official port/network/`TRUST_PROXY` guidance matching the actual topology. Do not overwrite the existing Caddyfile/Nginx/Traefik configuration or change existing client templates blindly.
4. **DenaGate design:** in Remnawave Subpage Builder use approved DenaGate premium branding (Midnight Navy `#0B1220`, Dena Teal `#14B8A6`), the approved logo asset if available, simple Persian RTL labels, a brief connection guide, actual operator support link, device imports supported by the selected official build, and explicit status/traffic/expiry. The builder is the source of truth; the Laravel bot does not inject HTML/JS or invent a new web frontend. If custom colors/RTL or QR are not supported by the actual builder, record this gap rather than inventing a solution.
5. **Confidentiality:** configure the page API token only in secret server environment, ensure no admin API endpoint exposed by the customer page/proxy, handle page links as bearer credentials, validate TLS, and inspect response/cache headers. *Show Connection Keys* is an explicit operator decision; Remnawave documents that this toggle has no effect when HWID is enabled.
6. **Activation order:** only after the official page works from browser and genuine client, and RW `subscriptionUrl` is verified to carry the exact new hostname for the same controlled user, set Laravel `SALES_SUBSCRIPTION_PAGE_HOST` to that hostname and refresh config using the approved deploy procedure. Do not flip a flag until real provider URLs match; existing users/clients must remain valid.

## Live acceptance, fail-safe and rollback

- [ ] Opening a *genuine private subscription URL* in iOS/Android/desktop browser produces a responsive DenaGate page with clear real status and used/total/remaining quota and exact expiration; under one day does **not** display a misleading expired-zero-days state.
- [ ] Client app still imports from **the same** URL via existing RW user-agent/template logic (Mihomo, Xray, sing-box, base64 where supported). Test at least one currently used real client; do not force browser HTML for client requests.
- [ ] Available actual official page build confirms which of import buttons, copy, QR, raw keys, support and Persian RTL are functional. Do not claim unsupported features.
- [ ] Privately activated own buyer sees new page button in service details and notification; another buyer, stale callback, refunded/deactivated service and provider failure never yield another user's URL. No URL in logs/outbox/notifications/callbacks.
- [ ] Test nonexistent/deleted subscription and RW/API outage fail closed without a fake success page. Check safe host mismatch behavior (bot retains the original connection action).
- [ ] Real staging/browser/device evidence, actual approved infra changes and a safe rollback are recorded separately from GitHub CI. Close #121 only after these checks.

**Rollback order:** unset `SALES_SUBSCRIPTION_PAGE_HOST` in Laravel to remove buttons; leave original private connection callback available. If external Subscription Page must be rolled back, use the verified old DNS/proxy and RW `SUB_PUBLIC_DOMAIN` / template setup, preserving working old subscription URLs and client compatibility. Do not blindly switch domain on a live production customer base.


## Why the customer cannot see the browser page yet (CLOSE-02 acceptance note)

The DenaGate Sales Bot **does not generate or host** this webpage. It only shows the «🌐 صفحه اشتراک» button if `SALES_SUBSCRIPTION_PAGE_HOST` matches an existing HTTPS subscription URL returned by Remnawave. Until the official service is actually running on an approved subscription hostname and Remnawave returns that host, the button is deliberately absent; «🔐 نمایش کد اتصال» still works. This is an **external deployment task**, not a Telegram UI bug. For final validation, note the actual redacted panel version, Docker/proxy type, existing subscription hostname, DNS/TLS setup and confirmed operator-approved destination. No blind compose/proxy replacement or forced migration of customer URLs.
