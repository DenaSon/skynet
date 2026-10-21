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
        Schema::create('sales_panel_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('first_name', 60);
            $table->string('last_name', 60);
            $table->string('mobile', 16);
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Future SP-02 review evidence must never be dropped by an implicit rollback.
        if (Schema::hasTable('sales_panel_requests')
            && DB::table('sales_panel_requests')->exists()) {
            throw new RuntimeException('Sales Panel application evidence exists; preserve it before rollback.');
        }
        Schema::dropIfExists('sales_panel_requests');
    }
};
