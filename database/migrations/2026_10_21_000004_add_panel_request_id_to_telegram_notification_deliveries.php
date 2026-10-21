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
            $table->foreignId('panel_request_id')->nullable()->constrained('sales_panel_requests')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('telegram_notification_deliveries')->whereNotNull('panel_request_id')->exists()) {
            throw new RuntimeException('Sales Panel notification evidence exists; preserve it before rollback.');
        }

        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('panel_request_id');
        });
    }
};
