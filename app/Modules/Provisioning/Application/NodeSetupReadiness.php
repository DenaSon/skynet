<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\NodeSetup\NodeSshPreflight;

/** Authorized NW-01 boundary over read-only SSH readiness. */
final readonly class NodeSetupReadiness
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private NodeSshPreflight $ssh,
    ) {}

    public function validateTarget(int $actorId, string $ip, int $port): void
    {
        $this->access->handle($actorId);
        $this->ssh->validateTarget($ip, $port);
    }

    /** @return array{fingerprint:string,knownHost:string} */
    public function scan(int $actorId, string $ip, int $port): array
    {
        $this->access->handle($actorId);

        return $this->ssh->scan($ip, $port);
    }

    public function check(int $actorId, string $ip, int $port, string $username, string $password, string $knownHost): string
    {
        $this->access->handle($actorId);

        return $this->ssh->check($ip, $port, $username, $password, $knownHost);
    }
}
