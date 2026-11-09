# Deniz Executive — financial evidence foundation (AI-EXEC)

**Status (2026-11-10):** Phase 1 **merged** in [PR #439](https://github.com/DenaSon/skynet/pull/439), squash commit `bea2fded367af7a49e8ea2a63b3b617f11ff4e2f`. [PR CI 42713553455](https://github.com/DenaSon/skynet/actions/runs/42713553455) passed PHP 8.4 (1,730 tests / 12,159 assertions), PHPStan, Pint, MySQL migration/rollback and frontend checks. PHP 8.5 is scheduled-only in the current workflow and was **not** run for this PR. CI success does not establish Runflare deployment or live Telegram acceptance; **no AI/Telegram consumer is wired in phase 1**. Phases 2–4 remain pending separate authorization.

## Objective and approved phases

Deniz should become a trusted Super Admin-only management-group advisor for evidence-led financial, accounting, sales and growth decisions. The same customer-support persona and its permissions stay unchanged. Four independently reviewable phases:

1. **AI-EXEC-01 — Financial evidence snapshot (this PR only).** Add a small read-only Application Action returning bounded, explicit, machine-readable reports from existing canonical financial Actions. No Telegram or AI integration in this phase.
2. **AI-EXEC-02 — Executive interpretation.** Scope an independent executive prompt and integrate verified snapshots into the trusted management route, with clear fact / inference / recommendation distinctions. No Customer Business changes. Requires a separate explicit start, PR and acceptance.
3. **AI-EXEC-03 — Controlled follow-up questions.** Limited dataset/period selection, follow-up context and evidence-aware comparisons; do not grant arbitrary SQL, open-ended tools or financial writes. Requires separate approval and PR.
4. **AI-EXEC-04 — Security, quality and live acceptance.** Test root revocation, scope isolation, period alignment, fidelity, sensitive-data minimization, failure modes and actual authorized Telegram experience; release enablement remains explicitly controlled.

## Phase 1 implementation contract

Class: `app/Modules/AI/Application/ReadDenizExecutiveSnapshot.php`.

`handle(int $actorId, string $dataset, string $period): array` accepts only:

| Dataset | Accepted periods | Canonical data source | Temporal contract |
|---|---|---|---|
| `sales` | `day`, `week`, `month` | `ReadAdminSalesChannelKpis` → `ReadAdminManagementKpis` | day = today vs yesterday at same Tehran time; week = previous two complete 7-day intervals excluding today; month = previous two **complete Persian** months; half-open bounds |
| `cash` | `7`, `30`, `all` | `ReadAdminCashReceipts` | Tehran calendar days inclusive of today; `all` has null start; wallet balance is current, **not** historical |
| `accounting` | `7`, `30`, `month` | `ReadAdminAccountingReport` | Tehran calendar days inclusive of today; month is **current Persian month to date**, not the completed KPI month |
| `wallet` | `current` | `ReadAdminWalletSummary` | point-in-time ledger balances; no historical window |

**Mandatory authorization:** `AssertCatalogManagementAccess` / `SuperAdminTrust` checks a currently active, Telegram-linked and allowlisted Super Admin **at each Action invocation**. Underlying financial Actions recheck their own authorization. `finance.view` alone does not grant AI Executive access. This phase adds no Telegram routes, jobs or switches.

**Contract:** each record includes `schemaVersion=1`, `dataset`, `period`, `timezone=Asia/Tehran`, `currency=IRR`, `source`, `generatedAt`, numeric values and dataset-specific report bounds/limitations. All monetary values remain **integer rials**. A dataset or period mismatch throws a validation error; read failure is not a synthetic zero. Sales captures KPIs/channels/concentration from the same read transaction. The bounded AI-oriented result **does not export the top-buyer IDs**, names, receipts, bank references, account details, or ledger entries.

**Financial semantics:**

- Settled **gross purchases** are not net profit or bank cash. Verified historical bank settlements may survive refunded order status; refunded wallet-order amounts are excluded by the existing canonical report.
- **Cash inflow** is verified top-ups (after recorded correction) plus direct bank sales. A wallet-funded purchase is sales activity but **not a new deposit**. Unverified/pending/voided wallet top-ups must not be treated as incoming cash.
- Current wallet balances represent credit liabilities, not revenue. Their timestamp is not the end of the requested financial period.
- Accounting profit is **indicative** (only recorded expenses plus referral costs); owner draws are **not** expenses. `afterDrawsRials` is an arithmetic figure, not a bank balance.
- **Do not compare different dataset reporting windows** merely because a user calls them "this week/month". The snapshot intentionally does not invent aligned comparison metrics; future aligned-window requirements need a separately reviewed canonical read extension.
- Historical `buyer_category=NULL` is **unknown**, not an inferred direct/reseller order. A percentage without a denominator is `null`, not zero.

**Non-goals:** No AI provider/GAPGPT call, tool registration, prompt or model changes, Telegram delivery or group replies, DB schema/migration, new package, new query language, scheduled analytics storage, fee/settlement rewrites, operational expenses, Provider network calls, or finance write paths.

## Verification / release gate

Tests under `tests/Feature/AI/DenizExecutiveSnapshotTest.php` should cover: exact canonical figures, channel/period behavior, audited partial top-up correction, bank-vs-wallet cash distinction, derived wallet liability, recorded accounting expense and owner draw, omission of personal data, invalid inputs, revoked/nonroot and nonallowlisted identities, and no read-caused journal mutation or outgoing HTTP.

Require actual GitHub Actions PHP 8.4 suite / PHPStan / Pint / MySQL checks, with PHP 8.5 where supported. CI uses isolated fixtures and cannot establish the correctness of live Production data or real Telegram behavior.

**Deployment:** Production is automatically deployed on merge to `master`. Phase 1 must remain inert (no consumer, no runtime integration). Phase 2/3 integration and a real Telegram financial answer require separate authorization and deployment gates. If rollback is needed, revert the isolated code/test/doc PR; no migrations or financial records are changed.
