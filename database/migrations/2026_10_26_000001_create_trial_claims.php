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
        Schema::create('trial_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->char('phone_fingerprint', 64)->unique();
            $table->string('state', 24)->default('reserved');
            $table->timestamps();
            $table->index(['created_at', 'state']);
        });
    }

    public function down(): void
    {
        // A free-trial entitlement is permanent anti-abuse evidence.
        if (Schema::hasTable('trial_claims') && DB::table('trial_claims')->exists()) {
            throw new RuntimeException('Trial claims exist: use a reviewed forward-fix, not destructive rollback.');
        }

        Schema::dropIfExists('trial_claims');
    }
};
