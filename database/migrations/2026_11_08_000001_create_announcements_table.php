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
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 120);
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['status', 'published_at', 'id'], 'announcements_published_lookup');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('announcements') && DB::table('announcements')->exists()) {
            throw new RuntimeException('Announcement data exists; preserve it and use a reviewed forward fix.');
        }

        Schema::dropIfExists('announcements');
    }
};
