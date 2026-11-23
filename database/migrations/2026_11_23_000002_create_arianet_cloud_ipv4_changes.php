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
        Schema::create('arianet_cloud_ipv4_changes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('cloud_server_id')->index();
            // MySQL permits multiple NULLs. Only one non-terminal operation may
            // reserve any Cloud server; terminal rejection/expiry releases it.
            $table->unsignedBigInteger('active_server_id')->nullable()->unique('ari_ipv4_active_server_unique');
            $table->unsignedBigInteger('actor_user_id');
            $table->char('token_hash', 64)->unique('ari_ipv4_token_unique');
            $table->string('old_ipv4', 15);
            $table->unsignedBigInteger('fee_rials');
            $table->unsignedInteger('free_left');
            $table->char('snapshot_hash', 64);
            $table->string('status', 30);
            $table->unsignedBigInteger('action_id')->nullable();
            $table->string('new_ipv4', 15)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('arianet_cloud_ipv4_changes')
            && DB::table('arianet_cloud_ipv4_changes')->exists()) {
            throw new RuntimeException('Cloud IPv4 operation journal is not empty; preserve audit history.');
        }

        Schema::dropIfExists('arianet_cloud_ipv4_changes');
    }
};
