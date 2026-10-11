<?php

namespace App\Modules\Identity\Application;

final readonly class UserDirectoryEntry
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public array $roles,
        public bool $active,
        public bool $telegramLinked,
        public bool $canChangeStatus,
    ) {}
}
