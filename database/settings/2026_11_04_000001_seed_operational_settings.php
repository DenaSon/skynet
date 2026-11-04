<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $initial = $this->effectiveLegacyConfig();

        DB::transaction(function () use ($initial): void {
            foreach ($initial as $key => $value) {
                $this->migrator->add('operational.'.$key, $value);
            }
        });
    }

    public function down(): void
    {
        $initial = $this->effectiveLegacyConfig();
        $settings = app(\App\Settings\OperationalSettings::class);

        foreach ($initial as $key => $value) {
            if ($settings->{$key} !== $value) {
                throw new RuntimeException('Operator-adjusted operational settings must not be discarded.');
            }
        }

        DB::transaction(function () use ($initial): void {
            foreach (array_keys($initial) as $key) {
                $this->migrator->delete('operational.'.$key);
            }
        });
    }

    /** @return array{panel_minimum_wallet_toman:int,trial_enabled:bool,trial_daily_limit:int} */
    private function effectiveLegacyConfig(): array
    {
        $panel = config('sales.panel_minimum_wallet_toman');
        if (! is_int($panel) && (! is_string($panel) || preg_match('/\A[1-9][0-9]*\z/D', $panel) !== 1)) {
            throw new RuntimeException('Invalid existing sales panel minimum; migration aborted.');
        }
        $panel = filter_var($panel, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX - 9, 10)],
        ]);
        $enabled = config('trial.enabled');
        $limit = config('trial.daily_limit');
        if ($panel === false || ! is_bool($enabled) || ! is_int($limit)
            || $limit < 1 || $limit > 1000) {
            throw new RuntimeException('Invalid existing trial/panel configuration; migration aborted.');
        }

        // Read Laravel config, not env(): config:cache must retain the actual
        // deployed 550000/true/20 (or other confirmed effective values).
        return [
            'panel_minimum_wallet_toman' => $panel,
            'trial_enabled' => $enabled,
            'trial_daily_limit' => $limit,
        ];
    }
};
