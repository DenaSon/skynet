# DenaGate — Denaban reliability and management KPI roadmap (2026-11-05)

Approved sequence: four **independent** phases. Only phase 1 is authorized for implementation in the current PR. Preserve Laravel modular-monolith Actions, private trusted Super Admin checks, existing Admin/Sales routes, financial journals, Remnawave gates and customer data. Each PR requires tests, review, CI and independent live Telegram acceptance. Merge to master deploys automatically.

## Phase 1 — reliability and actionable follow-up (current PR)

1. **Health completeness:** the latest persisted Spatie batch is healthy only if all four required basic checks (Database, Queue, Scheduler, Disk), optional Redis when in use, and all nine named operational checks are present and OK. Duplicate/missing/stale/unknown checks never show healthy. No health check runs from Telegram.
2. **Paid fulfillment attention:** a paid managed-plan order is actionable if its existing outbox is `needs_review`; a paid pending intent is overdue if created **more than 15 minutes** ago, or a processing intent if it has not been updated for **more than 15 minutes**. Do not infer a provider failure or activation status solely from this local delay.
3. **No hidden old orders:** existing twenty-latest orders screen remains intact. A new bounded, authorized, read-only attention directory sorts review cases first (oldest first), then overdue cases, with twenty per page and a 1–1000 validated page range. Each existing order detail still rechecks state/role. No new activation, retry, reconciliation or provider write behavior.
4. **Notification signal quality:** suppress *only* the following failed-delivery event types from Denaban attention counts and its actionable failed-delivery directory: `TrialLowTraffic`, `TrialExpiring5m`, `TrialEnded`, `SubscriptionExpiry24h`, `SubscriptionExpiry1h`, `SubscriptionLowTraffic`. Preserve `TrialActivated`, paid activation/renewal, billing, bank, order blocking and access/recipient failures for actionable event types. Preserve the previous `superseded_by_activation` exclusion. Health outbox monitoring applies the same importance filter.
5. **History and transport preserved:** no delivery records are deleted, modified or rescheduled; the actual sales/customer notification dispatcher continues unchanged. Only manager-facing summaries are filtered. Historical evidence remains in MySQL.

### Acceptance — source and CI
- Tests: missing base/Redis check even when all operations are OK; fresh/stale/partial health batches; old review orders beyond first twenty; paid vs unpaid and pending vs processing clock; validated pages and denied root; native RTL Rich list + existing callback navigation; low-priority event suppression vs important paid event preservation; no extra provider writes or settlement changes.
- Tests and static checks must pass PHP 8.4 (and 8.5 where CI supports it), PHPStan, Pint, existing MySQL/Redis CI. Do not claim real bot/network acceptance from fake tests.
- No migrations, new packages, ENV changes, scheduler changes, finance corrections, automatic provider operations or history deletion.
- Rollback: revert this one PR; all delivery and financial records are unchanged.

## Phase 2 — Denaban presentation (not started)

Retain the existing operational data and authorized actions; reorder the Rich first screen: **finance → users/subscriptions → action needed → nodes/network**. Concise two-column RTL tables, restrained conditionally visible action buttons, useful verified timestamps and safe native fallback. No KPI duplication in Denaban. Preserve all existing facts, callbacks and fields.

## Phase 3 — management KPI read model (not started)

Reuse canonical bank/wallet settled-sale sources and `users.created_at` without another ledger. Agree and test identical refund, renewal, timezone and half-open window definitions across the existing business reports before computing growth. Report new registered users, settled revenue, transaction count and unique buyers. Day: Tehran today-to-now vs yesterday-to-same-time; week: last seven *complete* days vs preceding seven; month: last *complete* Persian month vs the preceding complete month. Baseline zero does not produce a fabricated growth percentage. No schema, external analytics provider or settlement-write changes.

## Phase 4 — separate management KPI Rich UI (not started)

Add one `📊 شاخص‌های مدیریتی` entry to the Admin home, **separate** from Denaban. Native RTL Rich tables and native daily/weekly/monthly inline selectors update the same authorized report, preserving fallback and bounded queries. The existing 7/30-day detailed reports remain available; add no redundant KPI tables to Denaban. Explicit Telegram client validation remains separate from CI.

## Evidence and release

At the end of each phase record: implemented / tested (with exact CI outcome) / remaining / risks. Production execution of pending paid orders or alterations to customer balances are never part of these dashboard changes.
