<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/** Independent, operator-managed switch for connected Telegram Business support. */
final class DenizBusinessSupportSettings extends Settings
{
    public bool $enabled;

    public static function group(): string
    {
        return 'deniz_business_support';
    }

    public static function isEnabled(): bool
    {
        // Refresh across long-lived queue workers; missing settings must never enable replies.
        $settings = app(self::class);
        $settings->refresh();

        return $settings->enabled === true;
    }
}
