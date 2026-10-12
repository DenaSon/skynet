<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_order_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('billing_journal_id')->unique()->constrained('billing_journals')->restrictOnDelete();
            $table->string('method', 24);
            $table->unsignedBigInteger('amount_rials');
            $table->timestamp('settled_at');
        });

        Schema::create('sales_fulfillment_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->string('event_key', 128)->unique();
            // F11-A1 persists intent; intentionally no consumer/remote writer exists.
            $table->string('state', 20)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_fulfillment_outbox');
        Schema::dropIfExists('sales_order_settlements');
    }
};
