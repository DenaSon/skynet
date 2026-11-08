<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Jobs;

use App\Modules\Filtering\Application\ScanRegionalFiltering;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ScanRegionalNodeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 75;

    public function __construct(public readonly string $nodeUuid) {}

    public function handle(ScanRegionalFiltering $monitor): void
    {
        if (config('filtering.regional_monitor_enabled', false) !== true) {
            return;
        }
        $monitor->scan($this->nodeUuid);
    }
}
