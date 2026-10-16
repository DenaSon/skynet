<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offline-only, fail-closed read contract for future Plan → Internal Squad.
 * No API or operation in this phase creates/verifies mappings or activates sales.
 */
final readonly class CheckPlanSquadReadiness
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    public function handle(int $actorUserId, int $planId): bool
    {
        $this->access->handle($actorUserId);

        return $this->verifiedSquadForCheckout($planId) !== null;
    }

    /**
     * Internal read-only buyer-checkout gate. Caller must first authorize and
     * revalidate its buyer and plan; this method does not grant any provider write.
     */
    public function verifiedSquadForCheckout(int $planId): ?string
    {
        if ($planId < 1
            || config('services.remnawave.squad_bindings_live_verified', false) !== true
            || ! Schema::hasTable('sales_plan_internal_squad_bindings')) {
            return null;
        }

        $row = DB::table('sales_plans as plan')
            ->join('sales_products as product', 'product.id', '=', 'plan.product_id')
            ->join('sales_plan_internal_squad_bindings as binding', 'binding.plan_id', '=', 'plan.id')
            ->where('plan.id', $planId)
            ->select('plan.status', 'plan.revision', 'product.is_active',
                'binding.plan_revision', 'binding.internal_squad_uuid', 'binding.verified_at')
            ->lockForUpdate()
            ->first();

        $ready = $row !== null
            && $row->status === 'active'
            && (bool) $row->is_active
            && (int) $row->revision > 0
            && (int) $row->plan_revision === (int) $row->revision
            && $row->verified_at !== null
            && is_string($row->internal_squad_uuid)
            && preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $row->internal_squad_uuid) === 1;

        return $ready ? (string) $row->internal_squad_uuid : null;
    }
}
