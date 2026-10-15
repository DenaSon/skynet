<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing update receipts and active processing leases belong to admin.
        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->string('bot_key', 16)->default('admin');
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropPrimary();
            $table->primary(['bot_key', 'update_id']);
        });
    }

    public function down(): void
    {
        // Never discard or silently merge receipts from the second bot.
        if (DB::table('telegram_updates')->where('bot_key', '!=', 'admin')->exists()) {
            throw new RuntimeException('Cannot revert Telegram update scoping while non-admin receipts exist.');
        }

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropPrimary();
            $table->primary('update_id');
        });

        Schema::table('telegram_updates', function (Blueprint $table): void {
            $table->dropColumn('bot_key');
        });
    }
};
