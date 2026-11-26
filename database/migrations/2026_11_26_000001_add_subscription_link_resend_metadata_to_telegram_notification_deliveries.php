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
        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('subscription_delivery_mode', 24)->nullable();
        });
    }

    public function down(): void
    {
        $hasEvidence = DB::table('telegram_notification_deliveries')
            ->where(function ($query): void {
                $query->where('event_type', 'SubscriptionLinkResent')
                    ->orWhereNotNull('requested_by_user_id')
                    ->orWhereNotNull('subscription_delivery_mode');
            })->exists();

        if ($hasEvidence) {
            throw new RuntimeException('Subscription resend audit evidence exists; preserve it before rollback.');
        }

        Schema::table('telegram_notification_deliveries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropColumn('subscription_delivery_mode');
        });
    }
};
