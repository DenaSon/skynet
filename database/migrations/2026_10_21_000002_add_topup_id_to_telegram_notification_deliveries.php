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
        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->foreignId('topup_id')->nullable()->constrained('billing_topups')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_notification_deliveries')->whereNotNull('topup_id')->exists()) {
            throw new RuntimeException('Wallet notification evidence exists; preserve it before rollback.');
        }

        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('topup_id');
        });
    }
};
