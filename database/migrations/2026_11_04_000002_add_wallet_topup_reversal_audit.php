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
        Schema::table('billing_topups', function (Blueprint $table): void {
            $table->string('reversal_reason', 200)->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversal_journal_id')->nullable()->constrained('billing_journals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Do not silently destroy an immutable financial correction's audit trail.
        if (DB::table('billing_topups')->whereNotNull('reversal_journal_id')->exists()
            || DB::table('billing_topups')->whereNotNull('reversed_by')->exists()) {
            throw new RuntimeException('Cannot remove wallet reversal audit evidence.');
        }

        Schema::table('billing_topups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reversal_journal_id');
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reversal_reason', 'reversed_at']);
        });
    }
};
