<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Settings\DenizBusinessSupportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.webhook_secret', 'admin-other-secret');
    config()->set('nutgram.token', '100001:admin-bot');
    config()->set('telegram.sales.token', '100002:sales-bot');
    config()->set('telegram.sales.webhook_secret', 'sales-other-secret');
    config()->set('telegram.denix.token', '100003:denix-bot');
    config()->set('telegram.denix.webhook_secret', 'denix-business-secret');
    config()->set('telegram.denix.enabled', true);
    config()->set('telegram.denix.support_business_user_id', 191010);
    config()->set('denagate_ai.enabled', true);
    config()->set('cache.default', 'database');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);

    Queue::fake();
    Cache::flush();
    Http::preventStrayRequests();
});

function denizBusinessConnection(int $updateId, int $ownerId = 191010, bool $enabled = true, bool $canReply = true): array
{
    return [
        'update_id' => $updateId,
        'business_connection' => [
            'id' => 'trustedBusinessConnection',
            'user' => ['id' => $ownerId, 'is_bot' => false, 'first_name' => 'Support'],
            'user_chat_id' => $ownerId,
            'date' => 1794219000,
            'rights' => ['can_reply' => $canReply],
            'is_enabled' => $enabled,
        ],
    ];
}

function denizBusinessMessage(int $updateId, string $connectionId = 'trustedBusinessConnection'): array
{
    return [
        'update_id' => $updateId,
        'business_message' => [
            'message_id' => $updateId,
            'business_connection_id' => $connectionId,
            'date' => 1794219000,
            'chat' => ['id' => 190011, 'type' => 'private', 'first_name' => 'Customer'],
            'from' => ['id' => 190011, 'is_bot' => false, 'first_name' => 'Customer'],
            'text' => 'سلام، شرایط نمایندگی چیست؟',
        ],
    ];
}

function denizBusinessPost(TestCase $test, array $payload): void
{
    $test->postJson('/api/telegram/ai/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'denix-business-secret',
    ])->assertNoContent();
}

it('seeds a single disabled Business support setting with no other settings mutation', function (): void {
    $settings = app(DenizBusinessSupportSettings::class);

    expect($settings->enabled)->toBeFalse()
        ->and(DenizBusinessSupportSettings::isEnabled())->toBeFalse()
        ->and(DB::table('settings')->where('group', 'deniz_business_support')->count())->toBe(1);
});

it('authenticates Business updates through Deniz webhook and consumes duplicates without AI work', function (): void {
    denizBusinessPost($this, denizBusinessConnection(31001));
    denizBusinessPost($this, denizBusinessConnection(31001));
    denizBusinessPost($this, denizBusinessMessage(31002));

    expect(DB::table('telegram_updates')->where('bot_key', 'ai')->whereIn('update_id', [31001, 31002])->count())->toBe(2)
        ->and(Cache::get('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection'))['owner_id'])->toBe(191010);

    Queue::assertNotPushed(AnswerDenixMessage::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('never accepts forged webhook secrets or an unconfigured Business account', function (): void {
    $this->postJson('/api/telegram/ai/webhook', denizBusinessConnection(31003), [
        'X-Telegram-Bot-Api-Secret-Token' => 'admin-other-secret',
    ])->assertForbidden();
    expect(DB::table('telegram_updates')->count())->toBe(0);

    config()->set('telegram.denix.support_business_user_id', null);
    denizBusinessPost($this, denizBusinessConnection(31004));

    expect(Cache::has('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection')))->toBeFalse();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('never trusts connection updates from other accounts or without reply permissions', function (): void {
    denizBusinessPost($this, denizBusinessConnection(31005, ownerId: 999991));
    expect(Cache::has('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection')))->toBeFalse();

    denizBusinessPost($this, denizBusinessConnection(31006, canReply: false));
    expect(Cache::has('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection')))->toBeFalse();

    denizBusinessPost($this, denizBusinessConnection(31007));
    expect(Cache::has('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection')))->toBeTrue();

    denizBusinessPost($this, denizBusinessConnection(31008, enabled: false));
    expect(Cache::has('denix:business:connection:'.hash('sha256', 'trustedBusinessConnection')))->toBeFalse();
});

it('recognizes only incoming private customer text from the previously validated connection', function (): void {
    Log::spy();
    denizBusinessPost($this, denizBusinessConnection(31009));

    $settings = app(DenizBusinessSupportSettings::class);
    $settings->enabled = true;
    $settings->save();

    $invalid = [
        denizBusinessMessage(31010, 'unknownConnection'),
        array_replace_recursive(denizBusinessMessage(31011), ['business_message' => ['chat' => ['type' => 'supergroup']]]),
        array_replace_recursive(denizBusinessMessage(31012), ['business_message' => ['from' => ['is_bot' => true]]]),
        array_replace_recursive(denizBusinessMessage(31013), ['business_message' => ['sender_chat' => ['id' => 1]]]),
        array_replace_recursive(denizBusinessMessage(31014), ['business_message' => ['text' => str_repeat('x', 2001)]]),
        array_replace_recursive(denizBusinessMessage(31020), ['business_message' => ['sender_business_bot' => ['id' => 100003, 'is_bot' => true]]]),
        array_replace_recursive(denizBusinessMessage(31021), ['business_message' => ['from' => ['id' => 191010]]]),
        array_replace_recursive(denizBusinessMessage(31022), ['business_message' => ['from' => ['id' => 190012]]]),
    ];

    foreach ($invalid as $payload) {
        denizBusinessPost($this, $payload);
    }
    denizBusinessPost($this, denizBusinessMessage(31015));

    Log::shouldHaveReceived('info')->once()->with('Deniz Business message passed intake validation.');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('stays silent for Business edits and deletions, and when the Settings key is missing', function (): void {
    denizBusinessPost($this, denizBusinessConnection(31016));
    $settings = app(DenizBusinessSupportSettings::class);
    $settings->enabled = true;
    $settings->save();

    denizBusinessPost($this, ['update_id' => 31017, 'edited_business_message' => denizBusinessMessage(31017)['business_message']]);
    denizBusinessPost($this, [
        'update_id' => 31018,
        'deleted_business_messages' => [
            'business_connection_id' => 'trustedBusinessConnection',
            'chat' => ['id' => 190011, 'type' => 'private'],
            'message_ids' => [31015],
        ],
    ]);

    DB::table('settings')->where('group', 'deniz_business_support')->where('name', 'enabled')->delete();
    denizBusinessPost($this, denizBusinessMessage(31019));

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
