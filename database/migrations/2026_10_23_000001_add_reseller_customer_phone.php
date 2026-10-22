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
        Schema::table('sales_service_customer_labels', function (Blueprint $table): void {
            $table->string('label', 80)->nullable()->change();
            $table->string('phone', 16)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('sales_service_customer_labels')->whereNull('label')->exists()
            || DB::table('sales_service_customer_labels')->whereNotNull('phone')->exists()) {
            throw new RuntimeException('Refusing to drop customer phone or unnamed customer metadata.');
        }

        Schema::table('sales_service_customer_labels', function (Blueprint $table): void {
            $table->dropColumn('phone');
            $table->string('label', 80)->nullable(false)->change();
        });
    }
};
