<?php

declare(strict_types=1);

namespace App\Providers;

use App\Interfaces\Web\Live\EnsureLiveEnabled;
use App\Interfaces\Web\Live\EnsureLiveReseller;
use App\Support\Health\DenaGateOperationalCheck;
use App\Support\Health\OperationalProbe;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\QueueCheck;
use Spatie\Health\Checks\Checks\RedisCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Keep the existing Live Mini App feature and reseller guards on Livewire update requests.
        Livewire::addPersistentMiddleware([EnsureLiveEnabled::class, EnsureLiveReseller::class]);

        $checks = [
            DatabaseCheck::new(),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(75)
                ->failWhenUsedSpaceIsAbovePercentage(90),
        ];

        // Do not report skipped Redis probes as failures on SQLite/array/sync CI.
        // Redis is a required dependency only when the configured cache or queue uses it.
        if (config('cache.default') === 'redis' || config('queue.default') === 'redis') {
            $checks[] = RedisCheck::new();
        }

        // Registered by unique stable names; no user-controlled input.
        foreach (OperationalProbe::CHECKS as $probe) {
            $checks[] = DenaGateOperationalCheck::makeFor($probe);
        }

        Health::checks($checks);
    }
}
