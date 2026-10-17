# Roadmap

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

