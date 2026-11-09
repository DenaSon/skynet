<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // No customer-facing Business behavior may be enabled by a deployment.
        $this->migrator->add('deniz_business_support.enabled', false);
    }

    public function down(): void
    {
        // Never silently discard an operator's enabled setting during rollback.
        if (app(\App\Settings\DenizBusinessSupportSettings::class)->enabled === true) {
            throw new RuntimeException('Disable Deniz Business support before rolling back its settings.');
        }

        $this->migrator->delete('deniz_business_support.enabled');
    }
};
