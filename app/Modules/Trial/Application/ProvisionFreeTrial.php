<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Interfaces\Telegram\Notifications\TrialNotificationOutbox;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Application\RemnawaveSubscriptionLifecycle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/** One guarded invocation of the existing SQL-reserved provider lifecycle. */
final readonly class ProvisionFreeTrial
{
    public const BYTES = 250 * 1024 * 1024;

    public function __construct(
        private ReserveFreeTrial $admission,
        private RemnawaveSubscriptionLifecycle $lifecycle,
        private TrialNotificationOutbox $notifications,
    ) {}

    public function handle(int $trialId): void
    {
        TrialAvailability::assertReady();
        $trial = DB::transaction(function () use ($trialId): ?object {
            $day = now('Asia/Tehran')->toDateString();
            DB::table('trial_admission_days')->insertOrIgnore(['day' => $day, 'reserved_count' => 0]);
            $quota = DB::table('trial_admission_days')->where('day', $day)->lockForUpdate()->first();
            $row = DB::table('trial_claims')->where('id', $trialId)->lockForUpdate()->first();
            if ($row === null || $row->state !== 'reserved') {
                return null;
            }
            try {
                $this->admission->assertEligible((int) $row->user_id, (int) $row->telegram_user_id);
            } catch (AuthorizationException|ValidationException) {
                DB::table('trial_claims')->where('id', $trialId)->update(['state' => 'failed', 'updated_at' => now()]);

                return null;
            }
            if ($quota === null || (int) $quota->activation_count >= (int) config('trial.daily_limit')) {
                return null;
            }
            // Claimed/uncertain attempts consume the activation slot conservatively.
            DB::table('trial_admission_days')->where('day', $day)->increment('activation_count');
            // Freeze on the worker immediately before CREATE, never on Contact or queue admission.
            $start = now()->utc()->startOfSecond();
            $row->expires_at = $start->copy()->addHours(2)->format('Y-m-d H:i:s');
            $row->internal_squad_uuid = (string) config('trial.internal_squad_uuid');
            DB::table('trial_claims')->where('id', $trialId)->update([
                'state' => 'provisioning', 'internal_squad_uuid' => $row->internal_squad_uuid,
                'activation_started_at' => $start, 'expires_at' => $row->expires_at, 'updated_at' => now(),
            ]);

            return $row;
        }, 3);
        if ($trial === null) {
            return;
        }
        try {
            $this->lifecycle->create(self::reference($trialId), self::key($trialId),
                Carbon::parse((string) $trial->expires_at, 'UTC')->format('Y-m-d\TH:i:s\Z'),
                self::BYTES, [(string) $trial->internal_squad_uuid]);
            $this->complete($trialId);
        } catch (Throwable) {
            // Includes crashes after the durable claim but before/after an HTTP response.
            // A rejected/uncertain CREATE never returns to the automatic pending queue.
            DB::table('trial_claims')->where('id', $trialId)->where('state', 'provisioning')
                ->update(['state' => 'needs_review', 'updated_at' => now()]);
        }
    }

    /** Explicit privileged GET-only reconciliation. Never dispatch CREATE again. */
    public function reconcile(int $trialId, int $operatorId): void
    {
        app(AssertCatalogManagementAccess::class)->handle($operatorId);
        $row = DB::table('trial_claims')->where('id', $trialId)->first();
        if ($row === null || ! in_array($row->state, ['provisioning', 'needs_review'], true)) {
            throw new RuntimeException('Trial is not awaiting reconciliation.');
        }
        $op = DB::table('provisioning_operations')->where('idempotency_key', self::key($trialId))->first();
        if ($op === null) {
            throw new RuntimeException('No recorded CREATE; retain claim and investigate without retry.');
        }
        if ($op->state !== 'completed') {
            $this->lifecycle->reconcileCreate(self::reference($trialId), self::key($trialId));
        }
        $this->complete($trialId);
    }

    private function complete(int $trialId): void
    {
        DB::transaction(function () use ($trialId): void {
            $row = DB::table('trial_claims')->where('id', $trialId)->lockForUpdate()->first();
            $sub = DB::table('provisioning_subscriptions')->where('reference', self::reference($trialId))->first();
            $op = DB::table('provisioning_operations')->where('idempotency_key', self::key($trialId))->first();
            if ($row === null || ! in_array($row->state, ['provisioning', 'needs_review'], true)
                || $sub === null || $sub->state !== 'active' || ! is_numeric($sub->remote_user_id)
                || $op === null || $op->state !== 'completed' || $op->kind !== 'create'
                || (int) $op->subscription_id !== (int) $sub->id || $sub->last_operation_key !== self::key($trialId)) {
                throw new RuntimeException('Trial linkage requires review.');
            }
            DB::table('trial_claims')->where('id', $trialId)->update([
                'state' => 'active', 'provisioning_subscription_id' => $sub->id,
                'activated_at' => now(), 'updated_at' => now(),
            ]);
            $this->notifications->activated($trialId, (int) $row->user_id);
        });
    }

    public static function reference(int $trialId): string
    {
        return 'free-trial:'.$trialId;
    }

    public static function key(int $trialId): string
    {
        return self::reference($trialId).':create';
    }
}
