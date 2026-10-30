<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

return new class extends Migration
{
    public function up()
    {
        $connection = (new HealthCheckResultHistoryItem)->getConnectionName();
        $tableName = EloquentHealthResultStore::getHistoryItemInstance()->getTable();

        Schema::connection($connection)->create($tableName, function (Blueprint $table) {
            $table->id();

            $table->string('check_name');
            $table->string('check_label');
            $table->string('status');
            $table->text('notification_message')->nullable();
            $table->string('short_summary')->nullable();
            $table->json('meta');
            $table->timestamp('ended_at');
            $table->uuid('batch');

            $table->timestamps();
        });

        Schema::connection($connection)->table($tableName, function (Blueprint $table) {
            $table->index('created_at');
            $table->index('batch');
        });
    }

    public function down(): void
    {
        $connection = (new HealthCheckResultHistoryItem)->getConnectionName();
        $tableName = EloquentHealthResultStore::getHistoryItemInstance()->getTable();

        // Do not silently delete operational history on a production rollback.
        if (Schema::connection($connection)->hasTable($tableName)) {
            if (Schema::connection($connection)->getConnection()->table($tableName)->exists()) {
                throw new RuntimeException('Health check history exists; disable probes and preserve evidence.');
            }

            Schema::connection($connection)->drop($tableName);
        }
    }
};
