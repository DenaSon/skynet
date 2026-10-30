<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'member' => "🏠 DenaGate\n\nخوش آمدید، {name}.\nنوع حساب: {account_type}",
            'reseller' => "🏠 DenaGate\n\nخوش آمدید، {name}.\nنوع حساب: {account_type}",
        ];

        foreach ($defaults as $name => $value) {
            DB::table('settings')->insertOrIgnore([
                'group' => 'sales_welcome',
                'name' => $name,
                'locked' => false,
                'payload' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Remove only untouched defaults. Never delete operator-edited values.
        $default = "🏠 DenaGate\\n\\nخوش آمدید، {name}.\\nنوع حساب: {account_type}";
        $payload = json_encode($default, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        DB::table('settings')
            ->where('group', 'sales_welcome')
            ->whereIn('name', ['member', 'reseller'])
            ->where('payload', $payload)
            ->delete();
    }
};
