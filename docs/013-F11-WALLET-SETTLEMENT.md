# F11-A1 — Offline Reseller Wallet Settlement (NOT live-enabled)

**Merged:** [PR #30](https://github.com/DenaSon/skynet/pull/30), SHA `76ea9e1b78db0f37281d5cfb224a4a98f20bc2cf`; [master CI #38226617474](https://github.com/DenaSon/skynet/actions/runs/38226617474) PHP 8.4/8.5 green. No real E2E.
**Safety:** `SALES_WALLET_SETTLEMENT_ENABLED=false` by default. No Telegram/HTTP/Artisan handler invokes settlement. No outbox consumer, provider writes, or real money integration exists in this increment. Offline Pest + HTTP fakes verify the implementation; bank, provider and BotFather E2E remain unverified.

## Internal flow — single database transaction

1. `SettleWalletOrder` resolves a *fresh*, trusted Telegram/session buyer using `AuthorizeSubscriptionViewer`; only an active `reseller` can settle a Sales order whose buyer is exactly that account.
2. Lock the `pending_payment` Sales order. A previously `paid` order may replay only if its matching wallet journal, settlement record and unique outbox event are intact; paid status alone is NOT proof.
3. Lock the buyer's `billing_wallets` row. The ledger-derived spendable balance is `wallet_liability` credits minus debits. Never use an editable balance, message screenshot or buyer-supplied bank proof as payment truth.
4. Under the same transaction, post the unique `billing_journals(kind=purchase, key=purchase:order:<id>)` journal with two balanced integer-rial postings: debit `wallet_liability`, credit `order_liability` (prepaid, unfulfilled service obligation).
5. Persist unique `sales_order_settlements` (order/journal/amount), conditionally change the order to `paid`, and persist `sales_fulfillment_outbox` (`event_key=sales-order:<id>:create`, `state=pending`). Failure of any write rolls back the journal, order, settlement and outbox together.
6. **Stop.** There is no job/worker to consume this outbox and no Remnawave call in F11-A1. A paid but unprovisioned order is correctly shown as `provisioning` by F10-E.

## Acceptance and operational limits

- Default flag off, no route, no remote call, no unauthorized/mismatched actor and no ordinary-user wallet purchase.
- Repeated customer clicks with the same verified viewer/order cannot double-debit or duplicate outbox entries; a missing/corrupt settlement fails closed.
- Insufficient credit, role revocation, disabled/unlinked user, foreign order, and simulated outbox failure leave wallet/order states unmodified.
- Database transaction locks use Laravel `lockForUpdate`; SQLite feature tests cannot prove real multi-process contention. Validate with MySQL/PostgreSQL before enabling live credit spending.
- Previously approved wallet top-ups originate in F08 but **have no automatic independent bank verification**. Production top-up procedures, settlement/refund reversals, bank-reference collision policy and accounting review remain open gates.

## Next F11 increments

- **F11-A2:** direct bank transfer settlement *only* after independent evidence verification, globally unique approved receipt ownership, trusted operator confirmation and audit. Do not treat a user-supplied reference as payment.
- **F11-B:** reviewed product→Internal Squad binding, UTC expiration/byte-limit rules, outbox claim/lock/reconciliation and one safe provider create attempt; ambiguous writes must NOT be automatically retried.
- **F11-C/D:** privileged audited operations, staging with real provider/bank/BotFather, then customer activation UX. Do not connect Telegram to this new wallet action until reviewed integration and live gates are satisfied.

## F11-B0 financial boundary clarification

F11-A1 wallet purchase replay is attested by `Billing/Application/VerifyWalletOrderDebit`. All deposits and purchases share the Billing ledger (`billing_journals` + `billing_postings`) with integer-rial balanced entries. Direct bank entries use `wallet_id=NULL`; they never increase any customer's wallet balance. Sales does not query Billing tables. See [ADR 0008](adr/0008-unified-billing-ledger.md).
