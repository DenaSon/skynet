<?php

declare(strict_types=1);

namespace App\Modules\Provisioning;

use App\Modules\Provisioning\Presentation\Console\InspectRemnawave;
use Illuminate\Support\ServiceProvider;

final class ProvisioningServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InspectRemnawave::class]);
        }
    }
}
