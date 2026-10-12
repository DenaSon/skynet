# F11-B1.1 — Dynamic Sales Plan Catalog

**Status:** Internal application foundation only; NOT purchasable until B1.2 pricing and B1.3 provider binding.

## Schema and ownership

Sales owns `sales_plans`, `sales_plan_confirmations`, `sales_plan_audits`. The existing `sales_products` / `sales_product_rates`, F09 quotes and checkout are deliberately unchanged. Nullable `sales_orders.plan_id` is reserved for future price-snapshot-backed orders, with RESTRICT on plan deletion.

A plan is either a fixed `preset` or a reseller-only `builder`; kinds are `volume`, `time` (unlimited volume) and `hybrid`. Fixed presets have exact duration and, for finite volume plans, exact GB; a builder has no fixed duration/volume but defines min/max. Time-based plans store NULL for traffic, never an implicit provider sentinel. All plan durations are bounded 1–365 days and finite traffic between 1 and 2048 GB. Status transitions are `draft`, `active`, `inactive`, `archived`; each plan has audience `public`, `reseller` or `both`, featured and display order.

## Secure Application operations

`ManagePlans` provides `create`, `update`, `all`, `find`, `requestChange` and `confirmChange`. Every operation freshly checks Identity's allowlisted active and Telegram-linked Super Admin; ordinary admin/reseller roles have no administrative authority. Create starts in draft; edit requires the current monotonic plan revision. Activate/deactivate/archive/delete require an actor-bound, random single-use 3-minute confirmation stored hashed in SQL, and current DB state/revision rechecks in one transaction. Sold plans cannot be hard deleted (Sales orders restrictive FK), archived plans cannot be edited/reactivated, and all mutations retain a root/plan/revision audit entry.

`ListVisiblePlans` checks buyer's current active account and role: normal buyers see active public/both, resellers see active public/reseller/both. Parent products must be active. This is a read-only catalog preview, NOT a permitted priced checkout. A fabricated plan ID does not grant access.

## Release boundaries and handoff

- B1.2: one plan pricing/quote engine, role-aware pricing, immutable order snapshot and live revalidation.
- B1.3: approved plan-to-Internal-Squad mapping, bytes/expiry conversion; only mock provider inspection.
- B1.4–B1.6: Super Admin Telegram management, public three-preset UX, reseller builder.
- No seeded speculative prices, provider UUIDs, direct financial writes, live Remnawave provisioning or Telegram checkout edits in B1.1. Existing payment/provider feature flags remain OFF. PHP 8.4/8.5 CI is required. Real bank, BotFather and provider E2E remain out of scope.
