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
        Schema::table('arianet_cloud_operations', function (Blueprint $table): void {
            $table->string('target', 64)->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('arianet_cloud_operations')->whereNotNull('target')->exists()) {
            throw new RuntimeException('Cloud maintenance journal contains operational data; rollback refused.');
        }
        Schema::table('arianet_cloud_operations', function (Blueprint $table): void {
            $table->dropColumn('target');
        });
    }
};
