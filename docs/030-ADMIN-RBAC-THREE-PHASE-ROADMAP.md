# RBAC-ADMIN-01 — Three-phase granular Admin Bot permissions roadmap

**Status:** APPROVED FOR PLANNING/DOCUMENTATION ONLY, 2026-10-25. **No implementation, migrations, data changes, ENV changes, real Telegram sends, or production rollout authorized by this document.** Each phase requires a separate explicit implementation instruction, scoped PR, PHP 8.4/8.5 CI, review, guarded master merge and operator-confirmed real acceptance.

**Authority:** [docs/005-ROADMAP.md](005-ROADMAP.md), [docs/007-RBAC-OPERATIONS.md](007-RBAC-OPERATIONS.md), [docs/017-TELEGRAM-UX-IDENTITY.md](017-TELEGRAM-UX-IDENTITY.md), [docs/018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md](018-REMNAWAVE-STAGING-MULTIBOT-ROADMAP.md) and [docs/021-MB05A-NOTIFICATION-OPERATIONS.md](021-MB05A-NOTIFICATION-OPERATIONS.md).

## Goal / boundaries

Give a real Admin Bot operator only the areas and actions that the operator's approved role permissions permit, without granting Super Admin. Extend **both immediate receipt notifications and recurring reminders** to eligible delegated reviewers; this is a *required Phase 1 acceptance criterion*, not deferred polish.

- Keep one Laravel modular monolith, existing Spatie Permission (web guard), Identity Actions, private Admin Bot registry, current Sales Bot routes, existing durable notification outbox/Redis worker/Scheduler, Banking/Billing journal, audit, and Remnawave provider guards.
- **No second RBAC system**, generic ACL rule engine, extra queues, new Bot, new roles hardcoded for every combination, dependency, or broad UI refactor. Prefer a small centrally tested capability checker reused at the entry/menu, every callback/command, and the Application Action.
- Super Admin remains **strictly protected**: active Spatie super-admin role + linked Telegram ID + configured two-ID allowlist + SuperAdminTrust::eligible; promotion only by trusted CLI. Never grant root power through a custom permission, role editing, or mere Admin Bot entry. Preserve historical root menu and actions.
- Protect **data and operations**, not just Telegram buttons. An old or forged callback, direct command, legacy Admin route, web endpoint, revoked role, unlinked/inactive account, or unauthorized bot must fail closed on each request. All Admin screens remain private and Persian/minimal; provider secrets and internal phases never appear.
- No changes to Sales Bot customer menus or buyer rights; never turn a reviewer into a reseller or grant cross-user data on Sales Bot. Financial receipt state, reference attestation, ledger idempotency, approval/rejection, renewal/create fulfillment, owner checks, RW write switches and rollbacks remain unchanged.
- **Current baseline (master c6840933, 2026-10-25):** built-in admin has users.manage; trusted root has broad management. Built-in member/admin/super-admin roles are protected from ordinary permission mutation. Current admin is allowed Users/search/status and customer 360 read without financial wallet access. Other Admin Bot sectors are chiefly gated by canManageRoles/root checks. Receipt outboxes and the 15-minute reminder iterate trusted root IDs. Existing generic role manager can create/assign custom roles and toggle code-defined permissions; assigning a custom role does not currently grant the root-only financial or server screens.

## Capability contract / least-privilege matrix (PROPOSED; not currently deployed)

| Code-defined capability | Read/write boundary | Phase | Legacy behavior |
| --- | --- | --- | --- |
| users.view | Local user list, search and basic detail (no status mutations) | 1 | Existing admin with users.manage retains full current user read |
| users.manage | Existing normal-user create/status controls; no root/peer-admin grants or role grants | 1 (existing) | Keep existing permission, built-in admin role and current behavior |
| purchases.view | Read/list one-time bank purchase receipts, protected receipt media | 1 | Trusted roots unaffected |
| purchases.review | Approve/reject purchase claims with existing mandatory real-bank-reference/settlement/provider readiness checks | 1 | Requires purchases.view for delegation; trusted roots unaffected |
| topups.view | Read wallet recharge receipt queue and photo (not arbitrary wallet balances) | 1 | Trusted roots unaffected |
| topups.review | Approve/reject wallet recharge with existing verified bank reference, journal and final confirmation | 1 | Requires topups.view for delegation; trusted roots unaffected |
| support.view | Read-only customer 360 and owner-linked service data; no financial wallet balance by itself | 2 | Existing users.manage support reads remain accepted for compatibility |
| reports.view | Limited operational/sales/user summaries; no sensitive wallet/ledger or business income breakdown without finance.view | 2 | Trusted roots retain full current reports |
| finance.view | Read-only finance, wallet balances and approved receipts reports; no banking decisions or accounting mutations | 2 | Separate from purchases/topups.review; trusted roots unaffected |
| servers.view | Safe provider GET-only infrastructure/node/host/health views, without credentials or mutating buttons | 2 | Trusted roots retain full existing server controls |
| plans.manage | Existing catalog, plan/pricing actions with current publish, validations and audits | 3 | Trusted roots unaffected; no default delegation |
| resellers.review | Review Sales Panel activation/rejection requests using existing confirmed, audited reseller flow | 3 | Trusted roots unaffected; no arbitrary role management |

**Deliberately root-only in these three phases:** managing roles/permissions and root enrollment; DNS edits, Node/Host/Profile/Remnawave mutation; global settings and tokens; financial ledger entries/reversals/withdrawals; operator-only fulfillment/provider reconciliation, and other presently sensitive operations not expressly listed above. Do not infer new access from “servers.view” or “finance.view”. Add further capabilities only by a newly approved plan.

**Role assignment policy:** existing built-in admin continues to have users.manage. For *payment-only, no user management* operators, assign a **custom restricted role** (e.g. payment-reviewer) with purchases.view + purchases.review and/or topups.view + topups.review, **without** additionally assigning built-in admin. To augment an existing admin, attach a custom review role; this adds only approved permissions, without removing current users.manage. Multi-role Spatie grants are additive. Custom-role management and assignment stay root-only, audited and confirmed. For new capabilities, use explicit idempotent permission creation/mapping (including users.view compatibility) and do not replace/reseed/drop current roles in Production.

## Phase 1 — RBAC-A1: scoped Admin access + bank/wallet review + all receipt notifications

### Work units

1. Add **users.view**, **purchases.view/review**, **topups.view/review** to code-defined permissions and current Spatie registry via an additive, rerunnable migration/seed step; keep users.manage and every existing role assignment. Built-in admin keeps current Users and support-profile access. Keep custom role editing in the existing root-only role manager; support explicit assignment of custom roles to linked, active users. Validate that review grants are paired with matching view, and make menu/Action authorization consistent.
2. Adapt Admin Bot home entry to accept a linked, active operator holding any **recognized Admin capability** or legacy users.manage, without requiring super-admin or granting users.manage implicitly. Super Admin uses the original trusted check; buyer-only/custom non-Admin permissions cannot enter. Only permitted inline/reply menu items appear; preserve current Admin vs Sales Bot separation.
3. Update the **existing** bank-purchase and wallet-topup read and decision Actions, Telegram callbacks/commands, review tokens, media access and their underlying domain authorization; distinguish view-only from approve/reject. Recheck permission at final apply, not just on opening the queue. Retain owner/receipt-state locks, genuine-bank-reference attestation, financial journal uniqueness, fulfillment readiness, no replay, and root-protected emergency operations.
4. **MANDATORY notification fan-out:** extend **ReceiptSubmitted**, **WalletTopupSubmitted** and **ReceiptReviewReminder** to (a) every still-eligible trusted Super Admin and (b) each active, Telegram-linked, reachable Admin Bot contact with the **corresponding purchase or topup review permission** (and required view permission). Match event category and recipient permissions; never send a purchase image/amount to a topup-only manager, or vice versa. The original root delivery guarantees, durable per-event/per-recipient uniqueness, authenticated cross-token photo/text fallback, retry/backoff and operator recovery remain intact. All handlers revalidate recipient authority, chat/bot identity and open receipt state at send time; loss of permission stops future and queued deliveries. No double send to a root with multiple roles.
5. For the current **15-minute scan / first aged reminder / maximum once-per-60-minutes-per-recipient**: keep the existing scheduler, lock, shared outbox and worker. Build a **recipient-filtered aggregate**: show only the outstanding purchase/topup counts the recipient is entitled to review, with only corresponding links/buttons. A root may see both. A purchase-only reviewer must never learn topup counts, and a topup-only reviewer must never learn purchase counts. If **no matching overdue/unreviewed receipt** exists for a recipient, create **no message, no delivery row and no report/log entry for that recipient**. If *no overdue receipts exist globally*, the entire scan is silent as it is now. If all qualifying work is reviewed or access revoked before dispatch, do not send stale reminders. Avoid silently widening scope of unrelated root-only events (e.g. plan-blocked/admin operational alerts).
6. Leave existing notifications feature gate semantics, channel-specific Admin/Sales tokens, original bank and wallet payment flows, customer notices and all real/provider writes unchanged. Changes should be small, ordered, independently reviewable.

### Acceptance gates (source + live)

- Guest/member/reseller and unprivileged custom role: **no Admin entry**, direct callback, URL, photo or Action access.
- Root: old menu/role/finance/server permissions and both immediate receipt notifications and reminders unchanged.
- Existing ordinary admin: retains user directory/create/status and 360 customer summary (excluding root-only wallet/financial details); does **not** gain finance by default.
- A payment-only custom-role user accesses only authorized bank and/or wallet queues, including a direct Admin Bot entry and correct Persian navigation, and can approve/reject **only** permitted type with the exact existing attestation/confirmation flow; cannot browse, decide or infer unauthorized type.
- View-only user cannot approve/reject, including forged final callbacks. Permission revocation while a confirmation or queued alert is pending blocks completion/delivery.
- Immediate purchase, immediate topup and the periodic reminder are each delivered to both roots and relevant delegated reviewers via the same outbox; no extra recipient, cross-category leak or duplicate per event. One failed recipient does not prevent the others.
- Reminder timing, rolling hour quiet period, multiple receipts aggregated, root/admin category filtering, reviewed/stale/blocked outcomes, restart/recovery and **zero-work => zero-message/row/report** preserved.
- Financial idempotency, bank reference ownership/uniqueness, cross-token photos and all old account/roles/API tests pass. Require PHP 8.4/8.5 Pest, PHPStan, Pint, disposable MySQL/Redis and a reviewed security regression matrix. **Live Telegram smoke** using one root and one delegated reviewer after separately authorized rollout; CI HTTP fakes are not proof of live delivery.

**Phase 1 exit:** No Phase 2 execution until explicit operator acceptance of delegated receipt review, all three relevant notification types, unchanged root security and no regression.

## Phase 2 — RBAC-A2: support + read-only reporting + infrastructure observability

1. Introduce support.view, reports.view, finance.view and servers.view as opt-in capabilities in existing role-management UI. Split **read DTO authorization** only where required; do not grant unrelated write Actions.
2. Customer 360: allow support.view to read already-available scoped local customer history, service snapshots and provider GET-on-selection. Keep current admin users.manage compatibility. Wallet balances and detailed financial drilldown require **finance.view** independently; deny sensitive data in fallbacks, old callbacks and error paths.
3. Reports: define an explicit least-sensitive report set for reports.view. Financial totals, business margin, wallet balance and ledger views require finance.view or remain root-only for sensitive subsections; no implicit delegation of operator-only diagnostics.
4. Infrastructure: expose only redacted nodes/hosts/health GET from existing bounded Actions for servers.view. No controls to add/delete/disable nodes, alter config profiles, DNS, secrets, or arbitrary provider identity access. Keep the root-only Server management UI untouched for root.
5. Menus, direct routes, ownership, output redaction and active-permission refresh are enforced across all entry paths.

**Acceptance:** support-only cannot view balances or mutate users/finance; finance.view alone cannot approve a receipt; reports.view alone cannot see private accounting; servers.view cannot execute any mutation even with forged callback; explicit role revocation takes effect on the next request; roots/admin existing support/management features remain. PHP 8.4/8.5 + MySQL + static analysis + focused live Admin read-only smoke.

**Phase 2 exit:** operator validates support, scoped reports, readonly provider health and redaction; explicit approval to begin Phase 3.

## Phase 3 — RBAC-A3: controlled catalog management and Sales Panel requests

1. Add plans.manage for current product/plan/tariff operations only. Preserve existing publication status, price validation, reseller discounts, trusted catalog Action guards until their approved delegation is explicitly checked, confirmation and audit. No bypass of order price snapshots, checkout, ledger, remnawave write flags or plan/Squad integrity. No bulk reprice.
2. Add resellers.review for the existing Sales Panel application queue/approve/reject only. Preserve verification, explicit confirmation, ledger and protected-role guard; this capability cannot grant super-admin, arbitrary roles, or edit unrelated user access. Reuse existing Application review Action; no parallel workflow.
3. Reuse Phase 1 menu/permission approach for dedicated Admin Bot sections and callbacks. A finance reviewer should not inherit plans.manage; a plan manager should not inherit purchase approval; neither receives root-only infrastructure alerts merely by being an Admin Bot user.

**Acceptance:** delegated catalog manager can change only authorized existing catalog fields with a confirmed auditable action; other users and forged calls are refused; existing published plan prices/active subscriptions unaffected except explicitly approved changes. Delegated Sales Panel reviewer processes only valid pending requests; root enrollment, arbitrary role edits, provider mutations, banking approvals and account ledger remain denied. All original root flows remain intact. PHP 8.4/8.5 + MySQL + Pint/PHPStan + live permission-bound smoke after approval.

**Phase 3 exit:** operator accepts both delegated workflows, denial matrix, no regressions and any remaining root-only boundaries.

## Rollout / risk and decision log

- **Approval:** This is an authorized plan and documentation update only; phase scope is proposed, not authorized for implementation or production mutation by this PR. Run phases in order, one scoped PR at a time, followed by implementation/tests/review/merge and explicit live acceptance.
- **Migrations:** Only when expressly approved in a phase; additive, reproducible, non-destructive role/permission backfill. No migrate:fresh, destructive cleanup, blanket grant to admin/member, unreviewed DB seeding or privileged account changes.
- **Boundary risks:** (i) users.manage currently implies admin entry and 360 data reads, (ii) root-only finance and infrastructure Actions must not be loosened wholesale, (iii) reminder currently aggregates both finance categories and is root-only, (iv) immediate and scheduled outbox fan-out must be deduplicated and authorization rechecked, (v) token/approval idempotency, bank attestation and retry visibility.
- **Recovery:** Per-phase revert of presentation/policy/source without deletion of existing receipts, roles or delivery evidence. Never replay already-sent notification rows or financial/provider writes. Investigate dead/unavailable intents via existing admin tooling; a successful Telegram API response is not proof of client rendering.
- **Historical tests do not verify future scope.** Stage-by-stage operator test matrix and PR-specific source evidence are mandatory. Do not alter production role assignments, tokens, ENV or live bank/provider operations in a documentation PR.
