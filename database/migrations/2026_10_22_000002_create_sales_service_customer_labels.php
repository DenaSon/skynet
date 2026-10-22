<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_service_customer_labels', function (Blueprint $table): void {
            $table->foreignId('sales_order_id')->primary()->constrained('sales_orders')->cascadeOnDelete();
            $table->string('label', 80);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_service_customer_labels');
    }
};
