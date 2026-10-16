<?php

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('shows safe aggregate operational evidence without calling external providers or exposing identifiers', function (): void {
    $user = User::factory()->create();
    config()->set('nutgram.token', 'do-not-print-telegram-token');
    config()->set('services.remnawave.token', 'do-not-print-provider-token');
    config()->set('telegram.sales.enabled', false);
    config()->set('telegram_notifications.enabled', false);
    Http::preventStrayRequests();
    Http::fake();

    DB::table('telegram_updates')->insert([
        ['bot_key' => 'admin', 'update_id' => 80601, 'status' => 'failed',
            'processing_until' => null, 'processed_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ['bot_key' => 'sales', 'update_id' => 80601, 'status' => 'processing',
            'processing_until' => now()->subMinute(), 'processed_at' => null, 'created_at' => now(), 'updated_at' => now()],
    ]);

    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $user->id, 'bot_key' => 'admin', 'chat_id' => 994006,
        'delivery_status' => 'reachable', 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach (['pending', 'dead', 'unavailable', 'suppressed'] as $index => $state) {
        DB::table('telegram_notification_deliveries')->insert([
            'event_key' => 'ops-safe-event-'.$index, 'event_type' => 'PaymentReviewed',
            'recipient_user_id' => $user->id, 'bot_key' => 'admin',
            'state' => $state, 'attempts' => 0, 'available_at' => now()->subMinute(),
            'last_failure' => $state === 'dead' ? 'transport' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(Artisan::call('telegram:ops:status'))->toBe(0);
    $output = Artisan::output();

    expect($output)->toContain('Telegram delivery: disabled')
        ->toContain('Sales Bot runtime: disabled')
        ->toContain('admin webhook update failures: 1')
        ->toContain('sales stale webhook leases: 1')
        ->toContain('admin reachable contacts: 1')
        ->toContain('Notification pending: 1')
        ->toContain('Notification dead: 1')
        ->toContain('Notification unavailable: 1')
        ->toContain('Notification suppressed: 1')
        ->toContain('Notifications due for recovery: 1')
        ->toContain('Queue jobs awaiting worker: 0')
        ->not->toContain('do-not-print', '994006', '80601', 'ops-safe-event', 'transport');

    Http::assertNothingSent();
    expect(DB::table('telegram_notification_deliveries')->count())->toBe(4);
});

it('fails closed on missing migration instead of reporting misleading queue health', function (): void {
    Http::preventStrayRequests();
    Http::fake();

    Schema::drop('telegram_notification_deliveries');

    expect(Artisan::call('telegram:ops:status'))->toBe(1)
        ->and(Artisan::output())->toContain('Schema incomplete: telegram_notification_deliveries')
        ->not->toContain('Queue jobs awaiting worker: 0');

    Http::assertNothingSent();
});
