# DenaGate — Denaban reliability and management KPI roadmap (2026-11-05)

Approved sequence: four **independent** phases. Phase 1 merged via [PR #374](https://github.com/DenaSon/skynet/pull/374) with successful PHP 8.4, PHPStan, Pint and MySQL CI. Phase 2 merged via [PR #375](https://github.com/DenaSon/skynet/pull/375) with successful PHP 8.4, PHPStan, Pint and MySQL CI. Phase 3 merged via [PR #376](https://github.com/DenaSon/skynet/pull/376) with successful PHP 8.4, PHPStan, Pint and disposable MySQL CI. **Phase 4 is the only implementation scope of the current PR.** Preserve Laravel modular-monolith Actions, private trusted Super Admin checks, existing Admin/Sales routes, financial journals, Remnawave gates and customer data. Each PR requires tests, review, CI and independent live Telegram acceptance. Merge to master deploys automatically.

## Phase 1 — reliability and actionable follow-up (merged PR #374)

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

## Phase 2 — Denaban presentation (merged PR #375; real-device QA separate)

Reorder the existing trusted Super Admin Denaban display to **finance → users/subscriptions → action needed → nodes/network** in both the native RTL Rich primary screen and equivalent native HTML fallback. Show key metrics in four compact two-column Rich tables, including node/host counts; retain the existing collapsed per-node telemetry, metric caveats and Tehran report timestamp. Place the existing attention summary **inside** the attention section, after finance and users, not ahead of them.

Rearrange only the existing inline keyboard: finance first, conditional fulfillment/notification/receipt follow-up next, servers and health afterward, refresh/back and view toggle last. Preserve every original callback ID and feature/role-based visibility condition; never unhide an action to bypass authorization. Do not add KPI comparison data, change snapshots/queries, alter health-check scheduling, modify financial figures, add packages/routes/migrations or perform Provider/financial writes.

**Acceptance:** both Rich and native render all existing metrics with the same values, the four headings appear in the approved order and unknown stays unknown; the Rich report retains its HTML-escaped, collapsible node details and short Tehran footer. Preserve authorized private root access, deny revoked/group roles, preserve safe fallback, all enabled/disabled action buttons and stable callback IDs, no additional provider operations. Focused fake-Telegram tests and repository CI are required; actual Telegram iOS/Android rendering, expansion and navigation require separate operator verification. Rollback is a single PR revert.

## Phase 3 — management KPI read model (merged PR #376)

New private root-authorized `ReadAdminManagementKpis` Application Action accepts **only** `day`, `week` or `month`. No bot UI or route is added in this phase.

The existing `ReadAdminBusinessReport` now exposes one authorized `settledTotalsBetween` read method used by *both* the current 7/30-day report and the new KPI action. It retains the verified bank and wallet `UNION ALL` query and its exact earlier semantics: verified bank settlement amounts remain counted even if the order status later changes to refunded, whereas corrected/refunded wallet orders are excluded. This describes **recorded gross settled purchase activity**, not bank balance, new cash, net profit or net-of-all-refunds sales. Wallet top-ups and pending orders are excluded. `sales_renewal_orders` remains the canonical renewal marker and buyer uniqueness comes from the buyer ID of settled rows. The phase does not retroactively alter existing revenue/accounting figures.

**Date windows** are half-open `[from, until)`, based on calendar days in `Asia/Tehran` and converted to UTC solely for indexed SQL reads:
- `day`: Tehran midnight → captured current time; previous Tehran midnight → **yesterday at the identical local clock time**, not all of yesterday.
- `week`: the last **seven complete** Tehran days, excluding today; previous seven complete days immediately preceding them.
- `month`: the **last complete Persian civil month** versus the previous complete Persian civil month. Reuse `PersianCalendar::dayOfMonth` to locate month starts through leap Esfand, year boundaries and unequal month lengths; no approximation using 30 Gregorian days.

Both periods expose Tehran-local `from` and exclusive `until`, revenues in rials, transaction count, unique buyers, new purchases, renewals and **new registered local users** (based on `users.created_at`, not bot contact count). Growth percentages are rounded to one decimal. If the previous amount is zero, percentage is `null` (undefined), including both-zero scenarios; clients in phase 4 must show a label instead of fabricated infinity/zero. Use actual `generatedAt` in Tehran and do not equate new user accounts with active/paid subscribers.

**Acceptance / safety:** authorized root and invalid-period denial, canonical 7/30-day report parity, genuine bank/wallet settlement inclusion and corrected wallet exclusion, buyer deduplication, renewal separation, exact UTC SQL boundaries, incomplete current day, week upper edges, 29/30-day leap Persian Esfand and year transition, positive/negative/zero-baseline growth, no writes/no Provider/Telegram requests. Full PHP 8.4 tests, PHPStan, Pint and disposable MySQL CI must pass before merge. No migrations, new dependencies, settings, caches, finance actions, provider calls or new Telegram endpoints. Rollback is a single PR revert.

## Phase 4 — separate management KPI Rich UI (current scoped PR)

Add one **trusted Super Admin-only** inline entry `📊 شاخص‌های مدیریتی` directly after `🏔️ دنابان` in the existing private Admin home. Its callback `kpi:period:day` opens a new **independent** KPI screen; no KPI figures are inserted into Denaban and no existing `stats:overview` / 7/30-day stats entry is removed. Do not add a second persistent Reply Keyboard item, command, duplicated report Action, dependency or migration.

Use the existing `ReadAdminManagementKpis` only, with **private Telegram chat, fresh root trust and Action RBAC** on every report callback. Register exactly one Admin-only `kpi:period:{period}` route; reject unknown periods, and offer a safe return to the daily period. Supported period buttons are `✅ روزانه`, `هفتگی`, `ماهانه` (active marker moves to the current period), plus refresh and `◀️ مدیریت`. Use **`TelegramUi::editRichText`** on callbacks to edit the same message; do not send one message per selection or change notifications.

Show two concise native **RTL Rich tables**: (1) settled purchase revenue of recent and previous intervals, growth, recent transactions, unique buyers, new purchases and renewals; (2) new registered accounts in recent and previous intervals and signup growth. Label actual Tehran dates, clarify day-to-the-same-time, two full 7-day periods or complete consecutive **Persian months**. Treat exclusive SQL period end as an **inclusive display date** for complete periods, and preserve the time-of-day for incomplete-day comparisons. Show signed growth with readable Persian digits and `بدون مبنا` when the prior interval was zero, never false 0% or infinity. Footer contains the *actual* Action report time in Tehran and precise gross settled-sales limitation (not net profit/top-ups). No user-controlled data, secrets, fake values or irrelevant metrics in Rich content.

If Rich editing fails due to Telegram compatibility, **native HTML fallback** uses the same authorized snapshot, dates, comparisons and inline buttons. Read failures show a concise error without synthetic zeros; denied, revoked or nonprivate actors cannot open any period or see figures. Preserve callback navigation, all existing Admin/ordinary-admin menus, Sales Bot routing and older reports. Source tests include Rich payload/RTL/tables/footer, native matching output, period switching, empty baseline, percent formatting, role/group denial, disabled/nonroot navigation and router separation. Do not claim device-rendering acceptance from mocked messages.

**Release:** CI must pass PHP 8.4, PHPStan, Pint and MySQL checks; no provider, financial, database schema, settings or scheduling operations. A single PR revert rolls back all new UI/routes without touching phase 3 calculations. Real Telegram iOS/Android and deployed Production verification remain separate, explicit acceptance tasks.

## Follow-up UX correction — persistent KPI entry (operator-approved 2026-11-05)

Original phase 4 prohibited an extra persistent Reply Keyboard item because its design used the **inline Admin menu** only. The operator observed that the Admin Bot actually uses the hybrid persistent bottom menu and explicitly authorized direct KPI access. This focused follow-up supersedes **only** that earlier keyboard restriction.

- Insert `📊 شاخص‌های مدیریتی` immediately after `🏔️ دنابان` in trusted Super Admin `ReplyMenuRenderer::adminKeyboard`, as a first-row pair. Preserve every previous button, including `📊 آمار و گزارش‌ها`, and the inline `kpi:period:day` callback.
- Register the label in `ReplyMenuLabels::all()` and route the text through the existing `ReplyMenuHandler` with private-chat checks, freshly resolved trusted-root authorization, and the existing read-only `AdminManagementKpiUi::show` Action guard. Daily report opens by default; its Rich tables and daily/weekly/monthly callbacks are unchanged.
- Deny copied, stale, revoked and nonprivate text taps through the established guarded path. Tests cover placement, text routing, live authorization, and the preserved Admin menu.
- No finance/provider operations, migrations, dependencies, new callback routes, duplicated KPI engine, or Sales Bot changes. Verify repository CI before merge; real Telegram client / Production smoke remains separate. Revert this single PR to roll back.

## KPI-INSIGHT-01 — collapsible management narrative (approved 2026-11-05)

One independent, reversible PR after the existing four phases. Scope is display-only in `AdminManagementKpiUi`; `ReadAdminManagementKpis`, canonical settlement rules, Tehran calendar bounds, RBAC and existing callbacks remain unchanged.

- Add a **collapsed native Rich** `<details><summary>🧠 تحلیل عملکرد دوره</summary>` after the two existing KPI tables and before the Tehran footer. Four concise sections: **💰 فروش**, **👥 کاربران**, **📈 برداشت مدیریتی**, **💡 نکته قابل بررسی**.
- Rich and native HTML fallback share one deterministic helper using **only** the existing authorized result; fallback shows short readable inline analysis rather than unsupported collapsible tags. No new queries, external AI, migrations, permissions, routes, buttons, or dependencies.
- Sales analysis uses the existing local Persian interval, actual settled amount and delta, and signed growth if defined. Registration analysis uses previous/current signups, delta and signed growth. Handle rising, falling, stable, previous-zero and both-zero states; avoid infinity, fabricated growth or causal explanations.
- Combined summary may report opposite sales and registration trends but cannot attribute reasons or infer customer retention/conversions. Suggestions to compare transaction and average-basket metrics are recommendations, **not findings**.
- Preserve the original daily/weekly/monthly filters and same-message edits, financial caveats, timestamp, Rich tables, original fallback, error behavior, and trusted private Super Admin gates. No Provider, billing, MySQL schema, Sales Bot or Denaban behavior changes.
- Regression tests: realistic +103.3% weekly sales / −16.5% registrations, falling sales / increasing registrations, stability, zero baselines, both-zero windows, Rich details after the two tables and before the footer, native fallback parity and existing period/RBAC tests. Require full PHP 8.4, PHPStan, Pint and MySQL CI before merge. Real Telegram expand/collapse and Production deploy remain independent operator QA.

## Evidence and release

At the end of each phase record: implemented / tested (with exact CI outcome) / remaining / risks. Production execution of pending paid orders or alterations to customer balances are never part of these dashboard changes.

## KPI improvement follow-up — approved four-phase plan (2026-11-06)

This is a separate follow-up to the original four Denaban/KPI phases above. The operator approved the plan and authorized **only improvement phase 1** for implementation and closure. Keep each phase in an independent PR; do not start phases 2–4 inside phase 1.

| Phase | Scope | Status |
| --- | --- | --- |
| 1 — complete comparisons | Compare recent/previous settled sales, order count, unique buyers, non-renewal purchases and renewals; add average settled order value and percentage change. | Implemented in this scoped source change; CI and live device acceptance are separate. |
| 2 — sales channel data | Define immutable buyer category for new orders, historical unknown handling and read-only direct/reseller and top-three-buyer aggregates. No guessing historical category from discounts or current roles. | Planned; separate implementation authorization required. |
| 3 — reseller presentation | Compare direct/reseller sales and shares, buying resellers and top-three-buyer concentration in concise Rich/native views. | Planned; separate implementation authorization required. |
| 4 — management narrative | Short visible takeaway and expandable, data-supported observations plus one relevant suggestion using all approved metrics. | Planned; existing narrative is unchanged in phase 1. |

### Improvement phase 1 — comparison contract

- Change only `AdminManagementKpiUi`, focused tests and necessary documents. Derive comparisons from the existing authorized `ReadAdminManagementKpis` current/previous snapshot; **no added SQL, changed settlement definition, date windows, schema, configuration, dependencies or provider operations**.
- Keep two compact RTL tables. Sales rows show the recent value, previous value and signed percentage change in a two-column layout with line breaks inside the value cell; retain the registration table, existing expandable narrative, exact period filters, refresh/back callbacks and Tehran footer. Label non-renewal orders «خرید غیرتمدیدی», not new customers.
- Average order value is the period's canonical settled revenue divided by its settled order count, including both new purchases and renewals. It is **not** revenue per unique buyer. Use unrounded ratios for percentage comparisons and round only displayed average tomans to the nearest whole toman. This is display-only statistical arithmetic, never a monetary posting or repricing operation.
- Zero orders means an undefined average: show «بدون سفارش» for the value and «قابل محاسبه نیست» for its change if either period has no orders. A genuine zero average with nonzero orders remains zero. Zero previous values for otherwise defined comparisons remain «بدون مبنا»; do not invent infinity or a zero growth rate.
- Rich and native HTML use the same comparison rows. Rich transport failures preserve values, units, dates and native buttons. Keep existing private trusted-root checks and same-message callback behavior.
- Acceptance: actual bank/wallet fixture reads through the existing callback; all count comparisons; weighted order average vs unique buyers; rising/falling/stable and zero baseline cases; absent vs zero averages; rounding without distorting growth; no reads/writes during presentation; matching Rich/native values and transport-error fallback; existing role, group, period and settlement regressions. Run PHP 8.4/8.5, PHPStan, Pint and applicable MySQL/Redis CI; record actual outcomes in the PR. Native iOS/Android rendering remains operator QA.
- Compatibility inspected 2026-11-06: official Bot API changelog remains 10.3 and pinned Nutgram 4.50.3 supports `InputRichMessage(html:, is_rtl:)`; reuse the current compact table/line-break transport, with no SDK upgrade. Rollback is a single PR revert, with no customer data change.
