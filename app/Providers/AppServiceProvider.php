<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
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
        Health::checks([
            DatabaseCheck::new(),
            RedisCheck::new()->if(
                static fn (): bool => config('cache.default') === 'redis'
                    || config('queue.default') === 'redis',
            ),
            QueueCheck::new()->failWhenHealthJobTakesLongerThanMinutes(5),
            ScheduleCheck::new()->heartbeatMaxAgeInMinutes(2),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(75)
                ->failWhenUsedSpaceIsAbovePercentage(90),
        ]);
    }
}
