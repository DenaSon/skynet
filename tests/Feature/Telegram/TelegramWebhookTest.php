<?php

use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.webhook_secret', 'test_secret-telegram');
});

it('fails closed when no secret is configured', function (): void {
    config()->set('telegram.webhook_secret', null);

    $this->postJson('/api/telegram/webhook', ['update_id' => 101])->assertServiceUnavailable();
    expect(DB::table('telegram_updates')->count())->toBe(0);
});

it('rejects missing and incorrect secrets without consuming updates', function (): void {
    $this->postJson('/api/telegram/webhook', ['update_id' => 102])->assertForbidden();
    $this->postJson('/api/telegram/webhook', ['update_id' => 102], [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
    ])->assertForbidden();

    expect(DB::table('telegram_updates')->count())->toBe(0);
});

it('rejects invalid update identifiers', function (): void {
    foreach ([[], ['update_id' => '1'], ['update_id' => -1]] as $input) {
        $this->postJson('/api/telegram/webhook', $input, [
            'X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram',
        ])->assertUnprocessable();
    }
});

it('processes each verified update once and acknowledges duplicates', function (): void {
    $fake = new class implements ProcessTelegramUpdate
    {
        public int $count = 0;

        public function handle(): void
        {
            $this->count++;
        }
    };

    $this->app->instance(ProcessTelegramUpdate::class, $fake);
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram'];

    $this->postJson('/api/telegram/webhook', ['update_id' => 103], $headers)->assertNoContent();
    $this->postJson('/api/telegram/webhook', ['update_id' => 103], $headers)->assertNoContent();

    expect($fake->count)->toBe(1);
    expect(DB::table('telegram_updates')->where('update_id', 103)->value('status'))->toBe('processed');
});

it('returns a retry status while the same update is already processing', function (): void {
    $guard = app(TelegramUpdateReceipts::class);
    expect($guard->claim(104))->toBe(ClaimState::Claimed);

    $this->postJson('/api/telegram/webhook', ['update_id' => 104], [
        'X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram',
    ])->assertServiceUnavailable()->assertHeader('Retry-After', '5');

    expect($guard->claim(104))->toBe(ClaimState::Busy);
});

it('can retry an update when processing has failed', function (): void {
    $fake = new class implements ProcessTelegramUpdate
    {
        public int $count = 0;

        public function handle(): void
        {
            $this->count++;

            if ($this->count === 1) {
                throw new RuntimeException('simulated failure');
            }
        }
    };
    $this->app->instance(ProcessTelegramUpdate::class, $fake);

    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram'];

    $this->postJson('/api/telegram/webhook', ['update_id' => 105], $headers)->assertInternalServerError();
    expect(DB::table('telegram_updates')->where('update_id', 105)->value('status'))->toBe('failed');

    $this->postJson('/api/telegram/webhook', ['update_id' => 105], $headers)->assertNoContent();
    expect($fake->count)->toBe(2);
});

it('retries expired in-progress leases and prunes old completed receipts', function (): void {
    $guard = app(TelegramUpdateReceipts::class);
    expect($guard->claim(106))->toBe(ClaimState::Claimed);
    $this->travel(91)->seconds();
    expect($guard->claim(106))->toBe(ClaimState::Claimed);

    $guard->processed(106);
    $this->travel(8)->days();

    $this->artisan('telegram:prune-updates')->assertSuccessful();
    expect(DB::table('telegram_updates')->count())->toBe(0);
});
