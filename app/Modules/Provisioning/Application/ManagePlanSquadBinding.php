<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * OP-02: read the existing panel Squad and bind an existing plan revision.
 * Never creates/edits a Squad or toggles the checkout/provider write gates.
 */
final readonly class ManagePlanSquadBinding
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /** @return list<array{uuid:string,name:string}> */
    public function choices(int $actorId, int $planId): array
    {
        $this->plan($actorId, $planId);
        $response = $this->factory->make()->call('InternalSquadController_getInternalSquads');
        $items = is_array($response) ? ($response['response']['internalSquads'] ?? null) : null;

        if (! is_array($items) || ! array_is_list($items) || count($items) > 50) {
            throw new UnexpectedValueException('Invalid panel Squad list.');
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! self::uuid($item['uuid'] ?? null)
                || ! is_string($item['name'] ?? null)
                || trim($item['name']) === '') {
                throw new UnexpectedValueException('Invalid panel Squad entry.');
            }

            $result[] = [
                'uuid' => strtolower($item['uuid']),
                'name' => mb_substr(trim($item['name']), 0, 70),
            ];
        }

        return $result;
    }

    /**
     * Read-only status for the existing Admin plan screen. A stored UUID is
     * never presented as a live remote service without a matching provider GET.
     *
     * @return array{state:'missing'|'stale'|'unavailable'|'connected',name:?string}
     */
    public function status(int $actorId, int $planId): array
    {
        $this->access->handle($actorId);
        $plan = DB::table('sales_plans')->where('id', $planId)->first();
        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => 'Unavailable plan.']);
        }

        if (! Schema::hasTable('sales_plan_internal_squad_bindings')) {
            return ['state' => 'missing', 'name' => null];
        }

        $binding = DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $planId)->first();
        if ($binding === null) {
            return ['state' => 'missing', 'name' => null];
        }

        if ((int) $binding->plan_revision !== (int) $plan->revision
            || $binding->verified_at === null || ! self::uuid($binding->internal_squad_uuid)) {
            return ['state' => 'stale', 'name' => null];
        }

        try {
            $result = $this->factory->make()->call('InternalSquadController_getInternalSquadByUuid', [
                'uuid' => $binding->internal_squad_uuid,
            ]);
            $remote = is_array($result) ? ($result['response'] ?? null) : null;
            if (! is_array($remote) || ! self::uuid($remote['uuid'] ?? null)
                || strtolower($remote['uuid']) !== strtolower($binding->internal_squad_uuid)
                || ! is_string($remote['name'] ?? null) || trim($remote['name']) === '') {
                return ['state' => 'unavailable', 'name' => null];
            }

            return ['state' => 'connected', 'name' => mb_substr(trim($remote['name']), 0, 70)];
        } catch (\Throwable) {
            // Unreachable / malformed remote data never becomes a positive state.
            return ['state' => 'unavailable', 'name' => null];
        }
    }

    public function bind(int $actorId, int $planId, int $revision, string $uuid): void
    {
        $this->plan($actorId, $planId);
        if ($revision < 1 || ! self::uuid($uuid)) {
            throw ValidationException::withMessages(['squad' => 'Invalid binding.']);
        }

        // Revalidate the selected real Squad, not just a cached list or Telegram callback.
        $remote = $this->factory->make()->call('InternalSquadController_getInternalSquadByUuid', [
            'uuid' => $uuid,
        ]);
        $row = is_array($remote) ? ($remote['response'] ?? null) : null;
        if (! is_array($row) || ! self::uuid($row['uuid'] ?? null)
            || strtolower($row['uuid']) !== strtolower($uuid)) {
            throw ValidationException::withMessages(['squad' => 'Panel Squad not verified.']);
        }

        DB::transaction(function () use ($actorId, $planId, $revision, $uuid): void {
            $this->access->handle($actorId);
            $plan = DB::table('sales_plans')->where('id', $planId)->lockForUpdate()->first();
            if ($plan === null || $plan->status === 'archived' || (int) $plan->revision !== $revision
                || DB::table('sales_orders')->where('plan_id', $planId)->exists()) {
                throw ValidationException::withMessages(['plan' => 'Plan changed or has existing orders.']);
            }

            $old = DB::table('sales_plan_internal_squad_bindings')->where('plan_id', $planId)->first();
            DB::table('sales_plan_internal_squad_bindings')->updateOrInsert(
                ['plan_id' => $planId],
                [
                    'internal_squad_uuid' => strtolower($uuid),
                    'plan_revision' => $revision,
                    'verified_at' => now(),
                    'updated_at' => now(),
                    'created_at' => $old === null ? now() : $old->created_at,
                ],
            );
            DB::table('sales_plan_audits')->insert([
                'actor_user_id' => $actorId,
                'plan_id' => $planId,
                'plan_slug' => $plan->slug,
                'operation' => 'squad_bound',
                'old_revision' => $revision,
                'new_revision' => $revision,
                'details' => json_encode([
                    'changed' => $old?->internal_squad_uuid !== strtolower($uuid),
                    'verified_via' => 'panel_read',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        });
    }

    private function plan(int $actorId, int $planId): void
    {
        $this->access->handle($actorId);
        if ($planId < 1 || ! Schema::hasTable('sales_plan_internal_squad_bindings')
            || ! DB::table('sales_plans')->where('id', $planId)->where('status', '!=', 'archived')->exists()) {
            throw ValidationException::withMessages(['plan' => 'Unavailable plan.']);
        }
    }

    private static function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D',
            $value,
        ) === 1;
    }
}
