<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\UserRole;

final readonly class TelegramActor
{
    public function __construct(
        public int $userId,
        public string $name,
        public UserRole $role,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
