<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

final readonly class ListAdminPanelUsers
{
    private const PER_PAGE = 10;

    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    public function handle(int $actorUserId, int $page = 1): AdminPanelUsersPage
    {
        // The shared Application boundary reauthorizes root on every request.
        $this->access->handle($actorUserId);

        if ($page < 1 || $page > 1000) {
            throw new InvalidArgumentException('Invalid panel users page.');
        }

        try {
            $result = (new RemnawaveUsersApi($this->factory->make()))
                ->list(($page - 1) * self::PER_PAGE, self::PER_PAGE);

            $users = [];

            foreach ($result['users'] as $user) {
                if (! preg_match('/^[A-Za-z0-9_-]{1,36}$/D', $user->username)) {
                    throw new UnexpectedValueException('Invalid provider username.');
                }

                $users[] = [
                    'id' => $user->id,
                    'username' => $user->username,
                    'status' => $user->status,
                ];
            }

            return new AdminPanelUsersPage(
                state: 'available',
                page: $page,
                total: $result['total'],
                users: $users,
                hasNext: $page * self::PER_PAGE < $result['total'],
            );
        } catch (Throwable) {
            // Never forward bearer-like fields, raw responses or HTTP errors.
            return new AdminPanelUsersPage('error', page: $page);
        }
    }
}
