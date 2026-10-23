<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManageBaseCatalog;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit launch setup only; never called by DatabaseSeeder or a migration. */
final class LaunchSalesCatalogSeeder extends Seeder
{
    /** @var list<array{slug:string,name:string,gb:int,days:int,toman:int}> */
    private const PLANS = [
        ['slug' => 'basic-10-15', 'name' => 'پایه', 'gb' => 10, 'days' => 15, 'toman' => 50_000],
        ['slug' => 'economy-20-30', 'name' => 'اقتصادی', 'gb' => 20, 'days' => 30, 'toman' => 100_000],
        ['slug' => 'standard-50-30', 'name' => 'استاندارد', 'gb' => 50, 'days' => 30, 'toman' => 240_000],
        ['slug' => 'quarterly-100-90', 'name' => 'سه‌ماهه', 'gb' => 100, 'days' => 90, 'toman' => 520_000],
    ];

    public function run(
        SuperAdminTrust $trust, ManageBaseCatalog $catalog,
        ManagePlans $plans, ManagePlanPricing $pricing,
    ): void {
        $root = User::role('super-admin')->with('telegramAccount')->orderBy('id')->get()
            ->first(fn (User $user): bool => $trust->eligible($user));

        if ($root === null) {
            throw new RuntimeException('Enroll an active, Telegram-linked, allowlisted Super Admin before seeding the launch catalog.');
        }

        [$created, $skipped] = DB::transaction(function () use ($root, $catalog, $plans, $pricing): array {
            $productId = $this->product($root->id, $catalog);
            $created = [];
            $skipped = [];

            foreach (self::PLANS as $index => $entry) {
                $existing = DB::table('sales_plans')->where('slug', $entry['slug'])->lockForUpdate()->first();

                if ($existing !== null) {
                    if ((int) $existing->product_id !== $productId) {
                        throw new RuntimeException("Existing plan {$entry['slug']} belongs to another product; review it manually.");
                    }

                    // Never overwrite an operator's tariff, status, revision or Squad binding.
                    $skipped[] = $entry['slug'];

                    continue;
                }

                $plan = $plans->create($root->id, new PlanDefinition(
                    productSlug: 'denagate',
                    slug: $entry['slug'],
                    name: $entry['name'],
                    kind: 'hybrid',
                    mode: 'preset',
                    audience: 'both',
                    trafficGb: $entry['gb'],
                    durationDays: $entry['days'],
                    minGb: $entry['gb'],
                    maxGb: $entry['gb'],
                    minDays: $entry['days'],
                    maxDays: $entry['days'],
                    sortOrder: ($index + 1) * 10,
                ));
                $token = $plans->requestChange($root->id, $plan->id, $plan->revision, 'activate');
                $plan = $plans->confirmChange($root->id, $token);

                if ($plan === null) {
                    throw new RuntimeException('Launch plan activation did not return a plan.');
                }

                $publicRials = $entry['toman'] * 10;
                $priceRevision = $pricing->configure($root->id, $plan->id, $plan->revision, 0, new PlanPricingDefinition(
                    method: 'fixed',
                    fixedPriceRials: $publicRials,
                    basePriceRials: 0,
                    perGbRials: 0,
                    perDayRials: 0,
                    resellerDiscountPercent: 25,
                    minimumTotalRials: intdiv($publicRials * 75, 100),
                ));
                $token = $pricing->requestChange($root->id, $plan->id, $plan->revision, $priceRevision, 'activate');
                $pricing->confirmChange($root->id, $token);
                $created[] = $entry['slug'];
            }

            return [$created, $skipped];
        });

        $this->command?->info('Launch catalog: '.count($created).' created, '.count($skipped)." preserved; audit actor #{$root->id}.");
        if ($skipped !== []) {
            $this->command?->warn('Existing plans left unchanged: '.implode(', ', $skipped));
        }
        $this->command?->warn('Before selling, verify each plan tariff and bind it to its real Internal Squad in the Admin Bot.');
    }

    private function product(int $rootId, ManageBaseCatalog $catalog): int
    {
        $existing = DB::table('sales_products')->where('slug', 'denagate')->lockForUpdate()->first();
        if ($existing !== null) {
            return (int) $existing->id;
        }

        $id = $catalog->apply($rootId, 'create', 0, 0, [
            'slug' => 'denagate', 'name' => 'اشتراک DenaGate', 'min_gb' => 1, 'max_gb' => 100,
        ], 'new');
        $catalog->apply($rootId, 'enable', $id, 0, [], $catalog->snapshot($rootId, 'enable', $id));

        return $id;
    }
}
