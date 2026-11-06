<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Infrastructure\Backup\RwBackupSsh;

/**
 * Application boundary for the RW backup capability.
 * Telegram and HTTP transport do not depend on SSH implementation details.
 */
class RwBackupOperations
{
    public function __construct(private readonly RwBackupSsh $ssh) {}

    public static function validId(string $id): bool
    {
        return RwBackupSsh::validId($id);
    }

    /** @return list<array{id:string,state:string,size:int,timestamp:int}> */
    public function all(): array
    {
        return $this->ssh->all();
    }

    public function start(): string
    {
        return $this->ssh->start();
    }

    /** @return array{size:int,sha256:string} */
    public function info(string $id): array
    {
        return $this->ssh->info($id);
    }

    public function delete(string $id): void
    {
        $this->ssh->delete($id);
    }

    public function stream(string $id): void
    {
        $this->ssh->stream($id);
    }
}
