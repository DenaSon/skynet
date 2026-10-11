<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->char('code', 24)->unique();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->index('referrer_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
