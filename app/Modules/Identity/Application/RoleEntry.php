<?php

namespace App\Modules\Identity\Application;

final readonly class RoleEntry
{
    /**
     * @param list<string> $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public array $permissions,
        public int $userCount,
        public bool $protected,
    ) {
    }
}
