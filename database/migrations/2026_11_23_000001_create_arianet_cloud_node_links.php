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
        Schema::create('arianet_cloud_node_links', function (Blueprint $table): void {
            // Cloud IDs survive IPv4 changes; Node UUIDs survive address changes.
            $table->unsignedBigInteger('cloud_server_id')->primary();
            $table->uuid('node_uuid')->unique('ari_node_link_unique');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // A deployment rollback must not silently erase confirmed mappings.
        if (Schema::hasTable('arianet_cloud_node_links')
            && DB::table('arianet_cloud_node_links')->exists()) {
            throw new RuntimeException('Cloud node links exist; preserve the mapping and use a reviewed forward fix.');
        }

        Schema::dropIfExists('arianet_cloud_node_links');
    }
};
