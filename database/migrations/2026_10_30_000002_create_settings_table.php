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
        // Match the Spatie laravel-settings v3 database repository schema.
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group');
            $table->string('name');
            $table->boolean('locked')->default(false);
            $table->json('payload');
            $table->timestamps();
            $table->unique(['group', 'name']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('settings') && DB::table('settings')->exists()) {
            throw new RuntimeException('Settings contain operator data; preserve them and use a forward fix.');
        }

        Schema::dropIfExists('settings');
    }
};
