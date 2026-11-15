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
