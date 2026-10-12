<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_bank_payment_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('buyer_user_id')->constrained('users')->restrictOnDelete();
            $table->text('photo_encrypted');
            $table->string('photo_fingerprint', 64)->unique();
            $table->string('telegram_message_key', 100)->unique();
            $table->string('status', 20)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['sales_order_id', 'status']);
        });

        Schema::create('sales_bank_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sales_order_id')->unique()->constrained('sales_orders')->restrictOnDelete();
            $table->foreignId('payment_claim_id')->unique()->constrained('sales_bank_payment_claims')->restrictOnDelete();
            $table->foreignId('billing_journal_id')->unique()->constrained('billing_journals')->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->string('payment_reference', 64)->unique();
            $table->unsignedBigInteger('amount_rials');
            $table->timestamp('settled_at');
        });

        Schema::create('sales_payment_review_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_claim_id')->constrained('sales_bank_payment_claims')->restrictOnDelete();
            $table->foreignId('reviewer_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 16);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payment_review_audits');
        Schema::dropIfExists('sales_bank_settlements');
        Schema::dropIfExists('sales_bank_payment_claims');
    }
};
