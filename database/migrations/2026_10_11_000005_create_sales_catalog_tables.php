<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_products', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 120);
            $table->unsignedSmallInteger('min_gb')->default(1);
            $table->unsignedSmallInteger('max_gb')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sales_product_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('sales_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('duration_days');
            $table->unsignedBigInteger('base_price_rials');
            $table->unsignedBigInteger('per_gb_rials');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['product_id', 'duration_days']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_product_rates');
        Schema::dropIfExists('sales_products');
    }
};
