# Cloudflare Radar read-only backend (2026-11-15)

**Scope:** The first backend-only integration. No frontend, new domain, public API route, Telegram action, scheduler, migration, dashboard, provider operation, or existing Check-Host behavior changes.

## Client

Resolve \`App\Support\Network\CloudflareRadarClient\` from Laravel's container. Methods all return the provider's validated \`result\` array (not the Cloudflare envelope). No derived network-health verdicts:

- \`trafficSeries(?int $asn = null, string $window = '1d')\`: \`/radar/netflows/timeseries\` with original \`meta\` and \`serie_0\`.
- \`outages(?int $asn = null, string $window = '7d')\`: \`/radar/annotations/outages\`, max 25 events.
- \`anomalies(?int $asn = null, string $window = '7d')\`: \`/radar/traffic_anomalies\`, max 25 events; may include unverified events.
- \`qualitySummary(string $metric = 'LATENCY', ?int $asn = null, string $window = '7d')\`: \`/radar/quality/iqi/summary\` with \`meta\`, \`summary_0\`.

\`$asn = null\` selects Iran (\`location=IR\`); allowed explicit ASNs are **197207** (MCI), **44244** (Irancell), **57218** (Rightel). Windows: \`1d\`, \`7d\`, \`30d\`. IQI metrics: \`BANDWIDTH\`, \`LATENCY\`, \`DNS\`. Other arguments fail before network access.

## Configuration and failure policy

Configure **\`CLOUDFLARE_RADAR_API_TOKEN\`** as a separate protected secret, independent of the existing Cloudflare DNS token. Optional \`CLOUDFLARE_RADAR_CACHE_SECONDS\` (default 600; clamped 60–3600). Token stays server-side.

GET-only fixed Cloudflare HTTPS origin; disabled redirects, bounded connect/request timeouts and response size, validated Cloudflare \`success\` + result shape, shared successful-response cache, and a 60-second shared cooldown after HTTP 429. Failures throw \`RuntimeException\`; no stale-as-healthy fallback, automatic retry, raw provider error logging, or token in UI. Cache shares the existing Laravel cache backend (Redis in production).

Use \`meta.normalization\`, \`meta.lastUpdated\`, confidence information and the event verification status before interpreting data. A falling normalized traffic index is not a population outage percentage. Absence of an anomaly/outage is **not** proof of reachability, and Cloudflare Radar does not test VLESS/Xray/REALITY handshakes. A future frontend or Application Action must handle presentation, freshness policy and authorization in a separately approved phase.

## Validation

Pest feature tests use \`Http::fake\` for success, exact filters/token/GET, shared cache, input guards, missing credentials, 429 cooldown, provider failures and malformed replies. Such tests are **not** a live Radar API or Production acceptance test; a valid Radar token and separately approved live smoke will be required.


## DenaGate Live UI-01 — two reseller report cards (2026-11-15)

**Operator-approved narrow scope:** Only existing authenticated reseller Livewire reports (/dashboard, section reports), with two quiet cards **below** the original six sales metrics: (1) Iran public network notices; (2) MCI, Irancell, Rightel notices. No new page/domain, public route, chart, miniapp navigation, demo, bot, provider operation, DB migration, scheduler, dependency or sales/payment change. The existing 7/30-day selector remains for **sales only**; Radar cards always summarize the preceding 24 hours.

The read-only ReadIranNetworkSummary projects the already merged CloudflareRadarClient results from one country outage read and four anomaly reads (Iran plus the three allowlisted ASNs). Transport-level success responses are cached by the existing shared Redis/Laravel cache. The country/ISP projections distinguish: verified outage (eventType=OUTAGE) or TP/VERIFIED anomaly → **اختلال ثبت‌شده**; current UNVERIFIED anomaly → **احتمال اختلال**; empty valid results → **گزارش اختلالی یافت نشد**; failed/malformed/incomplete data → **اطلاعات کافی نیست**. Malformed, future and expired event timestamps do not prove an incident. Country outages are attributed to an ISP only when it is explicitly named in asns. No status ever means guaranteed internet, VLESS, REALITY or XHTTP access.

Radar work runs through a separate, reseller-authorized lazy Livewire action only after entering the reports section; it never blocks the initial sales-report render, never grants access to nonresellers, and never makes customer-facing provider writes. No direct browser calls or token exposure. Loading and unavailable states are explicit; last read time is **fetch time**, not Cloudflare's source measurement timestamp. Errors do not change readError or the established sales reporting. Opening other Mini App sections does not fetch Radar data. Reopening reports resets the summary; repeat calls in one view are idempotent.

**Verification:** PHP/Livewire fake-HTTP coverage exercises load-only-on-reports, three ISP projections, confirmed/suspected/empty/unavailable results, old/malformed event timestamps, role denial, sales period and no writes/secret leakage. This is source verification; mobile Telegram WebView UI and real post-deploy Radar+Report smoke are separate gates. UI-01 does not modify the original Radar backend client.


## Reports UI polish P1 — approved two-phase follow-up (2026-11-15)

- The reseller-owned settled-sales report supports **7 days**, **30 days** (unchanged default), and **از ابتدا** (sentinel 0). Existing bank/wallet source eligibility, refunded-wallet exclusion, Tehran day boundaries, tenant checks and pending/current-state fields remain. All-time omits only the lower date bound and shows the actual earliest settled date based on the same union. No database mutation or migration.
- The headline revenue amount uses one scoped responsive CSS rule; the six KPI cards and finance/report actions stay intact.
- The two Radar cards use small inline semantic check/warning/error/unknown icons and concise color-coded labels. The provider name is no longer rendered in UI. The read timestamp remains explicitly a fetch time. Green **سالم** requires a valid empty recent anomaly/outage assessment AND a fresh nonempty Cloudflare traffic sample within three hours (a freshness prerequisite only, not proof of ISP, TLS, VLESS or provider reachability). An incident notice can be shown from event evidence even when traffic-series data fails; absent/old or malformed evidence remains **نامشخص**.
- No Radar API client change, new scheduler, bot, domain or dependency. Reviewed PHP 8.4/Pest, Pint, PHPStan, MySQL and live Telegram/mobile rendering are separate checkpoints. Phase P2 Persian numeral presentation is a separate PR.
