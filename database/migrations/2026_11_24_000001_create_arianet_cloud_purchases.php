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
        Schema::create('arianet_cloud_purchases', function (Blueprint $table): void {
            $table->id();
            // One account-wide pending or unresolved purchase, not merely per actor.
            $table->unsignedTinyInteger('active_slot')->nullable()->unique();
            $table->unsignedBigInteger('actor_user_id');
            $table->char('token_hash', 64)->unique();
            $table->string('name', 63);
            $table->string('datacenter', 32);
            $table->unsignedBigInteger('plan_id');
            $table->string('image', 64);
            $table->unsignedBigInteger('ssh_key_id');
            $table->char('quote_hash', 64);
            $table->unsignedBigInteger('hourly_rials');
            $table->unsignedBigInteger('monthly_rials');
            $table->unsignedBigInteger('minimum_rials');
            $table->string('status', 25);
            $table->unsignedBigInteger('server_id')->nullable();
            $table->unsignedBigInteger('action_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('arianet_cloud_purchases')
            && DB::table('arianet_cloud_purchases')->exists()) {
            throw new RuntimeException('Cloud purchase journal contains operational data; rollback refused.');
        }

        Schema::dropIfExists('arianet_cloud_purchases');
    }
};
