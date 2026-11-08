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
        Schema::create('billing_topup_amount_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topup_id')->unique()->constrained('billing_topups')->restrictOnDelete();
            $table->foreignId('billing_journal_id')->unique()->constrained('billing_journals')->restrictOnDelete();
            $table->unsignedBigInteger('verified_amount_rials');
            $table->unsignedBigInteger('adjustment_amount_rials');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->string('reason', 200);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Audited financial corrections may never be silently discarded by rollback.
        if (DB::table('billing_topup_amount_corrections')->exists()) {
            throw new RuntimeException('Cannot drop financial amount-correction audit evidence.');
        }

        Schema::dropIfExists('billing_topup_amount_corrections');
    }
};
