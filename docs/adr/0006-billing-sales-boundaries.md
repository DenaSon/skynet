# ADR 0006 — Sales, Wallet, and Provisioning Boundaries

**Date:** 2026-10-11
**Status:** Accepted; F08 implements only prepaid wallet and verified manual topup, Sales/Provisioning remain deferred.

## Decision
- Identity determines who a user is. Referral only tracks first signup attribution (not financial rewards).
- Sales will own products, immutable quoted prices, customers (including external reseller clients), orders and their state transitions. Direct and reseller channels use one order model.
- Billing will own non-withdrawable reseller prepaid credit with a compact auditable ledger; do not use a mutable `users.balance` as source of truth. Top-up credit requires independent verification, duplicate payment references must not credit twice, and amounts will use integer rials.
- Provisioning translates a paid/authorized order into a service on a configured provider. First provider may be Marzban; SSH quotas, time accounting and Core/Edge topology are not F07 decisions.
- A reseller purchases using wallet credit for their own external customers; role `reseller` determines access, while customer ownership and price scopes will live in Sales. A referral is not a reseller.
- Reservation/capture/release and reconciliation semantics should be developed only when order and provisioning requirements are concrete and E2E tested.

## F08 implemented slice
A verified manual topup has a globally unique bank reference and a unique `topup:<id>` journal with balanced cash-clearing debit / wallet-liability credit entries in integer rials. Wallet balance is derived from postings. A root-bounded explicit review and independent bank-side verification are required before cash is recorded. No purchase-side reservation/capture/release yet.

## Non-goals
No generic accounting platform, invoice engine, tax rules, wallet transfers/withdrawals, commissions, exchange rates, cashback, or provisioning adapters in F07.

## Reason
Keep F07 identity-onboarding isolated from money and network operations, avoiding speculative abstractions. Later Billing/Sales increments will require independent financial/concurrency tests and human review before handling real funds.
