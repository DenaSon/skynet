<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ReadAccountCreatedAt
{
    public function handle(int $userId): ?string
    {
        $user = User::query()->find($userId);

        if ($user === null || ! $user->is_active) {
            throw new AuthorizationException;
        }

        return $user->created_at?->toDateString();
    }
}
