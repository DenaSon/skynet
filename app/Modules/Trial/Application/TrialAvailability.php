<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Settings\OperationalSettings;
use Illuminate\Validation\ValidationException;

final class TrialAvailability
{
    public static function ready(): bool
    {
        $squad = config('trial.internal_squad_uuid');

        return OperationalSettings::trialIsEnabled()
            && config('services.remnawave.writes_enabled') === true
            && is_string($squad)
            && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $squad) === 1
            // Verification is bound to the exact Squad, not a reusable boolean.
            && $squad === config('trial.verified_squad_uuid');
    }

    public static function assertReady(): void
    {
        if (! self::ready()) {
            throw ValidationException::withMessages(['trial' => 'تست رایگان فعلاً در دسترس نیست.']);
        }
    }
}
