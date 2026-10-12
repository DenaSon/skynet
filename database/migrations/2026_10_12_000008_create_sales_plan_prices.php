<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_plan_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('plan_id')->unique()->constrained('sales_plans')->cascadeOnDelete();
            $table->string('method', 12); // fixed | formula
            $table->unsignedInteger('configured_plan_revision');
            $table->unsignedBigInteger('fixed_price_rials')->nullable();
            $table->unsignedBigInteger('base_price_rials')->default(0);
            $table->unsignedBigInteger('per_gb_rials')->default(0);
            $table->unsignedBigInteger('per_day_rials')->default(0);
            $table->unsignedTinyInteger('reseller_discount_percent')->default(0);
            $table->unsignedBigInteger('minimum_total_rials')->default(1);
            $table->string('status', 12)->default('draft'); // draft | active | inactive
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('sales_plan_price_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_price_id')->constrained('sales_plan_prices')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('operation', 16); // activate | deactivate
            $table->unsignedInteger('expected_revision');
            $table->unsignedInteger('expected_plan_revision');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });

        Schema::create('sales_plan_price_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->unsignedBigInteger('plan_price_id');
            $table->string('operation', 24);
            $table->unsignedInteger('old_revision')->nullable();
            $table->unsignedInteger('new_revision')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['plan_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_plan_price_audits');
        Schema::dropIfExists('sales_plan_price_confirmations');
        Schema::dropIfExists('sales_plan_prices');
    }
};
