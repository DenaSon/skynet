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
        // Widening-only: legacy F09 orders keep their existing finite traffic.
        // NULL represents genuinely unlimited time-based plans.
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('traffic_gb')->nullable()->change();
            $table->string('plan_slug', 50)->nullable();
            $table->string('plan_kind', 12)->nullable();
            $table->unsignedInteger('plan_revision')->nullable();
            $table->unsignedInteger('pricing_revision')->nullable();
            $table->uuid('internal_squad_uuid')->nullable();
        });
    }

    public function down(): void
    {
        // Never discard a real plan-order snapshot during an automatic rollback.
        if (DB::table('sales_orders')->whereNotNull('plan_id')->exists()) {
            throw new RuntimeException('Cannot drop snapshots while managed-plan orders exist.');
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn([
                'plan_slug', 'plan_kind', 'plan_revision',
                'pricing_revision', 'internal_squad_uuid',
            ]);
            $table->unsignedSmallInteger('traffic_gb')->nullable(false)->change();
        });
    }
};
