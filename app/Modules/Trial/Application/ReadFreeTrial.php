<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Modules\Identity\Application\AssertTrialMember;
use App\Modules\Provisioning\Application\RemnawaveSubscriptionLifecycle;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Fresh owner checks before/after GET; credentials never appear in status DTOs. */
final readonly class ReadFreeTrial
{
    public function __construct(private AssertTrialMember $identity, private RemnawaveSubscriptionLifecycle $lifecycle) {}

    /** @return array{id:int,state:string}|null */
    public function find(int $userId, int $telegramId): ?array
    {
        $this->identity->handle($userId, $telegramId);
        $row = DB::table('trial_claims')->where('user_id', $userId)->where('telegram_user_id', $telegramId)->first();

        return $row === null ? null : ['id' => (int) $row->id, 'state' => (string) $row->state];
    }

    public function status(int $userId, int $telegramId): FreeTrialStatus
    {
        return $this->read($userId, $telegramId, false);
    }

    public function connection(int $userId, int $telegramId): string
    {
        return $this->read($userId, $telegramId, true);
    }

    /** @return ($credentials is true ? string : FreeTrialStatus) */
    private function read(int $userId, int $telegramId, bool $credentials): string|FreeTrialStatus
    {
        $this->identity->handle($userId, $telegramId);
        $binding = $this->binding($userId, $telegramId);
        try {
            $remote = $this->lifecycle->subscription((string) $binding->reference);
            if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z\z/D', $remote->expireAt) !== 1) {
                throw new RuntimeException('Invalid trial expiry.');
            }
            $expires = (new DateTimeImmutable($remote->expireAt))->getTimestamp();
            if ($remote->id !== (int) $binding->remote_user_id
                || $remote->trafficLimitBytes !== ProvisionFreeTrial::BYTES
                || $remote->trafficLimitStrategy !== 'NO_RESET'
                || $remote->activeInternalSquadUuids !== [(string) $binding->internal_squad_uuid]
                || $expires !== (new DateTimeImmutable((string) $binding->expires_at, new \DateTimeZone('UTC')))->getTimestamp()) {
                throw new RuntimeException('Trial provider tuple changed.');
            }
            $status = new FreeTrialStatus((int) $binding->id, $remote->status,
                max(0, $remote->trafficLimitBytes - $remote->usedTrafficBytes),
                max(0, $expires - now()->getTimestamp()));
            if ($credentials && ($status->ended() || $remote->status !== 'ACTIVE'
                || strlen($remote->subscriptionUrl) > 2048
                || ! filter_var($remote->subscriptionUrl, FILTER_VALIDATE_URL)
                || parse_url($remote->subscriptionUrl, PHP_URL_SCHEME) !== 'https'
                || parse_url($remote->subscriptionUrl, PHP_URL_USER) !== null
                || parse_url($remote->subscriptionUrl, PHP_URL_PASS) !== null)) {
                throw new RuntimeException('Trial credentials unavailable.');
            }
        } catch (Throwable) {
            // No response body, URL, provider key or exception chain leaves this action.
            throw new RuntimeException('Trial status is unavailable.');
        }
        $this->identity->handle($userId, $telegramId);
        $fresh = $this->binding($userId, $telegramId);
        if ($fresh != $binding) {
            throw new AuthorizationException;
        }

        return $credentials ? $remote->subscriptionUrl : $status;
    }

    private function binding(int $userId, int $telegramId): object
    {
        $row = DB::table('trial_claims as trial')
            ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'trial.provisioning_subscription_id')
            ->where('trial.user_id', $userId)->where('trial.telegram_user_id', $telegramId)
            ->whereIn('trial.state', ['active', 'ended'])->where('sub.state', 'active')
            ->whereNotNull('sub.remote_user_id')
            ->select('trial.id', 'trial.state', 'trial.expires_at', 'trial.internal_squad_uuid',
                'trial.provisioning_subscription_id', 'sub.reference', 'sub.remote_user_id')->first();
        if ($row === null || $row->reference !== ProvisionFreeTrial::reference((int) $row->id)) {
            throw new AuthorizationException;
        }

        return $row;
    }
}
