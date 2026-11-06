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
        // Historical orders are unknown. Never backfill from mutable roles or discounts.
        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->string('buyer_category', 16)->nullable();
        });
    }

    public function down(): void
    {
        // Rollback must not silently erase immutable category snapshots.
        if (DB::table('sales_orders')->whereNotNull('buyer_category')->exists()) {
            throw new RuntimeException('Cannot discard existing buyer category snapshots.');
        }

        Schema::table('sales_orders', function (Blueprint $table): void {
            $table->dropColumn('buyer_category');
        });
    }
};
