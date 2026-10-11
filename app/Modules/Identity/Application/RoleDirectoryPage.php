<?php

namespace App\Modules\Identity\Application;

final readonly class RoleDirectoryPage
{
    /**
     * @param list<RoleEntry> $roles
     * @param list<PermissionEntry> $permissions
     */
    public function __construct(
        public array $roles,
        public array $permissions,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
