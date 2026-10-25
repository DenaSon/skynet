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
        public bool $manageRoles = false,
        /** @var list<string> */
        public array $permissions = [],
    ) {}

    public function isAdmin(): bool
    {
        return $this->admin;
    }

    public function canManageRoles(): bool
    {
        return $this->manageRoles;
    }

    public function allows(string $capability): bool
    {
        return $this->manageRoles || in_array($capability, $this->permissions, true);
    }

    public function canEnterAdmin(): bool
    {
        return $this->isAdmin() || $this->allows('users.view')
            || $this->allows('purchases.view') || $this->allows('topups.view')
            || $this->allows('support.view') || $this->allows('reports.view')
            || $this->allows('finance.view') || $this->allows('servers.view')
            || $this->allows('plans.manage') || $this->allows('resellers.review');
    }
}
