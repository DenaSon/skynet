# Deniz Executive — financial evidence foundation (AI-EXEC)

**Status (2026-11-10):** Phase 1 **merged** in [PR #439](https://github.com/DenaSon/skynet/pull/439), squash commit `bea2fded367af7a49e8ea2a63b3b617f11ff4e2f`. [PR CI 42713553455](https://github.com/DenaSon/skynet/actions/runs/42713553455) passed PHP 8.4 (1,730 tests / 12,159 assertions), PHPStan, Pint, MySQL migration/rollback and frontend checks. PHP 8.5 is scheduled-only in the current workflow and was **not** run for this PR. CI success does not establish Runflare deployment or live Telegram acceptance; **no AI/Telegram consumer is wired in phase 1**. Phases 2–4 remain pending separate authorization.

## Objective and approved phases

Deniz should become a trusted Super Admin-only management-group advisor for evidence-led financial, accounting, sales and growth decisions. The same customer-support persona and its permissions stay unchanged. Four independently reviewable phases:

1. **AI-EXEC-01 — Financial evidence snapshot (this PR only).** Add a small read-only Application Action returning bounded, explicit, machine-readable reports from existing canonical financial Actions. No Telegram or AI integration in this phase.
2. **AI-EXEC-02 — Executive interpretation.** Scope an independent executive prompt and integrate verified snapshots into the trusted management route, with clear fact / inference / recommendation distinctions. No Customer Business changes. Requires a separate explicit start, PR and acceptance.
3. **AI-EXEC-03 — Controlled follow-up questions.** Limited dataset/period selection, follow-up context and evidence-aware comparisons; do not grant arbitrary SQL, open-ended tools or financial writes. Requires separate approval and PR.
4. **AI-EXEC-04 — Security, quality and live acceptance.** Test root revocation, scope isolation, period alignment, fidelity, sensitive-data minimization, failure modes and actual authorized Telegram experience; release enablement remains explicitly controlled.


## Phase 2 implementation contract — explicit Deniz Executive analysis

Phase 2 uses the **existing** management-group `/ai` or direct reply-to-Deniz path, the existing dedicated `ai` queue, 25-request/manager/10min and 250-request/group/day limits, the current two verified GapGPT models and the existing one-shot Telegram send. The authenticated group/actor check runs at webhook intake and again in the async job. The financial snapshot Action additionally rechecks the **current trusted, active, Telegram-linked Super Admin** before data access, and the job rechecks group/actor rights before calling the model or sending the response.

Only **explicit** analysis/report phrases at the *start* of the manager's question opt in to financial reads. For example:

| Explicit manager question in the existing group | Dataset and authoritative window |
| --- | --- |
| `/ai تحلیل فروش امروز` | `sales/day` (today vs yesterday at the same Tehran clock time) |
| `/ai گزارش نمایندگی هفتگی` | `sales/week` (last complete seven days, not calendar week to date) |
| `/ai تحلیل فروش ماهانه` | `sales/month` (**previous complete** Persian month vs the preceding complete month) |
| `/ai گزارش نقدینگی ۷ روز` / `۳۰ روز` / `از ابتدا` | `cash/7`, `cash/30`, `cash/all` |
| `/ai تحلیل مالی ماه جاری` / `گزارش حسابداری ۷ روز` | `accounting/month` (month to date) / `accounting/7` |
| `/ai گزارش کیف پول` | `wallet/current` (current ledger-derived liability) |

Unspecified supported periods use a fixed, clearly labeled default (sales = last seven **complete** days, cash = last seven **calendar** days including today, accounting = current Persian month, wallet = current). Unsupported **explicit** windows such as "sales this month" or "cash Persian month to date" yield a friendly nonbillable notice instead of silently reinterpreting the request. General group chat continues via the original conversational prompt with **no financial reads**.

**Data handling:** `PrepareDenizExecutiveAnalysis` selects from the four existing `ReadDenizExecutiveSnapshot` datasets, serializes a strict aggregated field allowlist as a bounded JSON user message (source, timezone, currency, actual reporting windows, metrics and concentration without buyer identities), and always shows an **application-rendered deterministic canonical facts header** with the real Tehran report time and monetary values correctly converted from integer rials to tomans. This prevents an unconstrained LLM from being the sole source of the displayed headline values. Any financial read failure or oversize input produces a clear notice **without a paid inference or fabricated zeros**. No raw customer records, invoice references, names, Telegram IDs, bank receipt details, arbitrary SQL, tools or provider calls are sent to the LLM.

**Distinct system context:** `DenizExecutiveKnowledge` is the **executive-only** system prompt. It requires evidence/inference/action separation, conservative causal interpretation, actionable KPI suggestions, and correct sales/cash/wallet/profit distinctions. It does **not** replace `DenizKnowledge` (ordinary group assistant) or its Business-only customer-support extension. The existing agent's tool list stays empty. Both established models remain selectable; no new models or model-wide token/cost changes.

**Context boundary:** In phase 2 each executive request analyzes **one authorized dataset/window**, without AI tool calls, cross-dataset joins or conversational follow-up routing. Executive questions and responses are **not saved** to the existing ordinary-group conversation cache, avoiding accidental reuse of stale monetary figures. Multiple datasets, arbitrary date ranges, conversational drilldowns and follow-up selection are reserved for separately approved phase 3.

**Privacy:** Only trusted Super Admins can initiate a financial request; however, any member of the configured Telegram group may be able to **read** the result. Operators must ensure group membership is appropriate for sensitive aggregate finance. All outgoing model/Telegram calls require active authorization. Failure, callback, AI and telemetry logs must not include raw financial evidence or customer details.

**Verification for phase 2:** Focused fake-provider/Telegram tests must validate accurate source money in sent group text, strictly aggregated and bounded JSON in the GapGPT request, exact root-only execution rights, nonfinancial chat and customer-support isolation, unsupported-period notices without AI billing, no financial state persistence in chat history, and one-shot/uncertain delivery. Full repository CI (PHP 8.4, PHPStan, Pint, MySQL, frontend) and a reviewed PR are required before merge. Scheduled PHP 8.5 is separate. Real Runflare deployment, connected management group membership and actual GapGPT/Telegram answer quality **cannot be inferred from fakes**; final real-world acceptance remains phase 4.

**Non-goals:** No Admin/Sales/Business bot changes, new menu/command or provider permission, schema or migration, new package, finance mutation, AI SQL reader, web interface, plan-margin or ROI calculations, or scheduled digests. Rollback is an isolated PR revert.

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
