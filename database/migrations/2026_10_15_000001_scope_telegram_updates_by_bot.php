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
        if (DB::getDriverName() === 'sqlite') {
            // SQLite INTEGER PRIMARY KEY columns cannot be changed into a composite
            // key by Blueprint::dropPrimary(); rebuild inside the migration transaction.
            Schema::create('telegram_updates_mb02_temp', function (Blueprint $table): void {
                $table->string('bot_key', 16)->default('admin');
                $table->unsignedBigInteger('update_id');
                $table->string('status', 16);
                $table->timestamp('processing_until')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->primary(['bot_key', 'update_id']);
            });
            DB::statement("INSERT INTO telegram_updates_mb02_temp (bot_key, update_id, status, processing_until, processed_at, created_at, updated_at)
                SELECT 'admin', update_id, status, processing_until, processed_at, created_at, updated_at FROM telegram_updates");
            Schema::drop('telegram_updates');
            Schema::rename('telegram_updates_mb02_temp', 'telegram_updates');

            return;
        }

        // Existing MySQL receipts and active leases are backfilled as 'admin'.
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
        // Never discard, merge, or silently re-key receipts from the second bot.
        if (DB::table('telegram_updates')->where('bot_key', '!=', 'admin')->exists()) {
            throw new RuntimeException('Cannot revert Telegram update scoping while non-admin receipts exist.');
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::create('telegram_updates_mb02_temp', function (Blueprint $table): void {
                $table->unsignedBigInteger('update_id')->primary();
                $table->string('status', 16);
                $table->timestamp('processing_until')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
            DB::statement('INSERT INTO telegram_updates_mb02_temp (update_id, status, processing_until, processed_at, created_at, updated_at)
                SELECT update_id, status, processing_until, processed_at, created_at, updated_at FROM telegram_updates');
            Schema::drop('telegram_updates');
            Schema::rename('telegram_updates_mb02_temp', 'telegram_updates');

            return;
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
