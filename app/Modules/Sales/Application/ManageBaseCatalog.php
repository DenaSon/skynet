<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AdminCapabilityAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Root-only, audited management of F09 parent products and legacy duration
 * rates. Never mutates managed plans, orders, billing or provisioning.
 *
 * Existing Artisan management remains available; all UI confirmations verify
 * fresh row contents under MySQL row locks instead of trusting callback data.
 */
final readonly class ManageBaseCatalog
{
    public function __construct(private AdminCapabilityAccess $access) {}

    /** @return list<array<string, mixed>> */
    public function all(int $rootId): array
    {
        $this->access->require($rootId, 'plans.manage');

        return DB::table('sales_products')->orderBy('id')->get()
            ->map(fn (object $row): array => $this->present($row))->all();
    }

    /** @return array<string, mixed> */
    public function find(int $rootId, int $id): array
    {
        $this->access->require($rootId, 'plans.manage');

        return $this->present($this->requireProduct($id));
    }

    /** Returns a nonsecret optimistic concurrency fingerprint for the selected action. */
    public function snapshot(int $rootId, string $operation, int $id, int $days = 0): string
    {
        $this->access->require($rootId, 'plans.manage');

        return $this->currentSnapshot($operation, $id, $days);
    }

    /** @param array<string, int|string|bool> $values */
    public function validate(int $rootId, string $operation, int $id, int $days, array $values): void
    {
        $this->access->require($rootId, 'plans.manage');
        $this->currentSnapshot($operation, $id, $days);

        if ($operation === 'create') {
            $data = $this->productValues($values, true);
            if (DB::table('sales_products')->where('slug', $data['slug'])->exists()) {
                throw $this->invalid();
            }

            return;
        }

        if ($operation === 'edit') {
            $this->productValues($values, false);

            return;
        }

        if ($operation === 'rate') {
            $this->rateValues($values);

            return;
        }

        if ($values !== []) {
            throw $this->invalid();
        }

        $product = $this->requireProduct($id);
        if (in_array($operation, ['enable', 'disable'], true)) {
            if ((bool) $product->is_active === ($operation === 'enable')) {
                throw $this->invalid();
            }

            return;
        }

        $rate = DB::table('sales_product_rates')->where('product_id', $id)
            ->where('duration_days', $days)->first();
        if ($rate === null || (bool) $rate->is_active === ($operation === 'rate-enable')) {
            throw $this->invalid();
        }
    }

    /**
     * @param  array<string, int|string|bool>  $values  Prevalidated UI fields;
     *                                                  the Application action validates again.
     */
    public function apply(int $rootId, string $operation, int $id, int $days, array $values, string $expected): int
    {
        $this->access->require($rootId, 'plans.manage');

        return DB::transaction(function () use ($rootId, $operation, $id, $days, $values, $expected): int {
            if ($operation === 'create') {
                if ($id !== 0 || $days !== 0 || $expected !== 'new') {
                    throw $this->invalid();
                }

                $data = $this->productValues($values, true);
                if (DB::table('sales_products')->where('slug', $data['slug'])->exists()) {
                    throw $this->invalid();
                }

                $newId = (int) DB::table('sales_products')->insertGetId([
                    ...$data, 'is_active' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->audit($rootId, $newId, $data['slug'], null, 'product.created', null, [
                    ...$data, 'is_active' => false,
                ]);

                return $newId;
            }

            // Lock the parent before its rates. This is also the lock order
            // for concurrent UI requests manipulating product and duration.
            $product = DB::table('sales_products')->where('id', $id)->lockForUpdate()->first();
            if ($product === null) {
                throw $this->invalid();
            }

            $rate = null;
            if (in_array($operation, ['rate', 'rate-enable', 'rate-disable'], true)) {
                $this->validateDuration($days);
                $rate = DB::table('sales_product_rates')
                    ->where('product_id', $id)->where('duration_days', $days)
                    ->lockForUpdate()->first();
            } elseif ($days !== 0) {
                throw $this->invalid();
            }

            if (! hash_equals($expected, $this->fingerprint($product, $rate, $operation, $days))) {
                throw ValidationException::withMessages(['catalog' => 'Product or tariff changed; start again.']);
            }

            if ($operation === 'edit') {
                $data = $this->productValues($values, false);
                $before = $this->productState($product);
                DB::table('sales_products')->where('id', $id)->update([
                    ...$data, 'updated_at' => now(),
                ]);
                $this->audit($rootId, $id, (string) $product->slug, null, 'product.updated', $before, [
                    ...$before, ...$data,
                ]);

                return $id;
            }

            if (in_array($operation, ['enable', 'disable'], true)) {
                if ($values !== [] || (bool) $product->is_active === ($operation === 'enable')) {
                    throw $this->invalid();
                }

                $before = $this->productState($product);
                $active = $operation === 'enable';
                DB::table('sales_products')->where('id', $id)->update([
                    'is_active' => $active, 'updated_at' => now(),
                ]);
                $this->audit($rootId, $id, (string) $product->slug, null,
                    'product.'.($active ? 'activated' : 'deactivated'),
                    $before, [...$before, 'is_active' => $active]);

                return $id;
            }

            if ($operation === 'rate') {
                $data = $this->rateValues($values);
                $before = $rate === null ? null : $this->rateState($rate);
                // A rate edit NEVER silently publishes prices; activation is
                // a separate root-confirmed operation. Existing F09 orders
                // keep their immutable snapshots.
                if ($rate === null) {
                    DB::table('sales_product_rates')->insert([
                        'product_id' => $id, 'duration_days' => $days,
                        ...$data, 'is_active' => false,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('sales_product_rates')->where('id', $rate->id)->update([
                        ...$data, 'is_active' => false, 'updated_at' => now(),
                    ]);
                }
                $this->audit($rootId, $id, (string) $product->slug, $days,
                    'rate.configured', $before, [...$data, 'is_active' => false]);

                return $id;
            }

            if (in_array($operation, ['rate-enable', 'rate-disable'], true)) {
                if ($rate === null || $values !== []
                    || (bool) $rate->is_active === ($operation === 'rate-enable')) {
                    throw $this->invalid();
                }

                $active = $operation === 'rate-enable';
                $before = $this->rateState($rate);
                DB::table('sales_product_rates')->where('id', $rate->id)->update([
                    'is_active' => $active, 'updated_at' => now(),
                ]);
                $this->audit($rootId, $id, (string) $product->slug, $days,
                    'rate.'.($active ? 'activated' : 'deactivated'), $before,
                    [...$before, 'is_active' => $active]);

                return $id;
            }

            throw $this->invalid();
        });
    }

    private function currentSnapshot(string $operation, int $id, int $days): string
    {
        if ($operation === 'create' && $id === 0 && $days === 0) {
            return 'new';
        }

        $product = $this->requireProduct($id);
        $rate = null;

        if (in_array($operation, ['rate', 'rate-enable', 'rate-disable'], true)) {
            $this->validateDuration($days);
            $rate = DB::table('sales_product_rates')->where('product_id', $id)
                ->where('duration_days', $days)->first();
            if (str_starts_with($operation, 'rate-') && $rate === null) {
                throw $this->invalid();
            }
        } elseif (! in_array($operation, ['edit', 'enable', 'disable'], true) || $days !== 0) {
            throw $this->invalid();
        }

        return $this->fingerprint($product, $rate, $operation, $days);
    }

    private function fingerprint(object $product, ?object $rate, string $operation, int $days): string
    {
        $state = [$operation, $days, $this->productState($product)];
        if (in_array($operation, ['rate', 'rate-enable', 'rate-disable'], true)) {
            $state[] = $rate === null ? null : $this->rateState($rate);
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, int|string|bool> */
    private function productState(object $product): array
    {
        return [
            'id' => (int) $product->id, 'slug' => (string) $product->slug,
            'name' => (string) $product->name, 'min_gb' => (int) $product->min_gb,
            'max_gb' => (int) $product->max_gb, 'is_active' => (bool) $product->is_active,
        ];
    }

    /** @return array<string, int|bool> */
    private function rateState(object $rate): array
    {
        return [
            'duration_days' => (int) $rate->duration_days,
            'base_price_rials' => (int) $rate->base_price_rials,
            'per_gb_rials' => (int) $rate->per_gb_rials,
            'is_active' => (bool) $rate->is_active,
        ];
    }

    /** @param array<string, int|string|bool> $values
     * @return array<string, int|string>
     */
    private function productValues(array $values, bool $create): array
    {
        $slug = $values['slug'] ?? null;
        $name = $values['name'] ?? null;
        $min = $values['min_gb'] ?? null;
        $max = $values['max_gb'] ?? null;
        if (($create && (! is_string($slug) || preg_match('/\A[a-z][a-z0-9-]{2,49}\z/D', $slug) !== 1))
            || ! is_string($name) || trim($name) !== $name || $name === ''
            || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1
            || ! is_int($min) || ! is_int($max) || $min < 1 || $max > 100 || $min > $max) {
            throw $this->invalid();
        }

        if (! $create && array_key_exists('slug', $values)) {
            throw $this->invalid(); // A stable slug is the parent-plan FK identity.
        }

        return $create
            ? ['slug' => $slug, 'name' => $name, 'min_gb' => $min, 'max_gb' => $max]
            : ['name' => $name, 'min_gb' => $min, 'max_gb' => $max];
    }

    /** @param array<string, int|string|bool> $values
     * @return array{base_price_rials:int,per_gb_rials:int}
     */
    private function rateValues(array $values): array
    {
        $base = $values['base_price_rials'] ?? null;
        $perGb = $values['per_gb_rials'] ?? null;
        if (! is_int($base) || ! is_int($perGb)
            || $base < 0 || $base > 100_000_000_000
            || $perGb < 0 || $perGb > 1_000_000_000
            || ($base === 0 && $perGb === 0)) {
            throw $this->invalid();
        }

        return ['base_price_rials' => $base, 'per_gb_rials' => $perGb];
    }

    private function validateDuration(int $days): void
    {
        if (! in_array($days, ConfigureRate::DURATIONS, true)) {
            throw $this->invalid();
        }
    }

    private function requireProduct(int $id): object
    {
        $row = $id > 0 ? DB::table('sales_products')->where('id', $id)->first() : null;

        return $row ?? throw $this->invalid();
    }

    /** @return array<string, mixed> */
    private function present(object $row): array
    {
        $rates = DB::table('sales_product_rates')->where('product_id', $row->id)
            ->orderBy('duration_days')->get()->map(fn (object $rate): array => $this->rateState($rate))->all();

        return [
            ...$this->productState($row),
            'rates' => $rates,
            'managed_plan_count' => DB::table('sales_plans')->where('product_id', $row->id)->count(),
        ];
    }

    /**
     * @param  array<string, int|string|bool>|null  $before
     * @param  array<string, int|string|bool>  $after
     */
    private function audit(int $rootId, int $id, string $slug, ?int $days, string $operation, ?array $before, array $after): void
    {
        DB::table('sales_catalog_management_audits')->insert([
            'actor_user_id' => $rootId,
            'product_id' => $id,
            'product_slug' => $slug,
            'duration_days' => $days,
            'operation' => $operation,
            'before_state' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_state' => json_encode($after, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['catalog' => 'Invalid or stale base product / duration rate.']);
    }
}
