<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class AssertCatalogManagementAccess
{
    public function handle(int $actorUserId): void
    {
        $user = User::query()->with('telegramAccount')->find($actorUserId);

        if ($user === null || ! app(SuperAdminTrust::class)->eligible($user)) {
            throw new AuthorizationException;
        }
    }
}
