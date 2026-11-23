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
        Schema::table('arianet_cloud_ipv4_changes', function (Blueprint $table): void {
            // Durable step checkpoints belong to the existing single active
            // Cloud IP-change operation. No parallel lifecycle/table.
            $table->json('coordination_state')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('arianet_cloud_ipv4_changes')->whereNotNull('coordination_state')->exists()) {
            throw new RuntimeException('IPv4 coordination checkpoints exist; preserve the audit record.');
        }

        Schema::table('arianet_cloud_ipv4_changes', function (Blueprint $table): void {
            $table->dropColumn('coordination_state');
        });
    }
};
