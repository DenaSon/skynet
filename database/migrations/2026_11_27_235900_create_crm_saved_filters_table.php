<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_saved_filters', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('criteria');
            $table->timestamps();
            $table->unique(['owner_user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_saved_filters');
    }
};
