# F11-A2 — Manual Bank Receipt Review (staging only)

**Status:** new implementation, enabled only with `SALES_MANUAL_BANK_REVIEW_ENABLED=true` and real bank review procedures. Default false. No bank API or provider account creation is implemented, and a Telegram screenshot is NEVER treated as proof of payment by itself.

## Customer / Super Admin

- A buyer's pending Sales order can show `🧾 ارسال رسید پرداخت`. The buyer selects the order and sends ONE ordinary Telegram photo in their linked private chat within 10 minutes. The file ID is encrypted at rest, and the image fingerprint/message key are unique. No public URL, filesystem dump, log, or caption-derived financial evidence.
- The system saves the claim under the immutable Sales order amount, replies `در انتظار بررسی`, and attempts to forward the photo by Telegram file ID to each ACTIVE, linked, allowlisted Super Admin. Telegram send failures do not lose the durable request. Recovery command: `/payments`, then `🖼️ مشاهده رسید`.
- Super Admin sees the photo with `✅ بررسی تأیید` and `❌ رد رسید`. Reject needs a second single-use confirmation. Approval first requires the operator to independently examine a real bank statement and enter `/bankconfirm CLAIM_ID BANK_REFERENCE`; the bot presents the exact expected order amount and reference for a second 3-minute, actor-bound, one-time final confirmation.
- Approve atomically claims the globally unique bank reference, creates an immutable `sales_bank_settlements` and **balanced** `cash_clearing` debit / `order_liability` credit, changes only the owned `pending_payment` order to `paid`, and inserts exactly one pending `sales_fulfillment_outbox` event. The F11-B dispatcher remains absent. Reject records a review audit and keeps the order unpaid; a new (different) photo can be submitted.
- The operator decision is recorded with user ID and timestamp. Customer status is sent by Telegram on a best-effort basis; a failed Telegram notification does not alter a committed accounting transaction. Old buttons or duplicated/expired confirmations cannot settle twice.

## Failure boundaries

- Neither a photo, forwarded message, payer-entered ID, nor a Telegram callback proves bank credit. Human verified bank reference is required before approval. An actual bank API verification is a separate future feature.
- Only private chat / identity-linked actors have access. Reviewing requires fresh Super Admin ROLE plus the separate >=2-ID allowlist on **every** action, not just UI visibility.
- `billing_bank_reference_claims` enforces new F08/F11 reference uniqueness; F11 additionally checks preexisting `billing_verified_receipts` so legacy wallet top-up references cannot be replayed as sales income.
- Access to Telegram file IDs is restricted to authorized Super Admins. Telegram media remains hosted by Telegram; this does not download, archive or guarantee permanent retention of bank screenshots. Production retention/deletion, bank account reconciliation SOP, crash/cancellation and database isolation/concurrency must be reviewed.
- No Remnawave outbound POST or live auto-activation. Offline fake transport and SQLite tests are not a substitute for real bank statement, MySQL/PostgreSQL concurrency, real Telegram private chats, or isolated staging E2E.
