<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

/** Secret-free projection; never contains provider URLs or passwords. */
final readonly class AdminPanelUsersPage
{
    /** @param list<array{id:int, username:string, status:string}> $users */
    public function __construct(
        public string $state,
        public int $page = 1,
        public int $total = 0,
        public array $users = [],
        public bool $hasNext = false,
    ) {}
}
