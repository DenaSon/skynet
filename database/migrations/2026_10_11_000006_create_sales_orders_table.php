<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 64)->unique();
            $table->string('product_slug', 50);
            $table->string('product_name', 120);
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedSmallInteger('traffic_gb');
            $table->unsignedBigInteger('public_price_rials');
            $table->unsignedTinyInteger('discount_percent');
            $table->unsignedBigInteger('discount_rials');
            $table->unsignedBigInteger('total_price_rials');
            $table->string('status', 32)->default('pending_payment');
            $table->timestamps();
            $table->index(['buyer_user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_orders');
    }
};
