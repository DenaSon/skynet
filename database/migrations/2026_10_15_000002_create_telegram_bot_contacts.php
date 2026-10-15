<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_bot_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('bot_key', 16);
            $table->unsignedBigInteger('chat_id');
            $table->string('delivery_status', 16)->default('reachable');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['bot_key', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_bot_contacts');
    }
};
