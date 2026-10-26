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
        Schema::create('referral_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('referral_id')->unique()->constrained('referrals')->restrictOnDelete();
            $table->foreignId('referrer_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invited_user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->unsignedBigInteger('amount_rials');
            $table->foreignId('billing_journal_id')->unique()->constrained('billing_journals')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->index(['referrer_user_id', 'id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('referral_rewards') && DB::table('referral_rewards')->exists()) {
            throw new RuntimeException('Referral rewards exist; preserve financial evidence and use a forward fix.');
        }
        Schema::dropIfExists('referral_rewards');
    }
};
