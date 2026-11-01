# DenaGate Live — approved review follow-up (2026-11-01)

Two independently reviewable phases authorized by the operator after the master d911baff review.

## Phase 1 — service navigation and search

- Clear status confirmation token/mode, cached approval, notices and errors before changing the selected service, tab, page or search. Invalidate the old service key before clearing its ID.
- Reuse `ManageResellerServiceLabels::search` for customer-name search; no new query layer, table, dependency or Provider request. Six results per page; shared owner-checked page resolution for listing and selection. Preserve the applied query and cursor across detail/back; changed/cleared search resets the cursor. Literal SQL wildcard characters, invalid input, foreign owners and revoked roles remain guarded.
- Tests extend Live service reads and status controls. Automated HTTP/Queue fakes do not establish production Provider or Telegram acceptance.

## Phase 2 — purchase and recovery

- Selecting a published preset automatically prepares the read-only quote; custom builders still require quantity entry/preview. Order creation and wallet debit retain separate explicit confirmations and current price/ownership checks.
- Add an explicit read-only order refresh and service-list navigation after payment, without background polling, duplicate payment or Provider writes.
- Persian login/access/session-expiration errors and a safe route back to the Sales bot. Preserve HMAC freshness, CSRF, HTTP error status, feature/role gates and existing throttle.

## Acceptance and rollback

Each phase has its own PR and CI. Require PHP tests, PHPStan, Pint and Vite build; MySQL/Redis checks follow the existing workflow. Physical Telegram iOS/Android/Desktop QA remains separate: search beyond page one, detail/back, preset quote, pending/paid order refresh, expired session and login-denial recovery. No real wallet debit or Provider write is authorized for testing. Revert each scoped PR independently (phase 2 depends on phase 1); no schema/config/ENV change. Rate-limit tuning, contrast redesign, account-switch policy and other features remain outside this authorization.
