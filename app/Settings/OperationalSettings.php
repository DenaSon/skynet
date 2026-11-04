<?php

declare(strict_types=1);

namespace App\Settings;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\LaravelSettings\Settings;

/**
 * Operator-managed policy shared by Admin Bot, Sales Bot and queue workers.
 * Nullable defaults exist solely for rolling deployments before migration.
 */
final class OperationalSettings extends Settings
{
    public ?int $panel_minimum_wallet_toman = null;

    public ?bool $trial_enabled = null;

    public ?int $trial_daily_limit = null;

    public static function group(): string
    {
        return 'operational';
    }

    public static function panelMinimumWalletToman(): int|string
    {
        $value = self::effective('panel_minimum_wallet_toman', 'sales.panel_minimum_wallet_toman');
        if (! is_int($value) && ! is_string($value)) {
            throw new RuntimeException('Invalid panel minimum wallet setting.');
        }

        return $value;
    }

    public static function trialIsEnabled(): bool
    {
        // Invalid values cannot enable provider writes.
        return self::effective('trial_enabled', 'trial.enabled') === true;
    }

    public static function trialDailyLimit(): int
    {
        $value = self::effective('trial_daily_limit', 'trial.daily_limit');
        // A malformed value must deny new reservations/provider activations.
        // Returning zero preserves the existing Trial ValidationException
        // contract while both admission and worker quota checks fail closed.
        return is_int($value) && $value >= 1 && $value <= 1000 ? $value : 0;
    }

    private static function effective(string $property, string $configKey): mixed
    {
        // Refresh the repository snapshot on each policy decision, including
        // queued jobs and long-lived PHP workers, before trusting a prior ON.
        $settings = app(self::class);
        $settings->refresh();
        $value = $settings->{$property};
        if ($value !== null) {
            return $value;
        }

        // Never silently resurrect a deleted/invalid setting from ENV after
        // migration. Only before its first rollout preserve the live config.
        if (DB::table('migrations')
            ->where('migration', '2026_11_04_000001_seed_operational_settings')->exists()) {
            throw new RuntimeException('Required operational setting is missing: '.$property);
        }

        return config($configKey);
    }
}
