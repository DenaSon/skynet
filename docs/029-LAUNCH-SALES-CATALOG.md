# DenaGate launch catalog — operator-approved prices

**2026-10-25 pricing revision:** For newly created launch plans only, mark up the original approved public prices by 25%, then round **up** to the next multiple of 5,000 tomans (including exact multiples), and increase the Sales Panel discount from 25% to 35% (a 10-percentage-point increase). Use integer arithmetic, never floating-point money.

`LaunchSalesCatalogSeeder` is an explicit, standalone setup for the four approved fixed plans. It is not called by `DatabaseSeeder`, migrations, deployment hooks, the Scheduler or a webhook. No test identities, credentials, orders, money, legacy duration rates or provider records are seeded.

| Plan | Slug | Total GB | Days | Original toman | New public toman | Sales Panel toman (35% off) |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| پایه | `basic-10-15` | 10 | 15 | 50,000 | 65,000 | 42,250 |
| اقتصادی | `economy-20-30` | 20 | 30 | 100,000 | 125,000 | 81,250 |
| استاندارد | `standard-50-30` | 50 | 30 | 240,000 | 300,000 | 195,000 |
| سه‌ماهه | `quarterly-100-90` | 100 | 90 | 520,000 | 650,000 | 422,500 |

The 100 GB quota covers the **entire 90 days**, not each month. All plans use `hybrid` / `preset` / `both`, exact fixed GB/day bounds, and display order shown above. Public prices are converted to integer rials (toman × 10). Each newly published tariff has a **35%** reseller discount and its discounted price as the minimum selling floor. The existing quote engine applies that single discount only to active reseller accounts; it does not stack the legacy product discount. No reseller builder or formula tariff is introduced.

## Run after deployment

1. Apply the existing migrations and enroll the real Super Admins using the existing bootstrap/link/promote workflow. The seeder requires an active linked allowlisted root and at least two distinct configured `SUPER_ADMIN_TELEGRAM_IDS`; it never creates or promotes users. It selects the lowest-ID **eligible** root as the audit actor and prints that internal ID.
2. Run once in the application container (`/var/www`):

   ```bash
   php artisan db:seed --class=LaunchSalesCatalogSeeder --force
   ```

3. In the Admin Bot, open **محصولات و پلن‌ها → مدیریت پلن‌ها**. Verify all four names, quotas, durations and published tariffs. For each newly created plan, use **اتصال سرویس** to select and confirm the real intended Internal Squad. The seeder makes **no Remnawave call**, guesses no UUID and writes no verification evidence. Existing checkout readiness and provider/payment switches still apply. A published catalog by itself is not proof that purchases can be fulfilled.
4. Verify public and Sales Panel prices in the real Sales Bot, then perform the already authorized controlled purchase/renewal acceptance checks. CI fixtures do not establish live Telegram rendering, deployed seed execution, Squad membership, provider connectivity or settlement acceptance.

## Rerun and failure behavior

The initial setup is one SQL transaction and reuses `ManageBaseCatalog`, `ManagePlans` and `ManagePlanPricing`, including their root checks, audits and activation/publication confirmations. It creates and activates the parent `denagate` / `اشتراک DenaGate` (1–100 GB) only when absent. An existing parent is preserved, including its name, bounds and disabled status.

Plan slugs are stable identities. A same-product existing launch plan is reported as preserved and skipped **as a whole**: no edits to its price, discount, status, revision, timestamps, missing tariff or Squad binding, even if it differs from the original launch specification. Review skipped plans manually; rerunning this seeder is not a repricing/repair command. Only missing plans are added. A conflicting slug under another product, an inactive parent preventing a new activation, or any other failure rolls back all new catalog rows and audit changes from that invocation. Previously committed data remain intact.

Source rollback is a PR revert and does not delete a catalog that an operator already seeded. Manage existing plans through the normal Admin actions; sold-plan/order and financial evidence protections remain unchanged.

## Existing production plans: no automatic repricing

Changing this seeder or rerunning `php artisan db:seed --class=LaunchSalesCatalogSeeder --force` **will not change existing plans**. This is deliberate: existing plans are always preserved, including their manual price/discount edits, active/inactive state, revisions, order snapshots, and Squad bindings. Merge/deploy also does not run this opt-in seeder.

For the already-created four plans, an authorized Super Admin must change each existing fixed tariff via **محصولات و پلن‌ها → مدیریت پلن‌ها → [پلن] → 💵 تعرفه ثابت**, then register and **✅ انتشار تعرفه** with final confirmation. The Admin Bot fast-entry rows are `public toman | minimum total toman | Sales Panel discount percent`:

| Plan | Copyable Admin tariff entry |
| --- | --- |
| پایه | `65000 | 42250 | 35` |
| اقتصادی | `125000 | 81250 | 35` |
| استاندارد | `300000 | 195000 | 35` |
| سه‌ماهه | `650000 | 422500 | 35` |

Tariff configuration deliberately transitions the tariff to `draft` until republished. Verify all member and reseller prices in the live Sales Bot afterward. Pre-existing orders retain immutable historical price snapshots; no existing payment, wallet balance or settled sale should be rewritten. Do not batch-rewrite production rows or run a provider operation for this price update.
