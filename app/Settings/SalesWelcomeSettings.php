<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

final class SalesWelcomeSettings extends Settings
{
    public string $member;

    public string $reseller;

    public static function group(): string
    {
        return 'sales_welcome';
    }
}
