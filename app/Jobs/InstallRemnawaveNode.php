<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Provisioning\Application\ManageNodeInstallation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A bounded launcher, not a long-running SSH installer. The VPS performs the
 * unattended Docker operations in a detached systemd unit.
 */
final class InstallRemnawaveNode implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $identifier) {}

    public function handle(ManageNodeInstallation $installation): void
    {
        $installation->launch($this->identifier);
    }
}
