<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_topup_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topup_id')->unique()->constrained('billing_topups')->restrictOnDelete();
            $table->text('file_encrypted');
            $table->char('photo_fingerprint', 64)->unique();
            $table->string('telegram_message_key', 128)->unique();
            $table->string('source_bot_key', 16)->default('sales');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_topup_photos');
    }
};
