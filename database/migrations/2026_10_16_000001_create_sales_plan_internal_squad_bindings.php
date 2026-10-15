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
        Schema::create('sales_plan_internal_squad_bindings', function (Blueprint $table): void {
            $table->foreignId('plan_id')->primary()->constrained('sales_plans')->restrictOnDelete();
            $table->uuid('internal_squad_uuid');
            $table->unsignedInteger('plan_revision');
            $table->timestamp('verified_at')->nullable(); // Only real staging evidence can authorize this.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_plan_internal_squad_bindings')
            && DB::table('sales_plan_internal_squad_bindings')->exists()) {
            throw new RuntimeException('Cannot discard recorded plan-to-squad binding evidence.');
        }

        Schema::dropIfExists('sales_plan_internal_squad_bindings');
    }
};
