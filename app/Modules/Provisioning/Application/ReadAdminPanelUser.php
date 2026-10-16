<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveSubscriptionsApi;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final readonly class ReadAdminPanelUser
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    public function handle(int $actorUserId, int $remoteId): AdminPanelUserDetail
    {
        $this->access->handle($actorUserId);

        if ($remoteId < 1) {
            throw new InvalidArgumentException('Invalid panel user ID.');
        }

        try {
            $client = $this->factory->make();
            $user = (new RemnawaveUsersApi($client))->byId($remoteId);

            if ($user->id !== $remoteId
                || ! preg_match('/^[A-Za-z0-9_-]{1,36}$/D', $user->username)
                || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T/', $user->expireAt)) {
                throw new UnexpectedValueException('Mismatched provider user.');
            }

            $subscription = (new RemnawaveSubscriptionsApi($client))->byUserId($remoteId);
            $matched = $subscription['isFound'] === true;

            // The v3.4.4 protected subscription user has username and shortUuid,
            // NOT a numeric user.id. All three read paths were observed live.
            if ($matched) {
                $summary = $subscription['user'] ?? null;
                if (! is_array($summary)
                    || ! is_string($summary['username'] ?? null)
                    || ! is_string($summary['shortUuid'] ?? null)
                    || $summary['username'] !== $user->username
                    || $summary['shortUuid'] !== $user->shortUuid) {
                    throw new UnexpectedValueException('Subscription identity mismatch.');
                }
            }

            return new AdminPanelUserDetail(
                state: 'available',
                id: $user->id,
                username: $user->username,
                status: $user->status,
                trafficLimitBytes: $user->trafficLimitBytes,
                usedTrafficBytes: $user->usedTrafficBytes,
                expiresAt: substr($user->expireAt, 0, 10),
                subscriptionMatched: $matched,
            );
        } catch (Throwable) {
            // Failed cross-checks must not disclose an unrelated subscription.
            return new AdminPanelUserDetail('error');
        }
    }
}
