<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('settings')->insertOrIgnore([
            'group' => 'reseller_service_status',
            'name' => 'enabled',
            'locked' => false,
            'payload' => json_encode(false, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $setting = DB::table('settings')
            ->where('group', 'reseller_service_status')
            ->where('name', 'enabled');

        // Do not discard an explicit operator choice during a rollback.
        if ($setting->exists() && $setting->value('payload') !== 'false') {
            throw new RuntimeException('Operator-edited reseller status setting must be preserved.');
        }

        $setting->delete();
    }
};
