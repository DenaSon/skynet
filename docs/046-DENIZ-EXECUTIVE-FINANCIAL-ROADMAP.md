## Deniz Executive / Decision Intelligence — baseline v1 closed (2026-11-10)

**Status: accepted as a practical base version; feature development paused.** The product owner reviewed real group replies after iterative corrections and reports the executive advisory mode has improved sufficiently, while customer support is also satisfactory, with no major issue requiring further development now. This is the current product decision; historical 'pending' phase descriptions below are no longer the active roadmap.

**Verified source:** [PR #446](https://github.com/DenaSon/skynet/pull/446) merged at `master` `4b995ba7c653a6a2ba140606233d6a31a24b6dcc`; [post-merge CI](https://github.com/DenaSon/skynet/actions/runs/42775938075) passed PHP 8.4 (1,758 tests / 12,446 assertions), PHPStan, Pint, MySQL and frontend. This is **not** independent production-SHA attestation or a quantified benchmark of real GapGPT strategy quality.

**Delivered baseline:** current local financial/Identity data through established trusted-root Actions; clear period/currency boundaries; read-only finance snapshots and limited Persian financial-intent follow-ups; strategic questions using bounded multi-KPI Decision Brief (sales two complete 7-day windows, current-month recorded accounting, point-in-time wallet balances), evidence/hypothesis separation, prioritized proposed action and KPI. Telegram Business customer support is separate and retains original authorization, budget and safety controls. No direct Remnawave/Provider read for Executive, no AI SQL or financial mutation.

**Operating decision:** do not add datasets, tools, autonomous actions, packages, models or UI under the completed AI initiative. Handle verified regression/security incidents through small approved PRs. Future AI phases may be proposed separately only if a real product requirement and acceptance tests justify them. Existing known limitations—different report windows, cost completeness, non-guaranteed causal inference and manual live validation—remain explicit and are **not** disguised as implemented capabilities.

# Deniz Executive — financial evidence foundation (AI-EXEC)

**Status (2026-11-10):** Phase 1 **merged** in [PR #439](https://github.com/DenaSon/skynet/pull/439), squash commit `bea2fded367af7a49e8ea2a63b3b617f11ff4e2f`. [PR CI 42713553455](https://github.com/DenaSon/skynet/actions/runs/42713553455) passed PHP 8.4 (1,730 tests / 12,159 assertions), PHPStan, Pint, MySQL migration/rollback and frontend checks. PHP 8.5 is scheduled-only in the current workflow and was **not** run for this PR. CI success does not establish Runflare deployment or live Telegram acceptance; **no AI/Telegram consumer is wired in phase 1**. Phase 2 was explicitly approved and implemented in [PR #441](https://github.com/DenaSon/skynet/pull/441); [phase-2 PR CI run 42750373338](https://github.com/DenaSon/skynet/actions/runs/42750373338) passed PHP 8.4 (1,736 tests / 12,233 assertions), PHPStan, Pint, MySQL migration/rollback and frontend checks. PR merge/deploy status must be checked independently; mocked CI does not verify Runflare, live GapGPT or real Telegram. Phase 3 was subsequently approved and merged as [PR #444](https://github.com/DenaSon/skynet/pull/444) with [green master CI 42755472745](https://github.com/DenaSon/skynet/actions/runs/42755472745). The scoped phase-4 quality improvements below were explicitly approved; live production acceptance remains separate.

## Deniz Decision Intelligence — approved value-focused scope (2026-11-10)

**Problem:** Source-correct numeric reports are already available in the Admin dashboard, but repeating them in AI does not deliver incremental management value. The approved refinement must prioritize **evidence-based decisions** over adding more KPI screens, model tiers, new Provider connections or autonomous actions.

**Implementation:** `ReadDenizDecisionBrief` composes a compact, strictly read-only decision view from the existing root-verified `ReadDenizExecutiveSnapshot` sources: `sales/week` (last complete 7-day window and equal prior window, direct/reseller/unknown channels, settled order count, buyers, renewals and concentration), `accounting/month` (current Persian month to date; recorded costs **not** guaranteed total expenses), and `wallet/current` (instantaneous credit liability). **These periods are different**; model and code MUST NOT divide, subtract or add cross-period figures or equate wallet balance with bank cash. No Remnawave read, no Provider/network query, no financial mutations, no new schema/packages/model/queue/permissions. The existing Super Admin trust checks and one-shot group safeguards remain authoritative.

**Screening heuristics only (not diagnoses):** candidates flag unusually low recorded-cost coverage of accounting sales, marked decline in settled revenue compared with the prior seven complete days, strong concentration among the three largest buyers, or a high fraction of wallet liabilities held by resellers. Heuristics are transparent and bounded; a flagged cost share is **not proof** of missing expenses, concentration is not churn, and reseller credit is not idle cash. If there is insufficient evidence, return no candidates and ask the next decisive question instead of inventing a risk. The selected focus (risk, growth, channel decision, general) comes from a small local routing rule, not autonomous SQL or provider tool calling.

**Executive response standard:** A *strategic* question such as «بزرگ‌ترین ریسک مالی چیست؟», «اگر یک اقدام برای رشد فروش پیشنهاد دهی؟», «کدام کانال را توسعه دهیم؟» or «چه اولویتی برای کسب‌وکار داریم؟» must return (1) a **specific key finding** with real source KPI and time window, (2) a **qualified interpretation** distinguishing evidence and hypothesis, (3) **one prioritized action** that is proportionate and feasible, and (4) a **measurable follow-up KPI**. It should not copy the dashboard or assert unmeasured ROI, acquisition costs, true bank balance, profit margin or a guaranteed winning channel. A question for one number keeps the established direct report path. The executive output/agent and existing safe group formatter are reused; no Business customer prompt or limits change.

**Acceptance:** in addition to feature tests for calculated facts, bounded prompt size, protected source access, no raw buyer data, period labeling, sparse evidence, negative scenarios and current-data follow-ups, evaluate real GapGPT responses in the trusted management Telegram group for the four scenarios above. CI fake replies prove wiring and invariants, **not the quality of reasoning**. Operators must check whether advice is grounded, prioritized, actionable, honest about unknowns and materially more useful than an existing dashboard. Treat missing behavioral evidence as a pending live-acceptance item, not a successful AI evaluation. Keep a simple PR revert available; deployment confirmation is separate.

## Deniz Executive insight quality — approved scoped correction (2026-11-10)

**Goal:** Turn the current numeric-report reply into a useful evidence-based senior accounting/business advisor, without granting provider access, provider requests, new AI models or finance writes. Implement in one independent, reversible PR; do not call this complete until source CI succeeds. Live model quality must be evaluated separately, not inferred from fake-provider tests.

**Strict local data boundary:** The approved current-period `users` evidence uses only the **existing local** Identity tables (`users`, `telegram_accounts`) and the **canonical** `ReadAdminBusinessReport` for 7/30-day new users, unique settled buyers and renewals. This action may issue local aggregate DB queries but **must never invoke** `ReadAdminDashboard`, `ReadAdminCommandCenter`, Remnawave factory, provider client, remote subscription/user scans or external HTTP. Registered and `is_active` accounts are **not** online users or active Remnawave subscriptions; unknown provider figures stay unknown, not zero.

**Financial correctness:** Explicit natural language `کل ورود پول` / `مجموع دریافتی` must select the existing `cash/all` report, even after an accounting follow-up. Canonical `ReadAdminCashReceipts` keeps top-up corrections, genuine bank receipts and wallet-funded purchases separate. For accounting, existing reporting windows are converted to Persian **on the Laravel server** using `PersianCalendar`: month-to-date starts at the first of the current Persian month (not the Gregorian 23rd). All report evidence includes clear Persian interval labels; AI must not calculate Gregorian-to-Jalali dates itself. Wallet summaries include the existing staff/other category so total reconciles with member/reseller balances.

**Decision-oriented insight:** Existing settled-sales KPIs already include transaction counts, unique buyers, renewals, registered users, growth, channel splits and concentration. Derive only safe arithmetic from these same windows: average order value and renewal share for sales, recorded top-up share of verified cash, registered-expense share of recorded sales, reseller share of ledger credit and local active-account share. Keep these sourced/calculated fields bounded and IRR-based; percentages with a zero denominator must be `null`, not fabricated. Do not claim causal proof, ROI, cohort conversion, provider online counts or bank cash balance.

**Prompt/response:** An independent `DenizExecutiveAssistant` has a modest **1,100-token** response allowance, while original `DenixAssistant` for ordinary group and Business replies retains 700 tokens; model catalog, provider, global 2,000-character input guard, management/Business quotas and timeout remain unchanged. A direct number question should still be brief; a strategy question should contain a key grounded finding, qualified interpretation, prioritized practical action and measurable follow-up metric. Do **not** merely repeat the deterministic Laravel headline. Never pass buyer identities, receipt details or raw transactions to the language model.

**Acceptance:** Tests verify local-only/zero-HTTP user reads, trusted root denial, 7/30 user/buyer metrics, full recorded cash intent, accounting Jalali boundary, extra wallet category, deterministic arithmetic signals, distinct Executive token setting and Business prompt isolation. PHP 8.4/8.5 where scheduled, Pint, PHPStan and MySQL CI must pass. Only after review merge to `master` (automatic deployment); operator must then independently test actual GapGPT reasoning and Telegram formatting, model cost/latency and expected user/finance numbers. No new migration, package, provider operation or scheduled job.

## Objective and approved phases

Deniz should become a trusted Super Admin-only management-group advisor for evidence-led financial, accounting, sales and growth decisions. The same customer-support persona and its permissions stay unchanged. Four independently reviewable phases:

1. **AI-EXEC-01 — Financial evidence snapshot (merged PR #439).** Add a small read-only Application Action returning bounded, explicit, machine-readable reports from existing canonical financial Actions. No Telegram or AI integration in this phase.
2. **AI-EXEC-02 — Executive interpretation (approved; PR #441).** Independent executive prompt and verified snapshots in the trusted management route, with clear fact / inference / recommendation distinctions. No Customer Business changes. Code/CI review is separate from live Telegram acceptance.
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

## Phase 3 — controlled natural questions and fresh-data follow-ups (2026-11-10)

**Approved implementation scope:** a small extension of the existing `PrepareDenizExecutiveAnalysis`, `DenixConversationState` and `AnswerDenixMessage`; no new AI tools, tables, bot, model, package, finance SQL or Provider writes. This is **bounded dataset selection**, not unrestricted AI tool/function calling.

### Natural managerial questions

Root managers in the existing Deniz group can ask a short ordinary Persian question with a clear financial topic, for example `/ai فروش ۲۴ ساعت اخیر چطور بوده؟`, `/ai چقدر شارژ داشتیم؟`, `/ai هزینه‌های این ماه چطور بوده؟`, `/ai وضعیت کیف پول‌ها چطور است؟`. A strict on-server keyword router maps to the **four existing canonical** datasets: sales, cash receipts, accounting and current wallets. Approved report windows remain the same. Explicit unsupported windows (e.g. sales current Persian month, cash today, arbitrary 3 days) must fail with a clear **nonbillable** message. Multi-dataset requests with different report-window semantics ask the manager to narrow the question rather than invent comparable data. Ordinary greetings and nonfinancial chat still use the original non-executive assistant.

### Context and security

After the **successful** sending of a root-authorized executive result, cache only `{dataset,period}` with the existing 30-minute TTL and existing `group:topic:telegram_actor` isolation. **Never cache financial figures, sampled customer rows, prompts or analytical replies** in the ordinary chat history. Follow-up questions such as `/ai چرا بیشتر شده؟`, `/ai چه پیشنهادی داری؟` or `/ai هفتگی چطور؟` may reuse the selector, but **read a fresh authorized snapshot on every request**, and pass only a new bounded aggregated evidence JSON to the chosen existing model. No historical model memory of financial figures or stale calculations is assumed. User `/ainew` or changing the AI model clears the selection along with the generic history. A failed inference, denied/revoked root, invalid period or uncertain Telegram delivery cannot create a new selector.

The existing intake root+group checks, rate limits (25/manager/10min; 250/group/day), one-shot queue and delivery checks remain authoritative. Root verification is repeated in the financial Action at execution time. Telegram command visibility does not constitute access. Business customer support remains separate and cannot request financial data.

### Limitations and acceptance

- Only one verified dataset per answer in phase 3; arbitrary joins, cross-dataset ROI, historical wallet balances, date ranges and model-driven SQL are **not** supported.
- Causal explanations are hypotheses unless recorded data proves them. The deterministic Laravel financial headline retains reported exact numeric facts; Deniz distinguishes observation, inference and proposed next action.
- Source CI must prove selector isolation across users/topics, new DB reads for each follow-up, reset/revoke/failure behavior, no finance in generic chat history, multi-topic rejection, unsupported periods, and Business isolation.
- Real production finance totals, live Telegram command registration and mobile HTML rendering require separate operator validation after deployment; mocked GitHub CI is not real-world acceptance. Phase 4 retains that live sign-off boundary.

## Deniz management-group formatting safety (2026-11-10)

The management group sender reuses the existing `DenizBusinessMessageFormatter` to convert a small Markdown subset (`###` headings, `**bold**`, links, code) to **escaped Telegram HTML**. This addresses raw Markdown in live group evidence without changing the Business support sender, models, permissions, queues or financial Actions. Every message remains bounded to 3,500 input characters before conversion; HTML tags are not truncated post-formatting.

On an explicit Telegram HTTP 400 *entity parse* failure only, a **single plain-text** delivery can be attempted with the same topic and reply target. Ambiguous timeouts, 5xx and other errors must not retry (avoid duplicate responses). No message body, BotFather secret, or upstream error is logged. Real mobile Telegram rendering is an operator acceptance check, not proved by mocked CI.

## Phase 2 polish — Telegram commands and rolling 24-hour sales (2026-11-10)

This narrow addition to phase 2 does not authorize open-ended phase 3, financial writes, new models or customer Business changes.

### Group-scoped command menu

DenixCommandCatalog is the single source of both the native Telegram slash list and the trusted group's shortcut-to-question mapping:

| Command | Group analysis/action |
| --- | --- |
| /ai | Type /ai followed by the custom question |
| /sales24h | Sales in rolling last 24h compared to the preceding 24h |
| /salesweek | Sales of last 7 complete Tehran days |
| /resellers | Reseller channel over last 7 complete Tehran days |
| /cash30 | Cash receipts over 30 calendar days including today |
| /accounting | Current Persian month-to-date accounting |
| /wallet | Current ledger-derived wallet balance |
| /aimodel, /ainew, /aistatus | Original group model/reset/status commands |

**Registration is an explicit operator step, not part of git merge or Scheduler:**

    php artisan deniz:telegram:commands:register --dry-run
    php artisan deniz:telegram:commands:register

The second command invokes Telegram Bot API setMyCommands with scope type=chat and chat_id=DENAGATE_AI_GROUP_CHAT_ID, using the distinct Deniz Bot token. Only this group scope is changed: global BotFather defaults, other groups and Admin/Sales Bot command menus are preserved. Existing management commands are included so they are not lost when the group-specific list is replaced. Without an actual successful operator invocation and Telegram UI verification, do not claim the live command menu is visible.

The group menu is only a discovery surface, **not** an access grant. The established webhook/queued-job active trusted Super Admin checks, rate limits, replay fences, model budget and financial authorization still govern execution; shortcuts consume the ordinary paid AI request quota. Non-root members may see the menu but cannot invoke financial analysis. Financial answers remain visible to anyone who can read this group; operators must restrict its membership. Wrong bot targets and extra shortcut arguments are ignored.

### The exact rolling 24h period

The additional sales/24h period extends the canonical ReadAdminManagementKpis → ReadAdminSalesChannelKpis → ReadDenizExecutiveSnapshot → PrepareDenizExecutiveAnalysis chain; no parallel purchases SQL. At request time T, compute the **half-open** periods [T-24h, T) and [T-48h, T-24h), convert Tehran endpoints to UTC for settlement queries, and preserve existing refunded wallet/bank history semantics, unknown channels, concentration redaction and null growth when the prior amount is zero. This is not sales/day (Tehran today to now). The existing transaction and read-only root checks remain unchanged.

Tests cover fixed-clock boundaries and refund behavior, AI snapshot evidence, slash alias routing and authorization, registration scope/offline dry run and failure paths. No migration or new dependency. GitHub PHP 8.4/PHPStan/Pint/MySQL verification is required before merge; real Telegram/BotFather UI and Production behavior need a separate operator check.

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
