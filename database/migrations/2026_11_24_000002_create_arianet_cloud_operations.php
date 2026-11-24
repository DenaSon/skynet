<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arianet_cloud_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('server_id')->index();
            $table->unsignedBigInteger('active_server_id')->nullable()->unique();
            $table->unsignedBigInteger('actor_user_id');
            $table->string('operation', 24);
            $table->string('token_hash', 64);
            $table->string('snapshot_hash', 64);
            $table->string('status', 24);
            $table->unsignedBigInteger('action_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('arianet_cloud_operations')->exists()) {
            throw new \RuntimeException('Refusing to drop recorded Cloud operations.');
        }
        Schema::dropIfExists('arianet_cloud_operations');
    }
};
