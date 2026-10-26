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
        Schema::table('trial_claims', function (Blueprint $table): void {
            $table->timestamp('ended_at')->nullable();
            $table->string('end_reason', 16)->nullable();
            $table->timestamp('end_notice_started_at')->nullable();
            $table->timestamp('end_notice_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('trial_claims')->whereNotNull('ended_at')->exists()
            || DB::table('trial_claims')->whereNotNull('end_notice_started_at')->exists()) {
            throw new RuntimeException('Trial completion evidence exists; use a reviewed forward-fix.');
        }
        Schema::table('trial_claims', function (Blueprint $table): void {
            $table->dropColumn(['ended_at', 'end_reason', 'end_notice_started_at', 'end_notice_sent_at']);
        });
    }
};
