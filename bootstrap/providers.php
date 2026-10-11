<?php

use App\Interfaces\Telegram\TelegramServiceProvider;
use App\Modules\Administration\AdministrationServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Provisioning\ProvisioningServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    ProvisioningServiceProvider::class,
    AdministrationServiceProvider::class,
    TelegramServiceProvider::class,
];
