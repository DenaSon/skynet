# Roadmap

## NODE final review — focused Admin Bot UX and write safety (2026-10-17)

- Reviewed NODE-01/02/03 on `master` against `docs/017-TELEGRAM-UX-IDENTITY.md`, existing role checks, the version-pinned Remnawave Nodes API adapter, and all Node Pest cases. Scope stays limited to the existing MVP Node screens/actions; no new features, dependencies, migrations, flags, host installation, billing or Sales changes.
- List now presents one concise summary (total/enabled/connected), a single status-marked button per Node, and no duplicate rows. An active Node no longer advertises the forbidden Delete button (the server-side disabled-before-delete check is retained). Single-Node confirmations explain consequences of disable/restart/reset/delete, with distinct messages for invalidated state, conflicting in-flight operations and uncertain provider outcomes.
- Batch UI lists **exact target Node names**, desired reorder, selected profile/inbound count, changed field and **complete proposed value** at confirmation. Reorder controls are explicit numbered arrows, not clickable labels with hidden move behavior. Entering a different Node wizard invalidates the prior pending draft to prevent free-text replies reaching a stale form. Existing confirmation token and global write flag stay unchanged.
- Creation, single-Node and batch writes now share a single bounded `nodes:mutation` cache lock, including the fresh profile/inbound or Node GET and the one provider write. This conservatively serializes admin Node mutations in the MVP, avoiding cross-screen in-flight conflicts. Lock contention does not imply an attempted remote write. All operations remain root-only/private, one-time confirmed and OFF by default; no provider request is blindly retried after a possibly successful timeout.
- Acceptance boundary: dedicated fake-HTTP tests for UX text and lock contention plus existing NODE-01/02/03 suite, PHP 8.4/8.5, PHPStan, Pint and MySQL CI. Green CI does **not** verify real Telegram, production schema/deploy, Node/Host setup or live VPN connectivity. Operator-controlled live smoke remains pending.


## NODE-03 — Super Admin group Node operations (2026-10-17)

- **Approved MVP-only completion of the 3-phase Node scope:** Existing private Admin Bot «مدیریت نودها» adds «عملیات گروهی» with select-up-to-20, bulk enable/disable/restart/reset traffic; guarded bulk update of the existing Nodes API's supported fields, existing Config Profile + inbound selection, complete-list display reorder (up/down), and explicitly confirmed restart-all. Uses only the pinned \`RemnawaveNodesApi\` 15-operation adapter; no duplicate API clients, dependencies, new migrations or Sales/customer changes.
- **Safety:** allowlisted active linked Super Admin + private chat at every Telegram step and inside the shared Application Action. \`REMNAWAVE_WRITES_ENABLED\` remains OFF. Selection is bounded to a 20-node private view; a fresh panel Node GET verifies each selected UUID, name, connection and disabled state immediately before one remote operation. Reorder/restart-all additionally verify complete inventory; profile modification revalidates remote Profile/Inbound choices; bulk update uses a one-field, typed whitelist. Three-minute root-bound token is consumed under cache lock **before** writing, and the action uses one bounded batch lock. No automatic replays on timeout or ambiguous outcomes; user is instructed to reread remote state. Bulk restart and restart-all may disrupt users. No real production or staging provider operations are authorized by CI.
- **Acceptance:** isolated fake-provider/Pest test of all Node bulk endpoints plus boundary denial, stale state, no duplicate writes, remote timeout, cross-root and private-chat isolation, and full PHP 8.4/8.5, PHPStan/Pint and MySQL CI. Final live Telegram, real Remnawave Node/Host and VPN connectivity evidence is still a separate operator-controlled gate. The three planned Node feature phases are code-complete only when this PR and post-merge CI pass.


## NODE-02 — Single-node Super Admin controls (2026-10-17)

- **Approved scope:** existing Admin Bot Node detail now exposes guided **edit name/address**, **enable**, **disable**, **restart**, **reset traffic counter** and **delete** using existing 3.4.4 \`RemnawaveNodesApi\`; no bulk, Host/Node VPS installation or extra Node field wizard (NODE-03 separately planned). Admin-only \`node2:prepare/apply/cancel\` callbacks and the original legacy-text dispatcher handle a single private edit line, without breaking NODE-01 and prior routes.
- **Security:** current allowlisted Super Admin is reauthorized in each Telegram step and inside the Application Action before remote GET and immediately before the one write. All changes require \`REMNAWAVE_WRITES_ENABLED=true\`, which remains **OFF by default**, and an explicit three-minute actor-bound one-use confirmation consumed before the external mutation. Action verifies the exact existing Node UUID/name/status against a fresh GET; only allowlisted modes execute. Delete requires a previously disabled Node and explicitly warns that it is irreversible. At most one Node write can run under the same bounded cache lock. Provider timeout/unknown outcome is **never automatically retried**; UI instructs a read-only status check. A successful 202/204 acknowledges only the request, not actual remote state change.
- **Acceptance boundary:** isolated fake Remnawave/Pest permission, stale, rejection, one-time/timeout and all five mutation kinds plus edit; PHP 8.4/8.5, PHPStan/Pint, MySQL CI. No production flag changes, migration, provider request from this PR or real Telegram/Node claim. Live staged/Production acceptance still requires separate operator checks before enabling writes. Only NODE-02 implementation is authorized; NODE-03 bulk operations remain deferred.


## NODE-01 — Super Admin Node list and guarded creation (2026-10-17)

- **Approved limited MVP expansion:** Admin Bot existing «مدیریت سرورها» continues to show the original read-only overview; a new root-only «مدیریت نودها» screen and \`/nodes\` offer a bounded paged Node list, exact Node detail with connected/enabled states, and existing panel tags. Reuse the pinned \`RemnawaveNodesApi\` (GET operations); never expose raw provider payloads, addresses, tokens or URLs.
- **Create-only wizard:** «افزودن نود» → private one-line name/address entry → select one existing Config Profile → select one or more of that profile's existing Inbounds → preview → short-lived one-use root-bound confirmation. Application Action rechecks allowlisted active root, selected remote Profile/Inbound membership and \`REMNAWAVE_WRITES_ENABLED\` immediately before exactly one \`NodesController_createNode\` POST. The confirmation is consumed **before** the request; unknown outcomes require explicit read-only list inspection, never blind automatic retry. The flag stays OFF by default; no live provider writes authorized by merging source code.
- **Outside NODE-01:** edit, delete, enable/disable, restart/reset, bulk operations, Node installation on a VPS, Host creation, new finance/Sales behavior, migrations, new dependency or automatic runtime flag changes. Dedicated fake-provider/Pest tests and GitHub CI check authorization, stale/invalid state and no duplicate POST. Real Remnawave/Telegram/Node connectivity must be separately verified before production activation. Planned follow-up NODE-02 and NODE-03 remain separate approvals.


## MVP post-review safety fixes — queued root trust and reserved update recovery (2026-10-17)

- The existing Admin Bot fulfillment confirmation now carries the authenticated root user ID into the one-shot database Job. The worker **rechecks current Super Admin trust** before initiating either CREATE or GET-only reconciliation. Old queued Job payloads without an actor ID fail closed. The separately trusted operator CLI still calls the existing Application Action directly; no new Telegram routes or permissions.
- For already fulfilled and locally linked orders, a status-update reservation left in `reserved` by worker interruption is shown as «نیازمند بررسی» only when a matching `enable/disable` operation is recorded. The existing Admin «بررسی نتیجه» action uses the Lifecycle's strict GET-only reconciliation. It never retries the PATCH, and unmatched/foreign states remain blocked.
- Source regression tests cover revoked queued authorization and reserved-state recovery. Provider writes and Sales gates remain default-OFF; no DB schema, flags, finance, provider API or architectural changes. Green CI does not replace deferred live Telegram/Remnawave E2E.

## MVP-04 — Basic Super Admin subscription status management (2026-10-17)

- **MVP-only scope:** Existing Admin Bot completed-order detail now links to a small «مدیریت وضعیت اشتراک» screen. Only active, linked, allowlisted Super Admins in private chats may request a real, owner-linked customer's subscription status or explicitly confirm **Disable/Enable**. Eligible orders require `fulfilled` Sales state, completed fulfillment outbox, a unique local provisioning link and exact `sales-order:{id}` reference; the existing Lifecycle strictly verifies remote username/ownership marker before any status write.
- **Reuse and safety:** The existing `RemnawaveSubscriptionLifecycle::disable/enable/reconcileUpdate` and Remnawave users `PATCH` adapter perform all writes; never accept a provider user ID from Telegram. One-time, root-bound three-minute confirmation queues one bounded one-attempt database Job. Current root authority, order and remote state are rechecked in the worker. Uncertain provider write produces `needs_review`, no automatic retry, with an explicit **GET-only** reconciliation button. Existing idempotent operation reservation prevents competing writes. `REMNAWAVE_WRITES_ENABLED` remains OFF by default; no server flag change or live operation authorized by source CI.
- **MVP closure boundary:** No quota/expiration edits, delete/revoke, renewal, Node/Host UI, finance changes, new migration, extra dependencies or notification subsystem. Existing Admin/Sales routes and buyer subscription access remain. Code checks are isolated HTTP-fake/Pest/PHPStan/Pint and disposable MySQL; real Telegram/Remnawave, deployed DB schema, payment and client VPN connectivity remain a separately approved end-of-MVP staging/Production gate, never assumed from CI. This is the **fourth and final planned MVP feature phase**; post-launch enhancements stay deferred.

## MVP-03 — Customer subscription delivery polish (2026-10-17)

- **MVP-only completion of SB-06, not a new subscription feature.** On the existing Sales Bot «اشتراک‌های من» detail, an unactivated/unpaid subscription now links directly to its **owner-authorized Sales order** for payment/status. Existing order details retain the fulfilled → subscription link. Back, refresh and Home remain intact; no new callbacks/routes or business Actions.
- The existing explicit, private and **freshly authorized** subscription-link callback delivers the same credential only in the dedicated private message, now with a native **«📋 کپی لینک»** button when the HTTPS URL fits Telegram's 256-character copy-text limit. Longer URLs remain selectable as message text with clear instructions; never truncate or store the credential in a callback/list/log. Link previews remain disabled, and the delivered message links back to the subscription detail. When the current secure usage GET cannot verify active/usable status, the detail screen offers refresh rather than an immediately futile link button; direct/stale link callbacks still independently re-authorize and fail closed via `GetSubscriptionLink`.
- Preserves existing `GetMySubscription`, `GetSubscriptionUsage`, `GetSubscriptionLink`, `ActiveSubscriptionAccess` and Remnawave client, admin routes, billing/checkout, flags and provider writes OFF by default. No migration, dependency, automated activation, notifications or feature outside this customer-delivery path. New isolated Nutgram/HTTP-fake regression is **not live Telegram/Remnawave proof**; operator E2E remains deferred to the end of the MVP. Next approved scope: MVP-04 basic authorized subscription enable/disable only.

## MVP-02 — Admin fulfillment outcomes (2026-10-17)

- **MVP scope:** enhance the original trusted/private Admin Bot `/fulfillment` listing with a single «✅ فعال‌سازی‌های انجام‌شده» destination (`full:completed`). The existing pending/processing/needs-review queue, root-only order details and explicit operator-confirmation actions remain intact. Both lists are bounded to the latest **20** applicable orders with refresh/back navigation; no search, pagination, analytics or new persistence layer.
- **Read-only truth:** `ReviewPaidFulfillment` can now read locally **fulfilled** managed-plan orders with a **completed** immutable fulfillment outbox. Detailed success means only that ParsaGate recorded the activation; it does **not** certify live provider connectivity. A paid order with an inconsistent `completed` outbox is *not* displayed as successfully activated. Existing paid-only `authorizeRequest`, remote POST, one-time confirmation, GET-only reconciliation and identity/role checks remain unchanged and fail closed.
- **Boundaries:** no finance changes, new jobs, Remnawave calls, migration, flag changes, notifications, automatic fulfillment or production activation. Source tests on isolated DB/HTTP fakes and CI are distinct from deferred real Telegram/Remnawave E2E. Next small planned MVP-03 is customer subscription-delivery completeness; MVP-04 is basic root-only enable/disable under independent approval.

## MVP-01 — Clear connected service and checkout blocker (2026-10-17)

- **Approved MVP-only scope:** reuse OP-02 Admin plan detail and `ManagePlanSquadBinding` to display the currently stored plan service as a **human-readable remote-verified name**, or a truthful missing/stale/unavailable state. One guarded read of existing local Plan–Squad mapping; only if its revision and local verification match, make **one existing GET** `InternalSquadController_getInternalSquadByUuid`. Remote UUID/name must match strictly; errors never claim connection. A temporarily unavailable remote name is displayed separately and does not silently modify the existing buyer checkout guard. Trust/RBAC are enforced in the Application Action and the existing private Admin presenter.
- The original `CheckPlanSquadReadiness` gate remains the decisive checkout guard; the Admin read-only screen shows one actionable Persian blocker in priority order: inactive plan, missing/stale/unpublished price, missing/stale service, globally unapproved service-sales readiness, or inactive product. No change to buyer checkout, API writes, DB schema, binding confirmation, prices, notifications, background jobs or runtime flags. The original «🔗 اتصال سرویس» action and Back navigation remain.
- **MVP first:** no generic readiness engine/DTO, squad browser rewrite, new wizard, Node/Host management, analytics or subscriptions automation. **CI with fake HTTP** supports code quality, not real Remnawave/Telegram/Production acceptance. The latter is intentionally deferred until infrastructure access is available. Next approved development scopes MVP-02 (Admin paid activation outcome), MVP-03 (customer subscription delivery), MVP-04 (only basic enable/disable), each subject to its own PR.

## OP-03 — Root-confirmed Admin fulfillment (2026-10-17)

- Admin Bot gains «🔌 فعال‌سازی اشتراک‌ها» and `/fulfillment`: trusted, linked, private Super Admin views only paid managed-plan orders with existing `sales_fulfillment_outbox` intent. Selection and 3-minute, per-root one-time confirmation enqueue a **single-order Laravel Job**. No provider request happens inside Telegram Webhook. Job implements unique order key, one attempt, bounded runtime and logs only generic outcome with order id.
- Actual provider POST uses **unchanged** `FulfillPaidPlanOrder` settlement journal verification, Plan revision/Squad checks, unique durable create reservation, terminal `needs_review` on uncertain writes and protected order-to-subscription mapping. Queue duplicates cannot cause a second POST. An ambiguous order offers separate root-confirmed **GET-only reconciliation**, reusing `FulfillPaidPlanOrder::reconcile`, with no blind create retry.
- When provider writes or globally verified Squad gate are OFF, activation action is unavailable. No finance enablement, user/client signup, automatic bank settlement, automatic paid-event fulfillment, Node/Host provisioning, new migration, provider flag or root role changes. Source CI and isolated fake-provider tests cannot prove real customer VPN connectivity; live two-bot/Remnawave/bank tests remain deferred together until after OP-03 by operator request.

## OP-02 — Plan–Squad management source checkpoint (2026-10-17)

- Trusted Super Admin only, private Admin Bot plan detail → «🔗 اتصال سرویس». The operator selects an existing Squad read from Remnawave `GET /api/internal-squads`, reviews a one-time confirmation and the authorized Provisioning Action verifies the exact UUID via `GET /api/internal-squads/{uuid}` before persisting a binding to the existing `sales_plan_internal_squad_bindings` table. Changes append a small `sales_plan_audits` row. No new schema/dependency, no Provider write, no creation of Nodes/Hosts/Squads.
- The DB write checks fresh trust/RBAC, current plan revision and rejects any plan already referenced by a Sales order. Selection is short-lived and per-actor, callbacks are Admin-only; stale/forged/revoked attempts fail closed. The live-verification **global flag remains OFF**, so this locally verified binding alone does not authorize Sales checkout or Provider writes. Real panel membership, Node/Host readiness and operator sign-off are deferred to the final Staging acceptance after OP-03.
- Code tests use an isolated database and HTTP fakes; they must not be described as live panel acceptance. CI PHP 8.4/8.5, MySQL, PHPStan and Pint must pass before merge.

**OP-01 operational preflight checkpoint (2026-10-17):** Production prerequisite verification is documented in [docs/026-OP01-OPERATIONAL-PREFLIGHT.md](026-OP01-OPERATIONAL-PREFLIGHT.md), using only existing read-only Laravel/Telegram/Remnawave checks and a PASS/BLOCKED ledger. This is a documentation/source completion only: previous operator evidence showed the 2026-10-17 SB-03 snapshot migration Pending and earlier Remnawave 0 Nodes/0 Hosts; both require **fresh Production readings**. No schema, ENV, Telegram webhook, finance, Provider or job execution has been changed; OP-01 live acceptance remains open until sanitized operator output. OP-02/03 are separate phases with independent approval.

**Sales checkout UX correction (2026-10-17):** Existing Sales Bot catalog/builder and home now describe the guarded plan selection/order flow truthfully; Admin plan detail shows current *read-only* Sales checkout readiness using existing Plan–Squad and published-price checks. Buyer order text differentiates payment OFF / submitted / paid-awaiting-provisioning / fulfilled. No new checkout, Plan–Squad authoring, finance/provider writes, auto-fulfillment, schema or flag changes. Production SB-03 migration and real Remnawave/Bank E2E remain unverified; see [Sales UX correction](025-SALES-BOT-ROADMAP.md#sales-checkout-ux-correction--2026-10-17-after-sb-06).

**SB-06 code checkpoint (2026-10-17):** Sales-only /subscriptions, owner-scoped paginated list/detail, live credential-free usage/quota/UTC expiry when remotely verified, protected explicit private-link send and clean Back/Home route; reuses existing Provisioning/Identity Actions, no Admin/legacy route replacement. Remote unavailable/expired/exhausted/mismatched state fails closed; provider writes, public signup, bank and notifications remain OFF until separately approved live gates. PHP 8.4/8.5 and MySQL CI must pass; private Sales /start/menu smoke passed but real subscription E2E not yet tested. See [SB-06 acceptance checklist](025-SALES-BOT-ROADMAP.md#sb-06--sales-subscription-visibility-and-final-private-navigation-2026-10-17).

**SB-05 code checkpoint (2026-10-17):** Existing paid Sales outbox receives an explicitly invoked, default-disabled one-order Remnawave provisioning Action and GET-only reconciliation. Genuine bank/wallet settlement and current immutable verified Plan–Squad matching required; exactly one protected remote CREATE, unique order/subscription mapping, terminal review on ambiguous results, no automated retry, no new schema or consumer. Production provider writes remain OFF; staged provider acceptance deferred. Operator-reported Sales private UX smoke: 17 processed updates / 1 reachable linked contact. Detailed scope and safety: [docs/025](025-SALES-BOT-ROADMAP.md).

**SB-04 source checkpoint (2026-10-17):** Sales-only managed-plan receipt entry + private photo handler reuse existing `ManualPaymentUi` and `ManualBankPayments`; Admin Bot root `/payments`, mandatory independent `/bankconfirm` reference and single-use decision are unchanged. Buyer order detail shows latest own receipt state (pending/rejected/approved), with no bank/reference/receipt-photo disclosure. Cross-bot receipt/media and decision notifications reuse MB-05A outbox, `source_bot_key` and bounded Telegram transfer; Sales never sends Admin-only callbacks as a fallback. All bank/notifications/Sales/provider switches remain OFF by default; suppressed intents are not replayed, actual notifications require a separate operator-approved rollout. No schema, provider write or automatic bank verification. Manual Telegram/bank acceptance deferred. See [025-SALES-BOT-ROADMAP](025-SALES-BOT-ROADMAP.md) and [021-MB05A operations](021-MB05A-NOTIFICATION-OPERATIONS.md).

**SB-03 code checkpoint (2026-10-17):** Approved managed-plan checkout adds a Sales-only buyer-bound 10-minute preview/confirm, revalidates the full `PlanPriceQuote` under a DB transaction, and persists one idempotent `pending_payment` order in existing `sales_orders` with immutable plan/pricing revisions and verified Internal Squad UUID. Existing historical F09 orders remain compatible; unlimited `time` traffic uses NULL. `squad_bindings_live_verified=false` and no verified, current plan binding mean checkout is refused, not silently enabled. No bank/payment/provisioning/provider or public Sales activation. CI and real Telegram/provider/bank acceptance are separate; manual acceptance deferred. [Sales roadmap](025-SALES-BOT-ROADMAP.md).

**SB-02 code checkpoint (2026-10-17):** The Sales-only `/catalog` and `/builder` paths reuse the already-tested `PlanCatalogUi` and `ResellerBuilderUi`, with buyer-authorized `ListQuotedPresets`, `ListResellerBuilders` and `CalculatePlanQuote` pricing. Includes safe list/detail/page, 10-minute custom volume/days and current-role checks. **No checkout/order, finance, provisioning or public Sales enablement**. Source/CI and real Telegram acceptance remain separate. See [025-SALES-BOT-ROADMAP.md](025-SALES-BOT-ROADMAP.md).

**EPIC-SB / SB-01 (approved 2026-10-17):** Six phased Sales Bot plan and acceptance boundaries: [docs/025](025-SALES-BOT-ROADMAP.md). SB-01 introduces only isolated Sales /start, /menu, /account, one-time /link and explicit member opt-in using shared Identity Actions; original Admin/Member/Reseller routes remain. Production Sales/signup/notifications/provider writes stay OFF. Automated CI is not real Sales Bot smoke; operational tests were explicitly deferred.

**CURRENT two-phase RW-04 Admin panel users handoff — 2026-10-16:** RW-04A [PR #75](https://github.com/DenaSon/skynet/pull/75), merged `ee4a367`, CI [38960730198](https://github.com/DenaSon/skynet/actions/runs/38960730198) **PASS**; RW-04B [PR #76](https://github.com/DenaSon/skynet/pull/76), merged `8ef3280`, CI [38961728997](https://github.com/DenaSon/skynet/actions/runs/38961728997) **PASS**. Source and fake-HTTP/SQLite/MySQL CI closed for **both** scoped Admin-only read features. Super Admin-only Inline «👥 کاربران پنل» + `/panelusers`, page/refresh, status, detail quota/usage/expiry, strict subscription username+shortUuid check. No protected link, token, raw config, provider Write, Sales, Node or schema change. The earlier wording below saying RW-04B PR/CI is pending is historical; **live Runflare deployment and real Admin Telegram acceptance are not yet supplied**. Operator next tests `/panelusers`→select fixture→refresh→back plus stale/revoked/ordinary-admin denial and logs. This is **not** full RW-03/04 live lifecycle sign-off, nor customer Sales ownership acceptance.


**RW-04A / RW-04B operator-approved admin user read scope (2026-10-16):** RW-04A source merged to `master` as [RW-04A PR #75](https://github.com/DenaSon/skynet/pull/75), commit `ee4a367`, green PHP 8.4/8.5 + MySQL CI [run 38960730198](https://github.com/DenaSon/skynet/actions/runs/38960730198). Adds root-only Admin Bot Inline «👥 کاربران پنل» and `/panelusers`, 10-row GET-only `/api/users` pages, pagination/refresh/back, safe username+status projection. Existing local «👥 کاربران» Reply menu is preserved unchanged, so the new panel users entry is **Inline only** with command fallback; never confuse local users and provider users. RW-04B follows as a separate PR: root-only detail selection with GET `/api/users/{userId}` + GET `/api/subscriptions/by-id/{userId}`, bounded quota/used/expiry/status and a strict `username + shortUuid` match, no provider user ID in the protected subscription payload. No raw subscription URL, password, connection key, user email, provider writes, migrations, existing finance, Node/Host provisioning or Sales Bot changes. **Green CI is not live Telegram acceptance**; operator must confirm real Admin Bot menu, paging, detail, refresh, root-only denial and regression after auto-deploy.


**Approved P1 Admin Server Overview (2026-10-16):** Extend existing root-only Remnawave server screen with `GET /api/system/stats` counts: CPU cores, panel-reported RAM total/used/free, uptime, total/online users. Live read-only shape observed on Runflare; no new service/Node/SSH/OS telemetry, writes or Sales. P0 remains functional if the optional stats endpoint fails; real Telegram smoke still pending. [Bounded scope](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md#server-overview-p1--approved-additive-read-only-statistics-2026-10-16).


**SRO-03 explicit operator-approved simplification (2026-10-16):** Remove the temporary Admin read visibility flag; always show the existing read-only server overview to trusted Super Admin, while preserving double authorization, safe errors and independent Write/Squad gates OFF. Source change and CI do **not** constitute a live Telegram acceptance; real Runflare/Telegram smoke still required. See [updated SRO-03 read-only overview decision](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md#sro-03-approved-simplification--remove-admin-reads-flag).


**Current development increment — SRO-02 (2026-10-16):** Existing P0 Admin-only server overview gains guarded Inline/hybrid menu and refresh/back plus tested empty-Node/unauthorized/error paths; **code in independent PR; CI and real Telegram rollout distinct**. SRO-03 requires explicit trusted Production read-flag approval; provider writes/Sales remain OFF. [Frozen scope & handoff](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md#h-sro-02-implementation-checkpoint--2026-10-16).


**Current operator checkpoint — 2026-10-16:** Isolated Remnawave **3.4.4** staging is online; real Laravel read/health/inventory and one disposable staging user Create/absolute Update/Disable/Enable were operator-tested. RW-01/02 lack full formal sign-off; RW-03 is **PARTIAL**, no Node/Host; one typed subscription ownership assertion failed (17/18 batch), not a proven leak. The **next planning scope is MB-05B-live P0 Admin-only server overview with zero Nodes**, phase-split **SRO-01 → SRO-02 → SRO-03**; no implementation/flag changes, no sales/provider writes. See [current evidence and exact scopes](024-REMNAWAVE-LIVE-CHECKPOINT-ADMIN-READ-PLAN.md). **This supersedes historical “staging unavailable / BLOCKED” statements below, which remain as dated history.**


All statuses reflect **code in the repository**, not intentions. Planned milestones are explicitly marked as **NOT STARTED**. The public product brand is **ParsaGate**; legacy repository, database and provider-reference identifiers remain unchanged.

**MB-06A LIVE REVOCATION ACCEPTANCE (2026-10-16):** Operator-reported five of five Telegram checks passed (old Admin Users button, `/users`, `/admin`, old Reseller builder button and `/builder` denied following role revocation without `/start`; no new processing errors). This closes live stale-role denial, **not** full MB-06A Production sign-off: real queue-job completion and any remaining operational evidence are pending. Detailed [operator evidence](023-MB06A-RELEASE-VERIFICATION.md). RW-01 may begin independently when an isolated staging VPS is available.

**Final MB-06A provider-independent CI checkpoint (2026-10-16):** [PR #65](https://github.com/DenaSon/skynet/pull/65) passed PHP 8.4/8.5/MySQL with real DB queue execution in a disposable test database and focused legacy Member/Reseller/ordinary Admin/role-revocation coverage. **Engineering code/CI scope closed; full live acceptance OPEN** pending actual Production queued-job evidence and any still-unrecorded operator cases; subsequent non-root role/revocation smoke was operator-reported as passed (above). No new users, money/provider writes, flags, or runtime features; see [release matrix](023-MB06A-RELEASE-VERIFICATION.md).

**Latest MB-06A Production checkpoint (2026-10-16):** deployed 23 migrations `Ran`, six read-only Super Admin Bot smoke areas and callback fixes passed, `www` database queue worker observed, Runflare Cron execution operator-confirmed; full MB-06A acceptance still pending observed job processing and any unrecorded itemized routes (non-root/role-revocation smoke was subsequently operator-reported as passed). [Detailed acceptance evidence](023-MB06A-RELEASE-VERIFICATION.md). Sales/notification delivery/provider writes remain OFF.

**Approved execution contract, dependency update (2026-10-15):** [Remnawave & Multi-Bot Roadmap](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md). **Current path:** MB-01 (documentation accepted, PR #52) → MB-02 (PR #53 merged, `213458f`; production smoke pending) → MB-03 (PR #55 merged `e8a780b`; live smoke pending) → MB-04 (PR #56 merged, `8da5e13`; live smoke pending) → MB-05A (PR #57 merged, `580c64a`; two-bot live smoke pending) + MB-05B offline (PR #58 merged, `480f2a9`; live deferred) → MB-06A (PR #59 source/CI checkpoint and limited 2026-10-16 operator Admin smoke; full acceptance still open). **Deferred until staging server:** RW-01 → RW-02 → RW-03 → RW-04 live completion → MB-05B-live → MB-06B final acceptance. RW-04 offline fixes may proceed only when narrowly justified. This is a documentation-only re-sequencing, **not** authorization to begin development or server provisioning.

| Increment | Scope | Status |
| --- | --- | --- |
| F0 — Laravel Bootstrap | Laravel 13 skeleton and initial push | Completed on `master` |
| F1 — Architecture & Context | Module boundaries/providers, docs-as-code, registration test | Completed on `master` (PR #1) |
| F2 — Developer Tooling | PHP >=8.4.1 and portable lock; Pest 5/Larastan 3/Pint; architecture checks and CI | Completed (PR #2; passing CI on PHP 8.4/8.5) |
| F3 — Identity | Session auth; admin bootstrap; role/active access; JSON user management; tests | Completed (PR #3, PHP 8.4/8.5 CI green; 23 tests) |
| F4 — Telegram Foundation | Nutgram, secure webhook, deduplication, private account linking, inline menus | Completed (PR #4; 40 tests, CI PHP 8.4/8.5) |
| F5 — Identity RBAC | Dynamic roles/permissions, guarded Super Admin enrollment, role migration, audit and tests | Completed (PR #5, PHP 8.4/8.5 CI green; 52 tests) |
| F06-A — Telegram Navigation | Home, account, settings, admin/read-only submenus, keyboard routing and role gating | Completed on `master` (PR #6; 58 tests, PHP 8.4/8.5 CI green) |
| F06-B — Telegram Users | Search, list, profile, one-time confirmed state updates through Identity Actions | Completed (PR #8 merged; 69 tests, PHP 8.4/8.5 CI green) |
| F06-C — Telegram RBAC | Role directory, custom role creation, code-defined permissions, multi-role assignments, single-use confirmations | Completed (PR #10 merged; 83 tests / 359 assertions, PHP 8.4/8.5 CI green) |
| F07 — Onboarding & Referral | Opt-in private Telegram registration, member-only accounts, unique invitations, single-shot attribution, invitation counts | Implemented (PR #12; 95 tests/426 assertions CI green); real E2E pending |
| F08 — Billing/Wallet | Non-withdrawable reseller wallets, manual bank-transfer topups, two-posting ledger, root review, idempotency, Telegram operations | Completed on master (PR #13 merged; 106 tests / 494 assertions, PHP 8.4/8.5 CI green); live bank E2E pending |
| F09-A — Product Catalog & Pricing | Product limits 1–100 GB, 15/30/60/90-day tariffs, direct/reseller quotes (5%), terminal admin setup | Implemented and verified (PR #15; 115 tests / 557 assertions, PHP 8.4/8.5 CI green) |
| F09-B — Telegram Subscription Builder | Price shown on first buying screen, duration/volume options, custom integer input, read-only quote preview | Verified in PR #16 (135 tests / 660 assertions, PHP 8.4/8.5) |
| F09-C — Orders | Server-side fresh-quote validation, immutable order price snapshot, buyer-bound idempotent pending-payment orders, `/orders` history | Verified in PR #16 (135 tests / 660 assertions, PHP 8.4/8.5) |
| F10-A/B/C — Provider API, discovery and Nodes | OpenAPI v3.4.4 catalog (218 ops), read-only inventory, NodesController 15 routes and telemetry | **Merged on `master`** (PRs #19/#20/#21); PHP 8.4/8.5 CI green; no live panel E2E |
| F10-D — Remnawave Users + durable lifecycle | Typed Users/Subscriptions adapters, safe DTOs, SQL reservations, exact squad check, create/update read-only reconciliation | **Merged on `master`** (PR #23, master CI run 38213468652: 200 tests / 959 assertions); no Telegram/checkout provisioning |
| F10-E1 — Local ownership bridge/read model | Unique Sales-order↔Provisioning link, Sales owner-scoped keyset cursor, safe immutable subscription summaries/statuses, no provider traffic | **Merged PR #26**, master CI 38218297939 green. No payment or delivery |
| F10-E2 — Authorized shared subscription reads | Fresh Telegram/web actor identity, owner-aware list/detail, guarded secret link and safe accessible-nodes data | Merged (#27, 2026-10-12); no live E2E |
| F10-E3 — Telegram subscription presenter | Private /subscriptions menu, paged owned summaries, safe details, guarded link and nodes, UX/forgery tests | **Merged on master** (PR #28, commit 3dd9e61; PHP 8.4/8.5 CI + master run 38223868886 green); live BotFather/Remnawave E2E pending |
| F11-A1 — Wallet settlement foundation | Reseller-only, disabled-by-default wallet debit + paid order + unique pending outbox in one DB transaction, no dispatch | **Merged PR #30**, squash 76ea9e1, master CI 38226617474 green; default-disabled, no live bank/provider E2E |
| F11-A2 — Super Admin manual bank review | Telegram photo claims, private Super Admin approve/reject with two-step manual bank reference, atomic balanced direct-bank settlement + outbox | **Merged PR #33** (SHA 1cd472e; master CI 38231567621 green); default-disabled, real bank/operator E2E pending |
| F11-B0 — Unified Billing ledger and strict module boundaries | Pre-production baseline migrations, Billing-owned bank reference + shared journal/postings, wallet replay attestation, architecture tests, ADR 0008 | **Merged** ([PR #35](https://github.com/DenaSon/skynet/pull/35), SHA 8e04572; master CI 38238151791 green); live flags OFF |
| F11-B1.1 — Plan catalog foundation | Draft-first presets/builders, type/bound/audience validation, root CRUD + confirmations/audit, role-scoped listing | **Merged** ([PR #37](https://github.com/DenaSon/skynet/pull/37), SHA c3d52c8; CI 38243565483 green); NOT purchasable |
| F11-B1.2 — Plan pricing | Root-managed fixed/formula/unlimited/reseller tariffs, single-use publish, bounded integer-rial quote + buyer/revision revalidation, tests | **Merged** ([PR #39](https://github.com/DenaSon/skynet/pull/39), SHA 027c83c; master CI 38245902790 green); read-only, no orders |
| UX-TG-01 — Telegram hybrid navigation | Opt-in two-column role-aware Reply Keyboard on home/admin, existing Inline screens, secure text dispatch, regression tests and rollout flag | **Merged PR #43**, green PHP CI; real BotFather smoke pending |
| F11-B1.4 — Telegram plan management | Super Admin private plan and tariff CRUD, single-use previews, lifecycle publishing and audits, no provider dependency | **Delivered via PR #44**, green PHP 8.4/8.5 CI; private BotFather E2E pending |
| F11-B1.5 — Read-only Telegram plan discovery | Private /catalog, buyer-authorized preset browsing, published quote previews, stale callback denial, copyable Super Admin templates | **PR #45**, 298 Pest tests/1818 assertions, PHP 8.4/8.5 PHPStan/Pint green; live BotFather QA pending; no checkout |
| F11-B1.6 — Reseller builder preview | Private /builder, bounded GB/day selectors, 10-min buyer-scoped custom text input, current formula quote and role enforcement, no money/provider writes | **PR #46**, green PHP 8.4/8.5 CI (305 tests/1898 assertions), live private Telegram E2E pending |
| F11-CATALOG-01 — Telegram base products and F09 rate management | Private root /products, add/edit/status, 15/30/60/90 legacy rate editing, one-use preview, optimistic snapshot/audit, no payment/provider writes | **PR #47**, green PHP 8.4/8.5 CI (313 tests/1979 assertions); live Telegram E2E pending |
| UX-POLISH — Telegram identity and guided UI | [UX identity](017-TELEGRAM-UX-IDENTITY.md), root-only grouped navigation, native one-tap copy, step-by-step product/price and plan forms, selective plan editing; preserve all Actions and previous commands/callbacks | [PR #48](https://github.com/DenaSon/skynet/pull/48); PHP 8.4/8.5 CI required, real Telegram smoke pending; no provider/finance writes |
| EPIC-RW — Remnawave staging & operational API | Real staging and compatible admin reads; bounded direct user lifecycle; [execution contract](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md) | **RW-01 online (operator evidence), RW-02 P0 reads live, RW-03 partial staging CRUD, RW-04 full acceptance OPEN**; no Node/Host |
| EPIC-MB — Multi-Bot foundation & Admin operations | MB-01 [inventory](019-MB01-TELEGRAM-ROUTE-INVENTORY.md) + [ADR 0009](adr/0009-dual-telegram-bot-boundaries.md); MB-02–04 admin foundation; MB-05A notifications, MB-05B offline read models, MB-06A regression; later live integration | **MB-01 ACCEPTED (docs-only, PR #52); MB-02 MERGED (PR #53, `213458f`; PHP 8.4/8.5 and MySQL smoke CI green; live smoke pending); MB-03 MERGED (PR #55, `e8a780b`; PHP 8.4/8.5 + MySQL CI green; live Telegram smoke pending); MB-04 MERGED in PR #56 (`8da5e13`; 341 tests/2196 assertions CI green; live smoke pending); MB-05A MERGED (PR #57, `580c64a`; CI green, live two-bot smoke pending); MB-05B OFFLINE MERGED IN PR #58 (`480f2a9`; provider reads OFF until real verification); MB-06A CODE IN PR #59 (CI and operator Telegram smoke tracked separately); MB-06B NOT STARTED**; real provider requires RW-04; public Sales Bot deferred |
| F11-B1.3 — Provider binding | Plan to Internal Squad mapping, bounded bytes/UTC expiry | Planned |

| F11-B — Fulfillment/delivery | Explicit plan → squad mapping, bounded bytes/expiry, reconciled activation and owner-safe link delivery | Planned; NOT implemented |
| F11-C — Admin operations + staging | Permissioned audited actions, one-use confirmation, live panel and bank/BotFather E2E gates | Planned; NOT implemented |
| F11-D — Telegram sales activation UX | Direct and reseller post-settlement delivery over common Application Actions | Planned after F11-A/B and E2E |
| Later — Web UI | Livewire 4 + Mary UI consuming the same Actions | Deferred |
| Later — Product Domains | Define network/server/business capabilities based on use cases | Undecided |

## Current engineering checkpoint (2026-10-12)

- `master` PR #23 merge SHA `ec43b945138af3711db03bf418d0f382495e4a97`; [master CI 38213468652](https://github.com/DenaSon/skynet/actions/runs/38213468652) succeeded on PHP 8.4/8.5 with 200 tests / 959 assertions. Automated fakes **do not** certify live Remnawave/BotFather/bank behavior.
- **Immediate work:** F10-E1/E2/E3 are merged (last: [PR #28](https://github.com/DenaSon/skynet/pull/28), master CI 38223868886 green). F11-A1 merged (PR #30; master CI 38226617474); F11-A2 manual bank review merged (PR #33, CI green), OFF by default; F11-B0 unified ledger/Billing boundary merged (PR #35, master CI 38238151791 green). **Sequencing decision:** B1.4 was prioritized before B1.3 because Remnawave is not installed. B1.5/B1.6 may prepare read-only UX next, but B1.3 squad mapping and B2 dispatch/staging E2E still gate live provisioning. Never enable live money/provider writes before production DB concurrency and isolated staging E2E.
- **Hard sequencing gate:** `pending_payment` is not settled and F08 top-up credit is not service purchase. Do not connect F10-D writes to checkout/Telegram before F11 paid transition, deduped debit/outbox and isolated staging verification.
- Details and entry points: [000-CONTEXT.md](000-CONTEXT.md) is the canonical development handoff; this roadmap is milestone-level, not a second memory.

## F3 acceptance criteria
- Login/logout work through Laravel session auth with CSRF, throttling and rotation.
- An admin can be bootstrapped without shipping known passwords.
- Admin can list/create users and modify role/active state; members/guests cannot.
- Active-session deactivation, self-lockout and last-admin protections have feature tests.
- Existing tests, Larastan and Pint pass on PHP 8.4/8.5.
- Documentation matches behavior; no network features introduced.

## F4 acceptance criteria
- Portable Nutgram Composer lock resolves on PHP 8.4 / 8.5.
- Webhook rejects missing/mismatched secrets and invalid updates; retry receipts cover duplicates, in-flight and failed updates.
- User binding uses expiring one-time hashed codes, no public sign-up; private chat and active-role gates apply.
- User/admin Telegram inline menus load; no simulated admin functionality.
- Tests, static analysis and Pint checks pass; deploy steps and docs updated.

## Deferred architectural choices
Remnawave is chosen for the subscription panel API (F10). ParsaGate's own SSH/WireGuard/Core/Edge transport/tunnel/agent design, dedicated reachability testing, and live deployment topology still require separate decisions.

## F2 acceptance criteria
- `composer.json` and `composer.lock` install consistently using PHP >=8.4.1, without workstation mirror URLs.
- Pest executes existing tests and new architecture checks; static analysis and Pint run successfully.
- GitHub Actions runs tests, analysis and style verification on PHP 8.4 and 8.5.
- Context, standards, README and architectural rationale are synchronized.


## F06-A acceptance criteria
- `/start` and inline Home / Account / Settings / Administration navigation work in a private chat without creating messages for every click.
- Account text reflects the current Identity role set and active status; settings is explicitly read-only.
- Role previews are available only to trusted Super Admins; user-management previews to authorized admins.
- Unauthorized/stale callback queries cannot expose protected pages.
- Pest, Larastan and Pint are green on PHP 8.4 and 8.5; no migration or new dependency.

## F06-B acceptance criteria
- Existing /users and admin inline link open a real server-side paginated list (six per page) with stable ordering.
- Search by internal ID, email or a single name fragment, with short-lived per-actor search state and no public registration.
- Details show roles, active state and Telegram binding flag (never credentials or raw Telegram IDs).
- Status changes require a 3-minute single-use actor-bound confirmation; stale requests, disabled actors, peer-admin edits, self-deactivation and Super Admin mutations are rejected by the shared Identity Action.
- Every successful status mutation is written to the existing Identity audit log; no extra package or migration.
- Nutgram fake tests, Identity tests, Larastan and Pint pass on PHP 8.4 and 8.5 before merge.

## F06-C acceptance criteria
- Only an active, Telegram-linked, ENV-allowlisted Super Admin can enter RBAC screens and run the shared Identity RBAC actions. An ordinary admin/member must be denied even with forged callbacks.
- List roles and permission catalog, inspect their assignments, and create a custom role without changing PHP enums.
- Add/revoke existing, code-defined permissions on **custom** roles, and toggle one of several roles for existing users; protect the last remaining user role.
- Never assign/revoke `super-admin` or mutate any built-in role permissions via bot/HTTP UI.
- Every mutation requires a three-minute actor-bound, one-time confirmation; reject stale/replayed modifications and log each successful change to the Identity audit table.
- Read/actions callable by future Livewire presenters; no new migration/package; update living docs in same PR.
- Pest, Larastan and Pint pass on PHP 8.4/8.5 before merge.

## F07 acceptance criteria
- No admin identity overwritten or unilaterally converted to a public member; `/link` for operator-created accounts still works before signup.
- `/start` and deep links alone must never create accounts. Explicit one-tap opt-in in private Telegram chats, guarded by an off-by-default deployment flag.
- Every new account is `member`, without a verified email claim or a usable pre-shared password, and is uniquely linked to the Telegram numeric ID.
- Referral codes are high entropy, stable per referrer, and no personal identifiers are exposed in links. One invited account gets at most one referrer, only when first registered. Invalid/expired/deactivated flows never rewrite attribution.
- No wallet balances, commissions, sales data, provider coupling, generic registration HTTP route or new networking decisions.
- Full Pest/Larastan/Pint CI on PHP 8.4 and 8.5, then real BotFather private chat E2E before public release.

## F08 acceptance criteria
- A role-gated reseller may view only their own wallet and request credit. No account balance is writable by users or read from a mutable `users.balance` field.
- Wallet entries are integer rials; Telegram displays toman. Amounts are bounded; no floating-point arithmetic.
- A claimed receipt never credits money. Operator must independently confirm bank transfer and amount; only an active, linked, ENV-allowlisted Super Admin may approve or reject.
- Approval writes a unique verified bank reference, two balanced postings and immutable journal in one database transaction. Repeated requests, approved receipts and stale callbacks never double-credit.
- Rejection posts no credit; root review actions use a single-use, 3-minute, operator-bound confirmation. A rejected/approved request cannot be moved again.
- All financial details remain in Billing module; Sales, wallet purchases, reversals, payment gateways, commissions, refunds, withdrawals and network providers remain out of scope.
- Pest, Larastan, Pint PHP 8.4/8.5 required to merge. Live bank reconciliation and Telegram E2E must gate enabling `BILLING_TOPUPS_ENABLED`.

## F09-A acceptance criteria
- Products/rates are small, operator-managed DB records, not guessed tariffs or user-editable pricing. No HTTP or Telegram write endpoint.
- Active customers can read available products and request a **read-only** quote. Inactive/nonexistent buyers are denied.
- Volume is integer GB (1–100 globally; product-specific limits may narrow the range) and durations are explicitly 15/30/60/90 days.
- Quote formula is `base_price_rials + selected_gb × per_gb_rials`. An active `reseller` role gets 5% discount using exactly the same public rate; direct buyers pay full public rate. No float arithmetic. Zero percent is allowed by code config.
- Disabled products/rates cannot be quoted. Product rate edits affect future quotes; F09-C is responsible for immutable order snapshots.
- No orders, payments, wallet debit, provider API, Telegram purchase flow or subscription activation in F09-A.
- Run Pest, Larastan and Pint on PHP 8.4/8.5 before PR merge.

## F09-B acceptance criteria
- Logged-in active private-chat customer can open buying flow from Home or /buy. An empty catalog clearly shows unavailable rather than fake prices.
- When one product is active, buying opens directly to durations with **real** computed minimum-volume prices (1 GB globally, or narrower product minimum). Multiple products display safe paginated selection with starting prices.
- Valid duration shows preset volumes (1/10/30/50/100 where allowed, plus exact min/max), **each with current price**. A special button accepts integer custom 1–100 GB (or narrower bounds), including ASCII/Persian/Arabic digits, after a 10-minute per-actor opt-in.
- Quote preview shows actual public price, 5% role-based reseller discount where applicable and final price; re-evaluates price and role server-side. Fractional tomans round *up only for display*.
- Unauthorized, forged, inactive, expired or now-disabled selections cannot access sensitive data, mutate wallet or persist orders.
- No buy confirmation button, payment, orders, Remnawave API or migrations/new dependencies in F09-B. CI + live E2E acceptance are distinct.

## F09-C acceptance criteria
- A customer sees price before making an order; the explicit confirm button is bound to the user and a ten-minute preview and cannot be forged or used by another account.
- An order is only created if the active identity, product, duration, volume, public price and role-dependent discount are still valid and **exactly match the preview**; stale quotes require a new preview.
- A successful callback stores one price snapshot and `pending_payment` order; database-unique idempotency key prevents two orders on retry. A retry returns the same order even if the catalog rate later changes.
- Customer sees only their own orders (latest ten and individual detail). Snapshot never silently changes after operator modifies catalog prices.
- F09-C adds no balance debit, wallet reservation, payment confirmation, bank integration, subscriptions, Remnawave API or server/network code. Any future paid order transition must be implemented and verified separately.
- Tests cover ownership, duplicated callback, token expiry, stale quotes, role revocation, inactive buyer and zero Billing postings.

