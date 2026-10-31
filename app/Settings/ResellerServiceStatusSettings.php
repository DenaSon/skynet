<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class ResellerServiceStatusSettings extends Settings
{
    public bool $enabled;

    public static function group(): string
    {
        return 'reseller_service_status';
    }
}
