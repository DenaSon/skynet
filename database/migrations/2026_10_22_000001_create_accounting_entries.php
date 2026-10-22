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
        Schema::create('accounting_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 24); // expense | owner_draw
            $table->string('category', 24); // server | domain | fee | other | owner
            $table->unsignedBigInteger('amount_rials');
            $table->string('memo', 200)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 80)->unique();
            $table->foreignId('reversal_of_id')->nullable()->unique()
                ->constrained('accounting_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_entries') && DB::table('accounting_entries')->exists()) {
            throw new RuntimeException('Accounting entries exist; rollback requires a reviewed forward fix.');
        }

        Schema::dropIfExists('accounting_entries');
    }
};
