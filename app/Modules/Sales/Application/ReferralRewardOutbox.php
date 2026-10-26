<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Interfaces\Telegram\Notifications\ReferralRewardNotificationOutbox;
use App\Modules\Referral\Application\GrantReferralReward;
use App\Modules\Referral\Application\ReferralRewardPolicy;
use App\Modules\Referral\Jobs\GrantReferralRewardJob;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Sales owns the existing fulfillment outbox; Referral/Billing own award decisions and credit. */
final class ReferralRewardOutbox
{
    /**
     * Local policy snapshot, included in the SAME update that commits successful
     * fulfillment. No identity lookup, financial write, queue or provider call.
     *
     * @return array<string,mixed>
     */
    public function captureFields(): array
    {
        if (config('referral.rewards_enabled') !== true
            || ! Schema::hasColumn('sales_fulfillment_outbox', 'referral_state')) {
            return [];
        }
        try {
            $policy = app(ReferralRewardPolicy::class)->settings();

            return [
                'referral_state' => 'pending',
                'referral_policy' => json_encode($policy, JSON_THROW_ON_ERROR),
                'referral_available_at' => now(),
            ];
        } catch (Throwable) {
            // A malformed referral setting must not turn a successful CREATE
            // into needs_review, nor be silently backfilled after a config fix.
            return ['referral_state' => 'suppressed', 'referral_failure' => 'configuration'];
        }
    }

    /** Queue failure never escapes into successful provider fulfillment. */
    public function enqueue(int $orderId): bool
    {
        try {
            GrantReferralRewardJob::dispatch($orderId);

            return true;
        } catch (Throwable) {
            Log::warning('Referral reward enqueue unavailable.', ['order_id' => $orderId]);

            return false;
        }
    }

    public function dispatchPending(): int
    {
        if (! $this->ready()) {
            return 0;
        }
        $dispatched = 0;
        $ids = DB::table('sales_fulfillment_outbox')->where('state', 'completed')
            ->where('referral_state', 'pending')->where('referral_available_at', '<=', now())
            ->orderBy('referral_available_at')->orderBy('id')->limit(50)->pluck('sales_order_id');
        foreach ($ids as $id) {
            if ($this->enqueue((int) $id)) {
                $dispatched++;
            }
        }

        return $dispatched;
    }

    public function process(int $orderId): void
    {
        if (! $this->ready()) {
            return; // Kill switch/config outage pauses already captured intents.
        }
        try {
            DB::transaction(function () use ($orderId): void {
                $row = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
                if ($row === null || $row->state !== 'completed' || $row->referral_state !== 'pending'
                    || $row->referral_available_at === null || $row->referral_available_at > now()->toDateTimeString()) {
                    return;
                }
                if ($row->event_key !== 'sales-order:'.$orderId.':create') {
                    throw new \RuntimeException('Invalid referral source event.');
                }
                $decoded = json_decode((string) $row->referral_policy, true, 8, JSON_THROW_ON_ERROR);
                if (! is_array($decoded)) {
                    throw new \RuntimeException('Invalid referral policy snapshot.');
                }
                $policy = app(ReferralRewardPolicy::class)->validateSnapshot($decoded);
                try {
                    $rewardId = app(GrantReferralReward::class)->handle($orderId, $policy);
                    $state = $rewardId === null ? 'suppressed' : 'credited';
                    $reason = $rewardId === null ? 'ineligible_or_limit' : null;
                    if ($rewardId !== null) {
                        // An outbox insert failure rolls the award and ledger back.
                        app(ReferralRewardNotificationOutbox::class)->credited($rewardId, $orderId);
                    }
                } catch (AuthorizationException) {
                    $state = 'suppressed';
                    $reason = 'membership';
                }
                // Deliberately leave the original updated_at/completion timestamp
                // untouched: first-purchase ordering must not change on reward retry.
                DB::table('sales_fulfillment_outbox')->where('id', $row->id)->update([
                    'referral_state' => $state, 'referral_failure' => $reason,
                    'referral_attempts' => (int) $row->referral_attempts + 1, 'referral_available_at' => null,
                ]);
            }, 3);
        } catch (Throwable $error) {
            $this->recordFailure($orderId, $error instanceof QueryException || $error instanceof \PDOException);
        }
    }

    /** @return array{ready:bool,pending:int,credited:int,suppressed:int,needs_review:int} */
    public function status(): array
    {
        $counts = ['ready' => $this->ready(), 'pending' => 0, 'credited' => 0, 'suppressed' => 0, 'needs_review' => 0];
        if (Schema::hasColumn('sales_fulfillment_outbox', 'referral_state')) {
            foreach (['pending', 'credited', 'suppressed', 'needs_review'] as $state) {
                $counts[$state] = DB::table('sales_fulfillment_outbox')->where('referral_state', $state)->count();
            }
        }

        return $counts;
    }

    private function ready(): bool
    {
        try {
            app(ReferralRewardPolicy::class)->settings();

            return Schema::hasColumn('sales_fulfillment_outbox', 'referral_state');
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(int $orderId, bool $transient): void
    {
        try {
            DB::transaction(function () use ($orderId, $transient): void {
                $row = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $orderId)->lockForUpdate()->first();
                if ($row === null || $row->referral_state !== 'pending') {
                    return; // An already committed credit must never be reopened.
                }
                $attempts = (int) $row->referral_attempts + 1;
                $retry = $transient && $attempts < 5;
                DB::table('sales_fulfillment_outbox')->where('id', $row->id)->update([
                    'referral_state' => $retry ? 'pending' : 'needs_review',
                    'referral_attempts' => $attempts,
                    'referral_available_at' => $retry ? now()->addSeconds(min(900, 60 * $attempts)) : null,
                    'referral_failure' => $transient ? 'database' : 'integrity',
                ]);
            }, 3);
        } catch (Throwable) {
            // DB outage leaves the original pending intent recoverable by cron.
        }
        Log::warning('Referral reward processing unavailable.', ['order_id' => $orderId]);
    }
}
