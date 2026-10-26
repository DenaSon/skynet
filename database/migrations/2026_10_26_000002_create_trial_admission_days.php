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
        Schema::create('trial_admission_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedInteger('reserved_count')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('trial_admission_days')->where('reserved_count', '>', 0)->exists()) {
            throw new RuntimeException('Trial quota evidence exists; use a reviewed forward-fix.');
        }
        Schema::dropIfExists('trial_admission_days');
    }
};
