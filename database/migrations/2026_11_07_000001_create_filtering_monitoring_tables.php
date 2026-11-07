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
        Schema::create('filtering_checks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('node_uuid');
            $table->string('module', 16);
            $table->string('outcome', 16);
            $table->timestamp('observed_at');
            $table->timestamp('provider_observed_at')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamp('created_at');

            $table->unique(['node_uuid', 'module', 'observed_at'], 'flt_check_identity');
            $table->index(['observed_at', 'id'], 'flt_check_retention');
        });

        Schema::create('filtering_incidents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('node_uuid');
            // MySQL allows multiple NULLs; a non-NULL UUID is unique while active.
            $table->uuid('active_node_uuid')->nullable()->unique('flt_one_active_per_node');
            $table->string('state', 16);
            $table->json('affected_modules');
            $table->timestamp('opened_at');
            $table->timestamp('last_observed_at');
            $table->timestamp('updated_at');
            $table->timestamp('resolved_at')->nullable();
            // Reserved for the existing Admin notification transport in FLT-02.
            $table->string('last_notified_state', 16)->nullable();
            $table->timestamp('last_notified_at')->nullable();

            $table->index(['node_uuid', 'opened_at'], 'flt_incident_history');
            $table->index(['resolved_at', 'id'], 'flt_incident_retention');
        });
    }

    public function down(): void
    {
        // A deploy rollback may safely drop an unused foundation, not live evidence.
        if ((Schema::hasTable('filtering_checks') && DB::table('filtering_checks')->exists())
            || (Schema::hasTable('filtering_incidents') && DB::table('filtering_incidents')->exists())) {
            throw new RuntimeException('Filtering monitoring data exists; retain evidence and use a reviewed forward fix.');
        }

        Schema::dropIfExists('filtering_incidents');
        Schema::dropIfExists('filtering_checks');
    }
};
