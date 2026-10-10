<?php

namespace App\Modules\Identity\Application;

final readonly class TelegramActor
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public int $userId,
        public string $name,
        public array $roles,
        public bool $admin,
    ) {}

    public function isAdmin(): bool
    {
        return $this->admin;
    }
}
