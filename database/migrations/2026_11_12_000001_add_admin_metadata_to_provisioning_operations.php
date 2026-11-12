<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table): void {
            // Optional: historical operations and non-admin flows remain valid.
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason_code', 24)->nullable();
            $table->string('preflight_status', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('provisioning_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_user_id');
            $table->dropColumn(['reason_code', 'preflight_status']);
        });
    }
};
