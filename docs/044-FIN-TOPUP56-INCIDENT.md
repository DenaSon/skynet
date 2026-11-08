# FIN-INC-56 — one-off wallet top-up amount reconciliation (2026-11-08)

## Incident and authorization boundary

- Customer user ID **494**, wallet ID **2393**, top-up ID **56**.
- Original recorded top-up: **9,000,000 tomans** (90,000,000 integer rials).
- Operator-reported actual bank transfer: **900,000 tomans** (9,000,000 rials). A photographed receipt is **not** bank verification; independently inspect bank transactions before applying.
- Incorrect surplus: **8,100,000 tomans** (81,000,000 rials).
- At investigation time, two wallet purchases of 178,750 tomans each posted **after** the top-up. Current verified snapshot: **8,642,500 tomans**; target after correction: **542,500 tomans**, if no other wallet movement occurs.

The existing ReverseUnusedWalletTopup must **not** be bypassed, invoked, or weakened. It intentionally blocks a deposit after spending and would reverse the entire amount.

## Source-only change

- One **operator-invoked, dry-run-first** Artisan command: finance:correct-topup-56. It is not scheduled and cannot be accessed through either Telegram Bot or web API.
- Existing Billing journals/postings are the only wallet balance source; the change creates a new balanced correction journal with unique key topup_amount_correction:56, debiting wallet liability and crediting cash clearing by the invalid excess only.
- An additive, source-unique audit row records verified amount, difference, trusted root operator ID, reason, correction journal and timestamp. Source top-up, verified receipt, global bank claim, order/payment records and Provider entities are **not modified**.
- ReadAdminCashReceipts counts the corrected **net** bank amount as one incoming top-up, retaining reported source counts; when migration has not run, it preserves old report behavior. Admin wallet history gives the adjustment journal a precise label.
- Runtime requires active trusted Super Admin, eligible buyer, original source proof and balanced journal, no previous full/partial correction, matching wallet and top-up and funds sufficient for the adjustment. Execution requires the **unchanged expected balance** observed at incident review: 8,642,500 tomans. SQL row locks plus unique source/journal constraints make replays safe.
- The source audit and journal are permanent. The migration rollback refuses to drop nonempty audit data.

## Production checklist — separated from PR/CI

1. **Before any write**: independently confirm the bank transfer amount is exactly 900,000 tomans and keep the bank evidence privately. Confirm no later wallet movement and take a database backup.
2. Confirm deployed commit and migration. This is not an automatic deploy action. Choose a currently authorized Super Admin local user ID (do not substitute a Telegram ID).
3. Preview: php artisan finance:correct-topup-56 --operator-id=1 --bank-amount-toman=900000 (operator ID 1 is only an example; use the authentic authorized root).
4. Only after independent operator approval of the production write and matching **all** preview values: php artisan finance:correct-topup-56 --operator-id=1 --bank-amount-toman=900000 --apply --confirm=CORRECT-56-8100000 --reason="Bank verified 900000 toman; customer entered 9000000".
5. If preview fails, **STOP**. Do not force the balance, amend a journal by SQL, retry with different figures or register a full reversal. Investigate new customer activity or missing evidence separately.
6. Read-only post-write verification: one correction audit topup_id=56, one journal idempotency_key=topup_amount_correction:56, two opposite postings of 81,000,000 rials, customer wallet now 5,425,000 rials, original top-up 90,000,000 rials and still approved, and corrected cash report. Do not infer bank settlement from app logs.
7. This action never reverses purchases, contacts customers, changes Provider subscriptions or issues a real refund. Those are separate decisions.

## CI and release

Required: focused Billing regression, full PHP 8.4/8.5 CI, PHPStan, Pint and MySQL migration checks. Offline tests must **not** be represented as successful live execution. Master auto-deploys, but this command is inert unless explicitly executed by an authorized operator with --apply.
