<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_order_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('provisioning_subscription_id')->unique()
                ->constrained('provisioning_subscriptions')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_order_links');
    }
};
