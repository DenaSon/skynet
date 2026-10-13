<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_catalog_management_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->string('product_slug', 50);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->string('operation', 32);
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->timestamp('created_at');
            $table->index(['product_id', 'id'], 'sales_catalog_audits_product_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_catalog_management_audits');
    }
};
