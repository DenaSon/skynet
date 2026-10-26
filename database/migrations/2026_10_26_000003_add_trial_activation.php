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
        Schema::table('trial_admission_days', function (Blueprint $table): void {
            $table->unsignedInteger('activation_count')->default(0);
        });
        Schema::table('trial_claims', function (Blueprint $table): void {
            $table->foreignId('provisioning_subscription_id')->nullable()->unique()
                ->constrained('provisioning_subscriptions')->restrictOnDelete();
            $table->uuid('internal_squad_uuid')->nullable();
            $table->timestamp('activation_started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->index(['state', 'updated_at']);
        });
        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->foreignId('trial_claim_id')->nullable()->constrained('trial_claims')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('trial_claims')->whereNotNull('activation_started_at')->exists()
            || DB::table('telegram_notification_deliveries')->whereNotNull('trial_claim_id')->exists()) {
            throw new RuntimeException('Trial activation evidence exists; use a reviewed forward-fix.');
        }
        Schema::table('trial_admission_days', function (Blueprint $table): void {
            $table->dropColumn('activation_count');
        });
        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('trial_claim_id');
        });
        Schema::table('trial_claims', function (Blueprint $table): void {
            $table->dropForeign(['provisioning_subscription_id']);
            $table->dropUnique(['provisioning_subscription_id']);
            $table->dropColumn('provisioning_subscription_id');
            $table->dropIndex(['state', 'updated_at']);
            $table->dropColumn(['internal_squad_uuid', 'activation_started_at', 'expires_at', 'activated_at']);
        });
    }
};
