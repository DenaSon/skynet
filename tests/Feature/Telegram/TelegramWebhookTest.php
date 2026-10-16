<?php

use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

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

it('logs only a bounded error category and never the Telegram description or callback token', function (): void {
    $fake = new class implements ProcessTelegramUpdate
    {
        public function handle(): void
        {
            throw new TelegramException('Bad Request: message is not modified; SECRET-DESCRIPTION', 400);
        }
    };
    $this->app->instance(ProcessTelegramUpdate::class, $fake);
    Log::spy();

    $this->postJson('/api/telegram/webhook', [
        'update_id' => 107,
        'callback_query' => ['data' => 'bank:apply:SECRET-CALLBACK'],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram'])->assertInternalServerError();

    Log::shouldHaveReceived('warning')->once()->with(
        'Telegram update processing failed',
        Mockery::on(function (array $context): bool {
            return $context['telegram_error_code'] === 400
                && $context['telegram_error_type'] === 'message_not_modified'
                && $context['callback_area'] === 'other'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SECRET');
        }),
    );
    expect(DB::table('telegram_updates')->where('update_id', 107)->value('status'))->toBe('failed');
});

it('classifies bank-list callback errors without logging raw Telegram API text', function (): void {
    $fake = new class implements ProcessTelegramUpdate
    {
        public function handle(): void
        {
            throw new TelegramException('Bad Request: message identifier is not specified', 400);
        }
    };
    $this->app->instance(ProcessTelegramUpdate::class, $fake);
    Log::spy();

    $this->postJson('/api/telegram/webhook', [
        'update_id' => 108,
        'callback_query' => ['data' => 'bank:list'],
    ], ['X-Telegram-Bot-Api-Secret-Token' => 'test_secret-telegram'])->assertInternalServerError();

    Log::shouldHaveReceived('warning')->once()->with(
        'Telegram update processing failed',
        Mockery::on(fn (array $context): bool => $context['telegram_error_code'] === 400
            && $context['telegram_error_type'] === 'missing_message_identifier'
            && $context['callback_area'] === 'bank_list'
            && ! array_key_exists('message', $context)),
    );
});
