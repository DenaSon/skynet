<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_topups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('billing_wallets')->restrictOnDelete();
            $table->string('request_key', 128)->unique();
            $table->unsignedBigInteger('amount_rials');
            $table->string('status', 16)->default('pending')->index();
            $table->string('payment_reference', 64)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('billing_verified_receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('payment_reference', 64)->unique();
            $table->foreignId('topup_id')->unique()->constrained('billing_topups')->restrictOnDelete();
            $table->timestamp('verified_at');
        });

        Schema::create('billing_journals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('billing_wallets')->restrictOnDelete();
            $table->string('kind', 32);
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('created_at');
        });

        Schema::create('billing_postings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('billing_journals')->restrictOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained('billing_wallets')->restrictOnDelete();
            $table->string('account_code', 32);
            $table->string('side', 6);
            $table->unsignedBigInteger('amount_rials');
            $table->index(['wallet_id', 'account_code', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_postings');
        Schema::dropIfExists('billing_journals');
        Schema::dropIfExists('billing_verified_receipts');
        Schema::dropIfExists('billing_topups');
        Schema::dropIfExists('billing_wallets');
    }
};
