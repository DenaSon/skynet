<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_bot_contacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('admin_notification_thread_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_bot_contacts', function (Blueprint $table): void {
            $table->dropColumn('admin_notification_thread_id');
        });
    }
};
