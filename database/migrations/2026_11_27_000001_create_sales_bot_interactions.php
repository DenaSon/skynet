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
        Schema::create('sales_bot_interactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('update_id')->unique();
            $table->string('kind', 16);
            $table->timestamp('recorded_at');
            $table->index(['user_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        if (DB::table('sales_bot_interactions')->exists()) {
            throw new RuntimeException('Recorded interactions exist; use a reviewed forward-fix.');
        }

        Schema::dropIfExists('sales_bot_interactions');
    }
};
