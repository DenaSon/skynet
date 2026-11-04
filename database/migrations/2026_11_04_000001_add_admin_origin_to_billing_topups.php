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
        Schema::table('billing_topups', function (Blueprint $table): void {
            // Existing verified top-ups remain customer-origin by default.
            $table->string('origin', 24)->default('customer');
            $table->string('admin_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        // Dropping origin would erase audit classification for real credits.
        if (DB::table('billing_topups')->where('origin', 'admin_verified_bank')->exists()) {
            throw new RuntimeException('Cannot remove verified admin credit audit metadata.');
        }

        Schema::table('billing_topups', function (Blueprint $table): void {
            $table->dropColumn(['origin', 'admin_reason']);
        });
    }
};
