<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('sales_products')->restrictOnDelete();
            $table->string('slug', 50)->unique();
            $table->string('name', 120);
            $table->string('kind', 12); // volume | time | hybrid
            $table->string('mode', 12); // preset | builder
            $table->string('audience', 12); // public | reseller | both
            $table->string('status', 12)->default('draft'); // draft | active | inactive | archived
            $table->unsignedSmallInteger('traffic_gb')->nullable(); // null = unlimited for time, or user chooses for builder
            $table->unsignedSmallInteger('duration_days')->nullable(); // null = user chooses for builder
            $table->unsignedSmallInteger('min_gb')->nullable();
            $table->unsignedSmallInteger('max_gb')->nullable();
            $table->unsignedSmallInteger('min_days');
            $table->unsignedSmallInteger('max_days');
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['status', 'audience', 'sort_order', 'id'], 'sales_plans_visibility_idx');
        });

        // Reserved for the next pricing/order phase. Legacy F09 orders remain
        // independent; the new plan catalog cannot yet be purchased.
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->constrained('sales_plans')->restrictOnDelete();
        });

        Schema::create('sales_plan_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('sales_plans')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('operation', 16);
            $table->unsignedInteger('expected_revision');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');
        });

        Schema::create('sales_plan_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('plan_id');
            $table->string('plan_slug', 50);
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
        Schema::dropIfExists('sales_plan_audits');
        Schema::dropIfExists('sales_plan_confirmations');
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });
        Schema::dropIfExists('sales_plans');
    }
};
