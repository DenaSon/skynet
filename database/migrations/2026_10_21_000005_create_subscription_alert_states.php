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
        Schema::create('subscription_alert_states', function (Blueprint $table): void {
            $table->foreignId('provisioning_subscription_id')->primary()
                ->constrained('provisioning_subscriptions')->restrictOnDelete();
            $table->unsignedBigInteger('last_used_bytes');
            $table->unsignedBigInteger('last_limit_bytes');
            $table->unsignedInteger('quota_generation')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('subscription_alert_states')
            && DB::table('subscription_alert_states')->exists()) {
            throw new RuntimeException('Subscription alert history exists; use a reviewed forward fix.');
        }

        Schema::dropIfExists('subscription_alert_states');
    }
};
