<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 120)->unique();
            $table->string('remote_username', 36)->unique();
            $table->unsignedBigInteger('remote_user_id')->nullable()->unique();
            $table->string('state', 24);
            $table->string('last_operation_key', 120);
            $table->timestamps();
        });

        Schema::create('provisioning_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('subscription_id')->constrained('provisioning_subscriptions')->cascadeOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->string('kind', 24);
            $table->char('payload_hash', 64);
            $table->string('state', 24);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_operations');
        Schema::dropIfExists('provisioning_subscriptions');
    }
};
