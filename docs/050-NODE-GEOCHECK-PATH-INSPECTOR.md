# DenaGate — GeoCheck «مسیرسنج» (2026-11-23)

## Scope

An independent, trusted Super Admin-only, private Admin Bot tool under:
**کنترل پنل → 🌐 مسیرسنج**.

- Use current authorized Remnawave Nodes inventory and UUID identity.
- Let the operator select one Node and explicitly start one GeoCheck job.
- Read the existing Job by GET on a separate "دریافت نتیجه" button; no webhook polling loop.
- Render short Persian RTL Rich-first summary, route list/limited hop detail, HTTP, stash, AI and geo source pages; keep native HTML fallback and return links.
- Use one existing RemnawaveApiClient, Redis Cache and existing root/Telegram authorization.
- Store only bounded validated projection for 30 minutes; **never cache raw full report/SVG**, addresses of arbitrary endpoints, user subscriptions or tokens.
- Prevent duplicate POSTs with atomic per-Node admission reservation; an uncertain POST is not retried.
- Independent default-OFF REMNAWAVE_NODE_GEOCHECK_ENABLED safety gate; does not depend on the provider mutation gate.

No filtering state, incidents, scheduler/alerts, automatic retries, provider settings, financial data, migration, new dependency, node modification, Sales Bot, reseller access, Deniz or new monitoring service is included.

## Contract tested by operator on a real Remnawave Node

- GET /api/nodes: three connected, enabled Nodes (operator-provided, Nov 23).
- POST /api/connections/geocheck/{nodeUuid}: **JSON object {} required**; [] returns 400. A real POST returned HTTP 201 and an integer jobId.
- GET /api/connections/geocheck/{jobId}: isCompleted=true, isFailed=false; result.success=true and matching Node UUID; image is base64 SVG.
- rawReport.schema=1, 13 root sections: identity, reputation, consensus, geo, connectivity_checks, stash_checks, ai_endpoints, connectivity and metadata.
- Observed report: 21 ICMP targets (16 direct, 1 peered, 3 transit, 1 detour), 9 HTTP checks (8 ok, 1 altered), 8 stash checks and 8 AI endpoints. These numbers are a **single live observation**, not a guaranteed schema cardinality or a VPN-access result.

The source operation catalog is pinned to Remnawave 3.4.4. The UI must fail closed on malformed, oversized, stale or mismatched data and label unknown rather than inventing health.

## Release and acceptance

1. Run focused Pest feature tests, PHPStan, Pint, and the required PR CI including MySQL/Redis checks where classified sensitive.
2. Review root role revocation, group-chat denial, callback replays, unknown/timeout/failed Job, invalid/oversized report, sensitive data redaction, Rich and native fallback, and navigation to the unchanged control panel.
3. Merge only after separately approved PR review and green required checks. A merge auto-deploys.
4. Keep REMNAWAVE_NODE_GEOCHECK_ENABLED=false until operator-controlled production acceptance. Enable only for a known active Node and verify start, pending/result transitions, output translation and iPhone Telegram navigation. One successful CLI Job is not full live Bot acceptance.
5. Roll back by disabling the flag; source-only rollback via PR revert if required. Cached observations expire automatically.

**Interpretation:** GeoCheck tests Node egress identity, path and external service reachability. ICMP results and score are not filtering classification or proof of successful real VPN connection from Iran.
