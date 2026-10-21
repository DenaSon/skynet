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
        // REN-01 is a NON-PAYABLE draft, deliberately outside sales_orders.
        // Payment/provisioning will be separately authorized in REN-02.
        Schema::create('sales_renewal_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('sales_plans')->restrictOnDelete();
            $table->unsignedInteger('plan_revision');
            $table->unsignedInteger('pricing_revision');
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedSmallInteger('traffic_gb')->nullable();
            $table->uuid('internal_squad_uuid');
            $table->unsignedBigInteger('total_price_rials');
            $table->string('state', 16)->default('draft');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_renewal_drafts')
            && DB::table('sales_renewal_drafts')->exists()) {
            throw new RuntimeException('Renewal draft history exists; use a reviewed forward fix.');
        }

        Schema::dropIfExists('sales_renewal_drafts');
    }
};
