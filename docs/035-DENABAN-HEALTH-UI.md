# Denaban — Health UI (HEALTH-UI-01)

## Delivered surface

A single new **🩺 سلامت سیستم** button on the existing Denaban screen.
Callbacks:
- `denaban:health`: RTL Rich Message with an HTML fallback.
- `denaban:health:simple`: native HTML view.
- `denaban:show`: existing Denaban home.

Only the private, current, trusted Super Admin identity can enter. Both the
Telegram presenter and the Application read action re-check authorization;
group chats, revoked accounts, and delegated admins are denied.

## Data and freshness

- Read at most twelve records from the **latest persisted Spatie batch** only.
- Display safe Persian service labels and whitelisted status categories.
- Never display raw metadata, notification messages, DB connection strings,
  private addresses, file paths, credentials, or stack traces.
- No record yet: **waiting**. Feature off: **disabled**.
- More than 3 minutes old or future-dated: **stale**.
- Incomplete (< 4 checks), unexpected status, or too many records: **unknown**.
- Current batch with any failure/crash: **failed**; warnings: **warning**.
- Only a fresh, complete and fully OK batch can show **healthy**.
- Missing table / DB failure: **unavailable**, never `0` or a green check.

The refresh button **re-reads stored results** and does not run a new health
check inside the Telegram Webhook. Queue jobs and scheduled monitoring stay
under the existing `DENAGATE_HEALTH_ENABLED` rollout switch from PR #287.

## Non-goals

- No email, Telegram health alerts, external heartbeat, public HTTP endpoint,
  new bot role/permission, or provider/financial writes.
- No changes to the existing Denaban operational, wallet, sales, or node data.
- No change to the Spatie `health:check` invocation cadence.

## Acceptance

Run PHP 8.4 tests, Larastan, Pint and the established MySQL migration checks.
Confirm the button is absent for ordinary admins, stale/revoked callbacks
fail closed, Rich and native views show safe information, and results do not
trigger Remnawave GETs or any financial/queue side effects. For live Telegram,
test a private trusted root on an RTL-capable client and a fallback client.

The production feature remains **disabled by default** until a separate
operator-approved activation after confirming migrations, Scheduler, Queue
worker, and fresh persisted health batches.
