# DenaGate — Laravel Health foundation (no Telegram UI)

## Scope

- Install `spatie/laravel-health` 1.40.x on Laravel 13 / PHP 8.4.
- Register checks for database connectivity, Redis when actively used by cache/queue, default queue worker heartbeat, Laravel Scheduler heartbeat, and disk usage.
- Store check history in Spatie's `health_check_result_history_items` table (7 days).
- Provide CLI inspection without registering any new health HTTP endpoint.
- No Telegram menu, notification, alert transport, financial operation, Remnawave API call, or new authorization surface in this phase.

## Rollout / opt-in

The `DENAGATE_HEALTH_ENABLED` setting defaults to **false**, so deployment alone does not schedule extra jobs or write health history. Existing Scheduler tasks are untouched.

1. Review PR checks on PHP 8.4, MySQL migration and rollback, PHPStan, Pint, and the full test suite.
2. Before production changes, verify a backup, the committed Composer lock, working queue worker, Scheduler, and effective MySQL/Redis configuration. Apply the additive health migration via the established deploy process.
3. Verify package commands with `php artisan health:list --fresh --no-notification --do-not-store-results`. A queue/schedule check may report a warning until its first heartbeat is recorded.
4. Set `DENAGATE_HEALTH_ENABLED=true` in the private production environment, rebuild config cache according to the normal deployment process, and keep the existing minute Scheduler running.
5. Confirm `php artisan health:list` returns fresh results after at least two Scheduler cycles and verify `health_check_result_history_items` rows. Health problems should **not** automatically change purchase/fulfillment state.
6. Rollback: turn `DENAGATE_HEALTH_ENABLED=false` and rebuild config cache. Keep historical health rows; do not drop tables during routine rollback.

When enabled, the Scheduler dispatches one lightweight health job to the default queue per minute, records its own heartbeat, and runs `health:check --no-notification`. A daily targeted `model:prune` retains seven days of health history. There is no new queue or Supervisor process.

## Boundaries and limitations

- Redis is checked only when the active Laravel cache or queue driver is Redis; local SQLite/array/sync tests do not require a Redis server.
- A successful queue check proves that its probe job was processed, not that every business job is healthy.
- The built-in Scheduler check uses a Laravel cache heartbeat. If the entire Scheduler/application stops, no in-app alert can be guaranteed. External monitoring is a later, separate decision.
- If the database is unavailable, DB-backed health results cannot be written. Failure reports must not be misrepresented as successful or as current.
- Health reports may contain operational information. Do not expose via a public URL. Next phase may read stored results inside the existing trusted-Super-Admin Admin Bot authorization boundary; no new public API.
- Existing DenaGate notification outbox is the only permitted Telegram delivery path. Spatie's native mail/Slack notifications are explicitly disabled.
- `spatie/laravel-settings` PR #285 is independent and not assumed merged; any later Composer lock changes must be reconciled by Composer before merge.

## References

- https://spatie.be/docs/laravel-health/v1/installation-setup
- https://spatie.be/docs/laravel-health/v1/available-checks/queue
- https://spatie.be/docs/laravel-health/v1/available-checks/schedule
- https://spatie.be/docs/laravel-health/v1/storing-results/in-the-database
