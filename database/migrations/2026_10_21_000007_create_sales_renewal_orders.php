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
        Schema::create('sales_renewal_orders', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->primary()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('source_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('provisioning_subscription_id')
                ->constrained('provisioning_subscriptions')->restrictOnDelete();
            // Targets are frozen once after verified payment, before the only remote PATCH.
            $table->string('target_expire_at', 32)->nullable();
            $table->unsignedBigInteger('target_traffic_limit_bytes')->nullable();
            $table->timestamps();
            $table->index(['source_order_id', 'sales_order_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_renewal_orders') && DB::table('sales_renewal_orders')->exists()) {
            throw new RuntimeException('Paid-renewal references exist; rollback requires a reviewed forward fix.');
        }

        Schema::dropIfExists('sales_renewal_orders');
    }
};
