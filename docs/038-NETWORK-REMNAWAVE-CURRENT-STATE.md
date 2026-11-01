# DenaGate — Network & Remnawave Current-State Baseline

> **As of:** 2026-11-01 (Asia/Tehran)  
> **Status:** Operator-reported current topology + configuration audit; documentation only.  
> **Change policy:** Preserve the current working configuration. No upgrade, configuration change, node operation, financial/provider write, or deployment is authorized by this document.  
> **Evidence:** Operator-supplied Remnawave and Cloudflare screenshots, redacted configuration excerpts, v2rayN 7.25.6 subscription verification, and operator-reported carrier experience. No independent live API read or repeatable throughput test was run for this document.

## 1. Current network at a glance

- **Remnawave:** backend **3.4.4** (last operator-reported); Node **3.4.2** and Xray-core **26.7.28** displayed in the audited node screens. Verify versions again before any future maintenance.
- **Node locations:** UAE (`UAE-01`), Netherlands (`NL-Amsterdam-DC2`), Germany (`Frankfurt-01`). This is the operator-confirmed current three-node topology; a fresh post-change API inventory was not performed here.
- **Connection families:** Shadowsocks (ChaCha20-IETF-Poly1305), VLESS over RAW + REALITY, VLESS over XHTTP + TLS behind Cloudflare.
- **Config Profiles:** `Default-Profile` and `UAE-Main` (two distinct, shared profile definitions).
- **Subscription delivery:** an expected set of **five client-facing Hosts**, mapped below; recheck a newly refreshed subscription if exact live output is operationally important.

## 2. Node / profile / inbound / Host map

| Node | Config Profile | Enabled inbound on node | Client-facing Host | DNS/proxy |
| --- | --- | --- | --- | --- |
| `UAE-01` | `Default-Profile` | Shadowsocks TCP/UDP, port 1234 | `ae1.coreflare.ir:1234` | Cloudflare DNS only |
| `UAE-01` | `Default-Profile` | VLESS RAW + REALITY, port 443 | `ae1.coreflare.ir:443` | Cloudflare DNS only |
| `UAE-01` | `Default-Profile` | VLESS XHTTP + TLS, port 8443 | `xhttp.coreflare.ir:8443` | Cloudflare proxied |
| `NL-Amsterdam-DC2` | `Default-Profile` | VLESS RAW + REALITY, port 443 | `nl01.coreflare.ir:443` | Cloudflare DNS only |
| `Frankfurt-01` | `UAE-Main` | Shadowsocks TCP/UDP, port 1234 | `fr.coreflare.ir:1234` | Cloudflare DNS only |

- Do **not** infer that every node runs every inbound defined in its selected profile: the per-node active-inbound assignment is authoritative.
- `UAE-Main` is a shared profile name, **not** a claim that its consumers are geographically in UAE; do not remove or rename shared profiles or inbounds casually.
- All mapped Hosts were shown enabled in the audited screens. Their continued presence after the inventory update should be confirmed with a read-only panel refresh before any further operation.

## 3. Server-side Xray configuration (redacted summary)

### `Default-Profile`

- `Shadowsocks`: port `1234`, cipher `chacha20-ietf-poly1305`, TCP and UDP accepted.
- `VLESS-REALITY`: port `443`, `network=raw`, `security=reality`, REALITY target and server name `www.bing.com`. No private key or short ID is recorded here.
- `VLESS-XHTTP-TLS`: port `8443`, `network=xhttp`, `security=tls`, `mode=packet-up`, ALPN `h2` and `http/1.1`; a dedicated HTTPS Origin certificate is configured. Do not record the precise XHTTP path or private certificate material in this file.
- Sniffing is enabled on these inbound definitions for `http`, `tls`, and `quic`.

### `UAE-Main`

- `Shadowsocks_1230`: port `1234`, cipher `chacha20-ietf-poly1305`, TCP and UDP accepted.
- Sniffing is enabled for `http`, `tls`, and `quic`.

### Shared behavior

- Outbounds defined in both profiles: `DIRECT` (`freedom`) and `BLOCK` (`blackhole`).
- Both profile excerpts showed an empty server-side `routing.rules` array. A declared `BLOCK` outbound is **not** the same as an enforced block rule; this is a security-review item, **not** an instruction to modify production routing.
- REALITY Vision flow cannot be established from the redacted profile alone because Remnawave populates per-user client data dynamically; verify a generated test connection if needed.

## 4. Cloudflare and TLS

- `ae1.coreflare.ir`, `nl01.coreflare.ir`, and `fr.coreflare.ir` use **DNS only**, consistent with direct client transports.
- `xhttp.coreflare.ir` uses Cloudflare **Proxied** on HTTPS port `8443`, with the same UAE origin infrastructure also reachable by the UAE direct Host. This is transport diversity, **not** origin-server redundancy.
- The zone SSL/TLS mode shown in the audit is **Full (strict)**.
- A Cloudflare Origin certificate for `xhttp.coreflare.ir` was shown with expiry **2041-10-20**. The screenshot verifies the configured certificate record and intended strict-mode design; it does not replace a live end-to-end origin handshake test.
- No Cloudflare DNS or TLS modification is planned.

## 5. Internal Squads and subscription delivery

- `Default-Squad` includes all four configured inbound identities: `Default-Profile / Shadowsocks`, `Default-Profile / VLESS-REALITY`, `Default-Profile / VLESS-XHTTP-TLS`, and `UAE-Main / Shadowsocks_1230`.
- `trial-squad` selects the three `Default-Profile` inbounds and not `UAE-Main / Shadowsocks_1230`. This is the reviewed access design, not a recommendation to broaden it.
- Subscription main settings observed: **Serve JSON at base subscription OFF**, **Randomize hosts OFF**, **Show custom remarks ON**, **HWID limit OFF**. The displayed fallback device limit of `999` is not evidence of an actively enforced HWID cap.
- Response Rules, in order: browser HTML; Mihomo; Stash; Sing-box; Clash; and final **XRAY_BASE64 fallback**. Preserve the final fallback rule and first-match ordering.
- The Xray JSON template defines Cloudflare DNS servers (`1.1.1.1`, `1.0.0.1`) and a conditional `bittorrent -> direct` rule. The reviewed v2rayN subscription is expected to follow the XRAY_BASE64 fallback, so **do not assume** that this JSON-template routing rule is applied to v2rayN runtime traffic without checking the effective client configuration.

## 6. Connection-quality evidence and limits

- The operator reports **excellent connection quality across MCI, Irancell, and Rightel** for the available service overall.
- XHTTP over CDN was explicitly reported to perform **very well on Irancell and Rightel**, while **occasionally unavailable on MCI**. This is a qualitative field observation, not a quantified reliability SLA.
- In a Windows **v2rayN 7.25.6** test, the then-present client connections responded to delay checks, with the REALITY UAE path producing one of the lowest observed delays. These one-off delays are **not** verified carrier-level performance, sustained bandwidth, or latency percentiles.
- A refreshed subscription in the audit demonstrated correct generation of the then-configured Host list. Do not reuse historical counts or delay samples as an up-to-date production inventory.
- No need for a new transport, new server, profile edit, routing change, TLS change, or software upgrade is established by the evidence at this checkpoint.

## 7. Security and operational follow-ups (not authorized work)

- **Credential hygiene:** Secret fields and subscription URLs were visible in operator-shared audit material. Avoid copying them into source control, tickets, chat summaries, or log output. Any token/key rotation must be **separately planned** with updated client provisioning, compatibility and rollback; no rotation has been executed by this documentation task.
- **Server routing:** Review private/metadata network egress exposure before introducing new routes. Existing empty server-side routing rules remain unchanged.
- **Client routing:** Confirm whether BitTorrent direct-bypass exists in *effective* client profiles before deciding whether it is a real privacy issue for the supported apps.
- **Operational measurements:** Memory pressure, particularly for small VPS instances, requires `MemAvailable`, Swap and OOM evidence, not utilization percentage alone. Do not treat snapshot RAM percentages as an incident.
- **Quality monitoring:** If service experience worsens, compare connection success, time-to-connect, session stability, packet loss, and repeatable throughput by carrier and transport before adjusting settings.

## 8. Decision and change boundary

**Decision (2026-11-01):** Keep the currently operating architecture and protocols. No feature expansion, upgrade, infrastructure changes, or new rollout is scheduled from this review. This document is a read-only operational baseline, not a deployment instruction.

**Evidence boundary:** Remnawave UI/config and Cloudflare settings were reviewed through operator-provided screenshots. The operator confirmed the present three-node topology and qualitative real-world quality. Current topology and exact counts have not been independently re-read from the API after the operator's administrative work.

**Related internal references:** [Remnawave API](009-REMNAWAVE-API.md), [Node API & telemetry](011-REMNAWAVE-NODES.md), [Network operations and health](036-DENABAN-OPERATIONAL-HEALTH.md).
