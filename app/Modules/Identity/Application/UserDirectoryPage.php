<?php

namespace App\Modules\Identity\Application;

final readonly class UserDirectoryPage
{
    /**
     * @param  list<UserDirectoryEntry>  $users
     */
    public function __construct(
        public array $users,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}
}
