<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_bank_payment_claims', function (Blueprint $table): void {
            // Legacy receipts originated on the existing Admin Bot.
            $table->string('source_bot_key', 16)->default('admin');
        });

        Schema::create('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 160)->unique();
            $table->string('event_type', 40);
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('bot_key', 16);
            $table->foreignId('claim_id')->nullable()->constrained('sales_bank_payment_claims')->restrictOnDelete();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('state', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('last_failure', 50)->nullable();
            $table->timestamps();
            $table->index(['state', 'available_at']);
        });
    }

    public function down(): void
    {
        // Never silently delete committed intents and their delivery evidence.
        if (Schema::hasTable('telegram_notification_deliveries')
            && DB::table('telegram_notification_deliveries')->exists()) {
            throw new RuntimeException('Notification deliveries exist; use a reviewed forward-fix, not a destructive rollback.');
        }

        Schema::dropIfExists('telegram_notification_deliveries');
        Schema::table('sales_bank_payment_claims', function (Blueprint $table): void {
            $table->dropColumn('source_bot_key');
        });
    }
};
