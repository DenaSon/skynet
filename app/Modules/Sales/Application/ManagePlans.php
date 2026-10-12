<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Root-authorized catalog management. No HTTP/Telegram endpoint and no price,
 * order or provider mutation is reachable from this API in F11-B1.1.
 */
final readonly class ManagePlans
{
    public function __construct(private AssertCatalogManagementAccess $authority) {}

    public function create(int $actorId, PlanDefinition $definition): PlanSummary
    {
        $this->authority->handle($actorId);
        $definition->validated();

        return DB::transaction(function () use ($actorId, $definition): PlanSummary {
            $product = DB::table('sales_products')->where('slug', $definition->productSlug)->first();

            if ($product === null) {
                throw ValidationException::withMessages(['product' => 'Catalog product not found.']);
            }

            $id = (int) DB::table('sales_plans')->insertGetId([
                ...$this->values($definition),
                'product_id' => $product->id,
                'status' => 'draft',
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit($actorId, $id, $definition->slug, 'created', null, 1);

            return $this->getRecord($id);
        });
    }

    public function update(int $actorId, int $planId, int $expectedRevision, PlanDefinition $definition): PlanSummary
    {
        $this->authority->handle($actorId);
        $definition->validated();

        return DB::transaction(function () use ($actorId, $planId, $expectedRevision, $definition): PlanSummary {
            $current = DB::table('sales_plans')->where('id', $planId)->lockForUpdate()->first();

            if ($current === null || $current->status === 'archived'
                || (int) $current->revision !== $expectedRevision || $expectedRevision < 1) {
                throw ValidationException::withMessages(['plan' => 'Plan is archived, unavailable, or has changed.']);
            }

            $product = DB::table('sales_products')->where('slug', $definition->productSlug)->first();

            if ($product === null) {
                throw ValidationException::withMessages(['product' => 'Catalog product not found.']);
            }

            DB::table('sales_plans')->where('id', $planId)->where('revision', $expectedRevision)->update([
                ...$this->values($definition),
                'product_id' => $product->id,
                'revision' => $expectedRevision + 1,
                'updated_at' => now(),
            ]);
            $this->audit($actorId, $planId, $definition->slug, 'updated', $expectedRevision, $expectedRevision + 1);

            return $this->getRecord($planId);
        });
    }

    /**
     * Request a time-limited, actor-bound, single-use transition. Even changing
     * active/inactive status goes through explicit confirmation.
     *
     * @return string Raw token; never log/store outside the short-lived UX.
     */
    public function requestChange(int $actorId, int $planId, int $expectedRevision, string $operation): string
    {
        $this->authority->handle($actorId);

        if (! in_array($operation, ['activate', 'deactivate', 'archive', 'delete'], true)) {
            throw ValidationException::withMessages(['operation' => 'Unsupported plan change.']);
        }

        $this->assertTransition($planId, $expectedRevision, $operation);
        $token = bin2hex(random_bytes(16));

        DB::table('sales_plan_confirmations')->insert([
            'actor_user_id' => $actorId,
            'plan_id' => $planId,
            'token_hash' => hash('sha256', $token),
            'operation' => $operation,
            'expected_revision' => $expectedRevision,
            'expires_at' => now()->addMinutes(3),
            'created_at' => now(),
        ]);

        return $token;
    }

    public function confirmChange(int $actorId, string $token): ?PlanSummary
    {
        $this->authority->handle($actorId);

        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            throw ValidationException::withMessages(['confirmation' => 'Invalid confirmation token.']);
        }

        return DB::transaction(function () use ($actorId, $token): ?PlanSummary {
            $row = DB::table('sales_plan_confirmations')
                ->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($row === null || (int) $row->actor_user_id !== $actorId
                || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw ValidationException::withMessages(['confirmation' => 'Expired or unauthorized confirmation.']);
            }

            $current = DB::table('sales_plans')->where('id', $row->plan_id)->lockForUpdate()->first();

            if ($current === null || (int) $current->revision !== (int) $row->expected_revision) {
                throw ValidationException::withMessages(['plan' => 'Plan changed; request a new confirmation.']);
            }

            $this->validateTransition($current, (string) $row->operation);
            DB::table('sales_plan_confirmations')->where('id', $row->id)->delete();

            if ($row->operation === 'delete') {
                // Future orders reference plans with a restrictive FK. Never
                // erase a sold plan; audit rows are deliberately retained.
                if (DB::table('sales_orders')->where('plan_id', $current->id)->exists()) {
                    throw ValidationException::withMessages(['plan' => 'Sold plans cannot be deleted.']);
                }

                DB::table('sales_plans')->where('id', $current->id)->delete();
                $this->audit($actorId, (int) $current->id, (string) $current->slug, 'deleted', (int) $current->revision, null);

                return null;
            }

            $nextStatus = match ($row->operation) {
                'activate' => 'active',
                'deactivate' => 'inactive',
                'archive' => 'archived',
                default => throw ValidationException::withMessages(['operation' => 'Unsupported plan change.']),
            };

            DB::table('sales_plans')->where('id', $current->id)->update([
                'status' => $nextStatus,
                'revision' => (int) $current->revision + 1,
                'updated_at' => now(),
            ]);
            $this->audit(
                $actorId, (int) $current->id, (string) $current->slug,
                (string) $row->operation, (int) $current->revision, (int) $current->revision + 1,
            );

            return $this->getRecord((int) $current->id);
        });
    }

    /** @return list<PlanSummary> */
    public function all(int $actorId): array
    {
        $this->authority->handle($actorId);

        return $this->query()->orderBy('plans.sort_order')->orderBy('plans.id')
            ->get()->map(static fn (object $row): PlanSummary => PlanSummary::fromRecord($row))->all();
    }

    /**
     * @return list<array{slug:string,name:string,active:bool}>
     */
    public function products(int $actorId): array
    {
        $this->authority->handle($actorId);

        return DB::table('sales_products')->orderBy('slug')->get()
            ->map(static fn (object $row): array => [
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'active' => (bool) $row->is_active,
            ])->all();
    }

    public function find(int $actorId, int $planId): PlanSummary
    {
        $this->authority->handle($actorId);

        return $this->getRecord($planId);
    }

    /** @return array<string, int|string|bool|null> */
    private function values(PlanDefinition $plan): array
    {
        return [
            'slug' => $plan->slug,
            'name' => $plan->name,
            'kind' => $plan->kind,
            'mode' => $plan->mode,
            'audience' => $plan->audience,
            'traffic_gb' => $plan->trafficGb,
            'duration_days' => $plan->durationDays,
            'min_gb' => $plan->minGb,
            'max_gb' => $plan->maxGb,
            'min_days' => $plan->minDays,
            'max_days' => $plan->maxDays,
            'sort_order' => $plan->sortOrder,
            'is_featured' => $plan->featured,
        ];
    }

    private function assertTransition(int $planId, int $expectedRevision, string $operation): void
    {
        $row = DB::table('sales_plans')->where('id', $planId)->first();

        if ($row === null || $expectedRevision < 1 || (int) $row->revision !== $expectedRevision) {
            throw ValidationException::withMessages(['plan' => 'Plan changed or unavailable.']);
        }

        $this->validateTransition($row, $operation);
    }

    private function validateTransition(object $plan, string $operation): void
    {
        $allowed = match ($operation) {
            'activate' => in_array($plan->status, ['draft', 'inactive'], true),
            'deactivate' => $plan->status === 'active',
            'archive' => in_array($plan->status, ['draft', 'inactive'], true),
            'delete' => in_array($plan->status, ['draft', 'inactive', 'archived'], true),
            default => false,
        };

        if (! $allowed) {
            throw ValidationException::withMessages(['plan' => 'Invalid plan state transition.']);
        }

        if ($operation === 'activate'
            && ! DB::table('sales_products')->where('id', $plan->product_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['product' => 'Product disabled; cannot activate plan.']);
        }
    }

    private function getRecord(int $planId): PlanSummary
    {
        $row = $this->query()->where('plans.id', $planId)->first();

        if ($row === null) {
            throw ValidationException::withMessages(['plan' => 'Plan not found.']);
        }

        return PlanSummary::fromRecord($row);
    }

    private function query(): Builder
    {
        return DB::table('sales_plans as plans')
            ->join('sales_products as products', 'products.id', '=', 'plans.product_id')
            ->select('plans.*', 'products.slug as product_slug');
    }

    private function audit(
        int $actorId, int $planId, string $slug, string $operation, ?int $previous, ?int $new,
    ): void {
        DB::table('sales_plan_audits')->insert([
            'actor_user_id' => $actorId,
            'plan_id' => $planId,
            'plan_slug' => $slug,
            'operation' => $operation,
            'old_revision' => $previous,
            'new_revision' => $new,
            'created_at' => now(),
        ]);
    }
}
