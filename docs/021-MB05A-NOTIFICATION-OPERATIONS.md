# MB-05A — Durable Telegram delivery / recovery

**Implementation checkpoint:** PR #57 (code/offline only); no live two-bot send or BotFather webhook change is claimed. Sales Bot remains OFF by default.

## Boundaries and correctness

- A receipt submission or bank decision commits its business transition and notification intents in **the same MySQL transaction** once the additive migration is present; duplicate photo/decision callbacks do not insert new events. Existing Billing journal, unique bank reference and fulfillment outbox are unchanged.
- Each intent is durable in `telegram_notification_deliveries` with a unique recipient/event key, one target token (`admin` or `sales`), source claim/order identifiers, attempts, lease, next-attempt time and terminal status. This is **not** a second payment outbox or a general event bus.
- Initially `TELEGRAM_NOTIFICATIONS_ENABLED=false`. The **existing Admin Bot best-effort receipt and payment-result messages remain active** for backwards compatibility; a notification envelope is persisted with status `suppressed`, not queued for replay. When enabled, new transitions use the durable worker and the legacy direct alerts are skipped. This avoids silent notification loss on deploy or an alert flood when the worker is first enabled. The additive migration adds `sales_bank_payment_claims.source_bot_key` defaulting to `admin` for historical file IDs; code tolerates migration running after PHP deployment. Rolling back after intents have been recorded is deliberately blocked to protect evidence.
- A newly submitted receipt targets each **currently linked, active, allowlisted and trusted root** on the original Admin Bot. Just before sending, role and active status are rechecked. The original Admin bot continues to serve legacy nonadmin members.
- A committed bank review targets the buyer's **positively started Sales Bot contact only when Sales is configured and enabled**; otherwise the existing linked Admin/legacy bot is the delivery bridge. No Sales Bot public handlers, provider writes or new checkout have been enabled.
- Notification transport has **at-least-once attempts**, not exactly-once Telegram messages. A Telegram timeout after acceptance may yield a duplicate message on retry; repeated approvals cannot settle twice. No alert flood from submission replays.
- Photos: same bot token may send its own file ID; across tokens the sender obtains the file from official Telegram API, validates a bounded (5 MiB) JPEG/PNG and uploads the bytes as a new file with the destination token. No cross-token file-ID assumption, untrusted destination URL or permanent plaintext media copy. If photo transport cannot complete, an ID-only Admin alert directs the root to `/payments`; **never claim the image was delivered**. The existing review queue, rather than the alert, remains authoritative; bank screenshots alone do not prove payment.
- `ProvisioningNeedsReview` and `SubscriptionStateChanged` are **documented event contracts only**, not wired to provider lifecycle: there is not yet an approved verified owner/order mapping and real Remnawave staging transitions. Avoid fabricated recipients or lifecycle states; these are excluded from the MB-05A payment notification adapter and require their own future approved owner-bound source transition.

## Operator setup (after database migration, queue and two non-public bot tests)

1. Verify the migration `2026_10_15_000003_create_telegram_notification_deliveries` is applied and `telegram_bot_contacts` exists. Keep `TELEGRAM_SALES_ENABLED=false` until an independent Sales cutover is approved.
2. Run the existing Laravel **database queue worker** on the deployment (e.g. `php artisan queue:work database --queue=default` under the usual process supervisor) and the existing Scheduler (`php artisan schedule:run` every minute). Do not run polling against an active webhook.
3. After fake-endpoint tests and a real **non-public** Admin Telegram smoke, set `TELEGRAM_NOTIFICATIONS_ENABLED=true` in persistent deployment env; redeploy/rebuild config cache via the standard pipeline. Events created earlier in the disabled compatibility mode remain `suppressed` and **must not be replayed**. New events use the durable delivery worker. No tokens or bank details in Git, tickets, logs or test screenshots.
4. `php artisan telegram:notifications:dispatch` enqueues at most 50 due pending/expired-lease events manually; Scheduler runs it every minute for recovery. This is a fallback; immediate enqueue happens after the originating DB transaction commits. `available_at` provides exponential backoff, capped at 5 attempts. Statuses: `suppressed` (legacy direct-send mode; not retryable), `pending`, `processing`, `sent`, `dead`, `unavailable`.
5. To inspect counts **without PII**, run:
   `php artisan tinker --execute='dump(DB::table("telegram_notification_deliveries")->select("state", DB::raw("count(*) as count"))->groupBy("state")->get());'`
6. Fix the underlying bot/contact/queue issue first. Operator-only manual resubmission for a `dead` or `unavailable` delivery: `php artisan telegram:notifications:dispatch --retry-id=123`. This **retries the notification only**; it cannot create another bank settlement. Do not retry a `sent` row, and be aware of message duplication when prior Telegram acceptance was ambiguous.
7. To pause outbound traffic, change `TELEGRAM_NOTIFICATIONS_ENABLED=false` in the persistent deployment environment. The outbox remains intact and resumes on re-enable; do **not** truncate it.

## Verification still required before live acceptance

- Two distinct non-public bot tokens with safe Webhooks and independently started private chats; authenticate destination chat and webhook; confirm Admin receives a submitted receipt and buyer receives a reviewed decision from the correct bot.
- Observe cross-token photo reupload; blocked, revoked, stale, delayed and oversized media conditions; check Admin can always reach `/payments` and never settle based on the picture alone.
- Measure queue latency under healthy production-equivalent infrastructure (target p95 ≤ 10 seconds, not a promise); no staging measurements have been performed.
- Verify no duplicate journal/settlement and no credentials, bank identifiers or media bytes in logs. **No real financial approval** in test.

Do not conflate CI fake Telegram responses with a real two-token BotFather delivery.
