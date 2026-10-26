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
        Schema::table('sales_fulfillment_outbox', function (Blueprint $table): void {
            // NULL on historical/disabled activations: never synthesize a later intent.
            $table->string('referral_state', 20)->nullable();
            $table->json('referral_policy')->nullable();
            $table->unsignedInteger('referral_attempts')->default(0);
            $table->timestamp('referral_available_at')->nullable();
            $table->string('referral_failure', 32)->nullable();
            $table->index(['referral_state', 'referral_available_at', 'id'], 'sales_referral_pending_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_fulfillment_outbox')->whereNotNull('referral_state')->exists()) {
            throw new RuntimeException('Referral intents exist; preserve delivery evidence and use a forward fix.');
        }
        Schema::table('sales_fulfillment_outbox', function (Blueprint $table): void {
            $table->dropIndex('sales_referral_pending_idx');
            $table->dropColumn(['referral_state', 'referral_policy', 'referral_attempts', 'referral_available_at', 'referral_failure']);
        });
    }
};
