<?php

namespace App\Modules\Identity\Application;

final readonly class PermissionEntry
{
    public function __construct(public int $id, public string $name) {}
}
