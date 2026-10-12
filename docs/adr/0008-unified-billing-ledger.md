# ADR 0008 — Unified Billing Journal and Cross-Module Settlement

**Date:** 2026-10-12
**Status:** Accepted in F11-B0 (pre-production schema)

## Context

SkyNetwork has no production database or real customer data. F11-A2 introduced
a separate `sales_bank_postings` table and Sales queries directly into
`billing_*` tables, despite Billing being the owner of monetary truth.

## Decision

- **Identity:** resolves trusted actors and the allowlisted, active Super Admin.
- **Sales:** owns quotes, immutable orders, Telegram receipt claims, manual
  approval/rejection audits, settlement snapshots, `paid` state transitions,
  and the unique, pending fulfillment outbox.
- **Billing:** exclusively owns verified bank reference claims, F08 top-up
  accounting, reseller prepaid wallet debits, direct bank sale journals and
  the **one shared double-entry ledger**, `billing_journals` and
  `billing_postings`. Sales must access it solely through Billing Application
  Actions, never DB queries or imported Infrastructure models.
- **One schema, not two:** make `billing_journals.wallet_id` nullable, with
  non-wallet direct bank journals marked `kind=direct_bank_sale`,
  `wallet_id=NULL` and a unique `direct-bank:order:{id}` key. Record a debit
  to `cash_clearing` and credit to `order_liability`; the same ledger
  records F08 top-ups and F11-A1 wallet purchases. Queries for a user's wallet
  balance still filter wallet ID and `wallet_liability` postings.
- **No duplicate journal:** F11-A2 `sales_bank_settlements` retains its
  immutable Sales order, verified reference and reviewer information, and
  adds a unique foreign key `billing_journal_id`. There is **no**
  `sales_bank_postings` or `billing_direct_bank_postings` table.
- **Global reference claim:** `billing_bank_reference_claims` is created
  in the original Billing migration with unique bank reference AND unique
  (source_type, source_id). Both F08 and F11 enter through
  `ClaimVerifiedBankReference`. Keep F08 verified receipt rows for its
  top-up identity and conservative collision checking.
- **Atomic transaction:** the operator first *independently* confirms the
  real bank transfer. Sales locks the order/claim and invokes Billing's
  `RecordDirectBankPayment` inside the same SQL transaction; Billing
  validates root permission and feature gate, claims the unique reference and
  records both balanced postings. Sales then inserts the journal-linked
  settlement, marks the order paid, records an outbox intent, and audits the
  decision. Failure in any part rolls everything back.
- **Wallet replay:** the Sales `SettleWalletOrder` delegates Billing journal
  + exact two-posting attestation to `VerifyWalletOrderDebit`. Sales never
  joins `billing_wallets`, `billing_journals` or `billing_postings`.
- Enforce these boundaries with architecture tests, plus tests for duplicate
  bank references, rolled-back outbox insert, accounting balance and unknown
  actors. Telegram is a thin transport and never owns financial SQL.

## Migration policy and release gate

This is **pre-production only**: there are no persisted/live customer records
to migrate. Alter the original Billing and Sales create-table migrations
directly and use a **fresh disposable development/test database**. Do not
pretend this change is a safe live in-place upgrade; before first production
launch, freeze migrations and define an additive upgrade policy.

All money/provider flags stay OFF by default. No outbox consumer, live bank
verification or Remnawave writes are activated in F11-B0. MySQL/Postgres
concurrency and isolated Telegram/bank/provider E2E remain deployment gates.

ADR 0006 remains a historical statement of initial domain separation;
this ADR is the authoritative financial ledger implementation boundary.
