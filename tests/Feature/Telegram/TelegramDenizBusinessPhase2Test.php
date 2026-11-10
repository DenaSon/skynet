<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenizBusinessMessage;
use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenizBusinessConnectionGuard;
use App\Interfaces\Telegram\Processing\DenizBusinessMessageSender;
use App\Modules\AI\Application\AskAssistant;
use App\Settings\DenizBusinessSupportSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.webhook_secret', 'admin-secret');
    config()->set('nutgram.token', '100001:admin-token');
    config()->set('telegram.sales.token', '100002:sales-token');
    config()->set('telegram.sales.webhook_secret', 'sales-secret');
    config()->set('telegram.denix.token', '100003:deniz-bot');
    config()->set('telegram.denix.webhook_secret', 'deniz-secret');
    config()->set('telegram.denix.enabled', true);
    config()->set('telegram.denix.support_business_user_id', 191010);
    config()->set('denagate_ai.enabled', true);
    config()->set('denagate_ai.business_replies_enabled', true);
    config()->set('ai.providers.gapgpt.key', 'test-gapgpt-secret');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
    config()->set('cache.default', 'database');
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.retry_after', 90);
    $settings = app(DenizBusinessSupportSettings::class);
    $settings->enabled = true;
    $settings->save();

    Cache::flush();
    Queue::fake();
    Http::preventStrayRequests();
});

function denizP2Payload(int $updateId, int $chatId = 190011, string $text = 'سلام دنیز', string $connectionId = 'connection_A'): array
{
    return [
        'update_id' => $updateId,
        'business_message' => [
            'message_id' => $updateId,
            'business_connection_id' => $connectionId,
            'date' => now()->timestamp,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Customer'],
            'text' => $text,
        ],
    ];
}

function denizP2Post(TestCase $test, array $payload): void
{
    $test->postJson('/api/telegram/ai/webhook', $payload, [
        'X-Telegram-Bot-Api-Secret-Token' => 'deniz-secret',
    ])->assertNoContent();
}

function denizP2Job(int $messageId = 201, int $chatId = 190011, string $kind = 'answer', ?int $timestamp = null): AnswerDenizBusinessMessage
{
    return new AnswerDenizBusinessMessage('connection_A', $chatId, $chatId, $messageId, $timestamp ?? now()->timestamp, 'سلام دنیز', $kind);
}

function denizP2Run(AnswerDenizBusinessMessage $job): void
{
    $job->handle(
        app(DenizBusinessConnectionGuard::class),
        app(DenizBusinessMessageSender::class),
        app(DenixConversationState::class),
        app(AskAssistant::class),
    );
}

function denizP2ProviderResponse(string $text = 'سلام، چطور کمک کنم؟'): array
{
    return [
        'id' => 'fixture-chat',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 13],
    ];
}

/** @param callable(Request):mixed|null $hook */
function denizP2FakeHttp(?callable $hook = null, bool $canReply = true, int $ownerId = 191010): void
{
    Http::fake(static function (Request $request) use ($hook, $canReply, $ownerId) {
        if ($hook !== null) {
            $override = $hook($request);
            if ($override !== null) {
                return $override;
            }
        }

        if (str_ends_with($request->url(), '/getBusinessConnection')) {
            return Http::response([
                'ok' => true,
                'result' => [
                    'id' => 'connection_A',
                    'user' => ['id' => $ownerId, 'is_bot' => false],
                    'rights' => ['can_reply' => $canReply],
                    'is_enabled' => true,
                ],
            ]);
        }

        if (str_ends_with($request->url(), '/sendMessage')) {
            return Http::response(['ok' => true, 'result' => ['message_id' => 888]]);
        }

        if ($request->url() === 'https://api.gapgpt.app/v1/chat/completions') {
            return Http::response(denizP2ProviderResponse());
        }

        return Http::response(['ok' => false], 404);
    });
}

it('keeps responses OFF after deployment unless both root toggle and deployment gate are enabled', function (): void {
    config()->set('denagate_ai.business_replies_enabled', false);
    denizP2Post($this, denizP2Payload(40001));

    config()->set('denagate_ai.business_replies_enabled', true);
    $settings = app(DenizBusinessSupportSettings::class);
    $settings->enabled = false;
    $settings->save();
    denizP2Post($this, denizP2Payload(40002));

    Queue::assertNothingPushed();
    Http::assertNothingSent();

    expect(DB::table('telegram_updates')->where('bot_key', 'ai')->count())->toBe(2);
});

it('enqueues a single private customer message on ai without contacting Telegram or GapGPT in webhook', function (): void {
    denizP2Post($this, denizP2Payload(40003));
    denizP2Post($this, denizP2Payload(40003));

    $redelivered = denizP2Payload(40004);
    $redelivered['business_message']['message_id'] = 40003;
    denizP2Post($this, $redelivered);

    Queue::assertPushed(AnswerDenizBusinessMessage::class, 1);
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->queue === 'ai'
            && $job->chatId === 190011 && $job->customerId === 190011
            && $job->connectionId === 'connection_A' && $job->kind === 'answer'
            && $job->messageDate > 0 && $job->tries === 1);
    Http::assertNothingSent();
});

it('ignores unsupported private messages, bots and stale customer messages before queueing', function (): void {
    $cases = [
        array_replace_recursive(denizP2Payload(40005), ['business_message' => ['chat' => ['type' => 'group']]]),
        array_replace_recursive(denizP2Payload(40006), ['business_message' => ['from' => ['is_bot' => true]]]),
        array_replace_recursive(denizP2Payload(40007), ['business_message' => ['from' => ['id' => 190012]]]),
        array_replace_recursive(denizP2Payload(40008), ['business_message' => ['sender_business_bot' => ['id' => 100003]]]),
        array_replace_recursive(denizP2Payload(40009), ['business_message' => ['date' => now()->subDay()->subMinute()->timestamp]]),
        array_replace_recursive(denizP2Payload(40010), ['business_message' => ['text' => str_repeat('x', 2001)]]),
    ];
    foreach ($cases as $payload) {
        denizP2Post($this, $payload);
    }

    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('bounds customer quota independently of admin and provides at most one hourly free quota notice', function (): void {
    for ($i = 0; $i < 23; $i++) {
        denizP2Post($this, denizP2Payload(40100 + $i));
    }

    Queue::assertPushed(AnswerDenizBusinessMessage::class, 21);
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->kind === 'quota' && $job->retrySeconds > 0);
    expect(Cache::has('denix:user:190011'))->toBeFalse()
        ->and(RateLimiter::attempts('denix:business:quota:user:'.hash('sha256', 'connection_A').':190011'))->toBe(20)
        ->and(RateLimiter::attempts('denix:business:quota:global'))->toBe(20);
    Http::assertNothingSent();
});

it('enforces the 80-per-day customer budget independently of the 20-per-10-minute window', function (): void {
    for ($batch = 0; $batch < 4; $batch++) {
        for ($i = 0; $i < 20; $i++) {
            denizP2Post($this, denizP2Payload(42000 + $batch * 20 + $i, 199996, 'پیام '.$i));
        }

        $this->travel(11)->minutes();
    }

    denizP2Post($this, denizP2Payload(42080, 199996, 'پیام بعدی'));

    Queue::assertPushed(AnswerDenizBusinessMessage::class, 81);
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->messageId === 42080
            && $job->kind === 'quota' && $job->retrySeconds > 0);
    expect(RateLimiter::attempts('denix:business:quota:global'))->toBe(80)
        ->and(config('denagate_ai.user_requests_per_10_minutes'))->toBe(25)
        ->and(config('denagate_ai.group_requests_per_day'))->toBe(250);
    Http::assertNothingSent();
});

it('carries an opaque trace from accepted Business intake into the queued job', function (): void {
    denizP2Post($this, denizP2Payload(42500, 199997, 'متن کاملاً خصوصی'));

    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => is_string($job->traceId)
            && preg_match('/\\A[a-f0-9]{16}\\z/D', $job->traceId) === 1
            && $job->kind === 'answer');
});

it('logs a non-identifying reason after a Business response is successfully delivered', function (): void {
    Log::spy();
    denizP2FakeHttp();
    denizP2Run(new AnswerDenizBusinessMessage(
        'connection_A', 190011, 190011, 42501, now()->timestamp, 'سلام', 'answer', 0, 'trace-test-42501',
    ));

    Log::shouldHaveReceived('info')->with('Deniz Business worker outcome.',
        Mockery::on(static fn (array $context): bool => ($context['trace'] ?? null) === 'trace-test-42501'
            && ($context['kind'] ?? null) === 'answer'
            && ($context['reason'] ?? null) === 'answer_sent'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), '190011')))->once();
});

it('calls getBusinessConnection twice, purchases one answer and sends only from the Business account', function (): void {
    denizP2FakeHttp();
    denizP2Run(denizP2Job());
    denizP2Run(denizP2Job());

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['business_connection_id'] === 'connection_A'
        && $req['chat_id'] === 190011
        && str_contains($req['text'], 'دستیار هوش مصنوعی دناگیت')
        && json_decode($req['reply_parameters'], true)['message_id'] === 201);
    Http::assertSent(fn (Request $req): bool => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $req['model'] === 'gpt-6-luna'
        && str_contains($req['messages'][0]['content'], '@denaGateBot')
        && str_contains($req['messages'][0]['content'], 'گفتگوی خصوصی حساب پشتیبانی')
        && $req['messages'][1]['content'] === 'سلام دنیز');
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toHaveCount(2)
        ->and(app(DenixConversationState::class)->history(-100987654321, null, 190011))->toBeEmpty();
});

it('introduces Deniz once when the first model answer also starts with سلام', function (): void {
    denizP2FakeHttp(static fn (Request $req) => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        ? Http::response(denizP2ProviderResponse('سلام، بفرمایید چطور می‌توانم کمکتون کنم؟'))
        : null);

    denizP2Run(denizP2Job(215));

    $expected = "سلام، من دنیز، دستیار هوش مصنوعی دناگیت هستم. 🌿\n\n"
        .'بفرمایید چطور می‌توانم کمکتون کنم؟';

    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['text'] === $expected
        && substr_count($req['text'], 'سلام') === 1);

    $history = app(DenixConversationState::class)->history(190011, null, 190011);
    expect($history)->toHaveCount(2)
        ->and($history[1]['content'])->toBe($expected);
});

it('preserves an answer with no initial greeting and does not mistake سلامتی for سلام', function (): void {
    denizP2FakeHttp(static fn (Request $req) => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        ? Http::response(denizP2ProviderResponse('سلامتی و امنیت ارتباط برای ما مهم است.'))
        : null);

    denizP2Run(denizP2Job(216));

    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && str_contains($req['text'], "\n\nسلامتی و امنیت ارتباط برای ما مهم است."));
});

it('uses the approved formal quota notice with a human-readable remaining time without calling AI', function (): void {
    denizP2FakeHttp();

    denizP2Run(new AnswerDenizBusinessMessage(
        'connection_A', 190011, 190011, 217, now()->timestamp, 'سلام دنیز', 'quota', 3900,
    ));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['business_connection_id'] === 'connection_A'
        && $req['text'] === "⏳ تعداد پیام‌های مجاز در این بازه زمانی تکمیل شده است.\n\n"
            ."امکان ادامه گفت‌وگو حدود 1 ساعت و 5 دقیقه دیگر فعال می‌شود.\n\n"
            .'از همراهی شما سپاسگزاریم.');
    Http::assertNotSent(fn (Request $req): bool => $req->url() === 'https://api.gapgpt.app/v1/chat/completions');
});

it('renders provider Markdown as safe Telegram HTML in the full Business job', function (): void {
    denizP2FakeHttp(static fn (Request $req) => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        ? Http::response(denizP2ProviderResponse('**نمایندگی دناگیت** و [بات فروش](https://t.me/denaGateBot)'))
        : null);

    denizP2Run(denizP2Job(214));

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['business_connection_id'] === 'connection_A'
        && $req['chat_id'] === 190011
        && $req['parse_mode'] === 'HTML'
        && str_contains($req['text'], '<b>نمایندگی دناگیت</b>')
        && str_contains($req['text'], '<a href="https://t.me/denaGateBot">بات فروش</a>'));

    $history = app(DenixConversationState::class)->history(190011, null, 190011);
    expect($history)->toHaveCount(2)
        ->and($history[1]['content'])->toContain('**نمایندگی دناگیت**');
});

it('isolates histories for two customers and does not reintroduce itself on a continued chat', function (): void {
    denizP2FakeHttp();
    denizP2Run(denizP2Job(202));
    denizP2Run(denizP2Job(203, 190012));
    denizP2Run(denizP2Job(204));

    Http::assertSentCount(12);
    $requests = collect(Http::recorded())->map(fn (array $item): Request => $item[0]);
    $completions = $requests->filter(fn (Request $req): bool => $req->url() === 'https://api.gapgpt.app/v1/chat/completions')->values();

    expect($completions)->toHaveCount(3)
        ->and(count($completions[1]['messages']))->toBe(2)
        ->and(count($completions[2]['messages']))->toBe(4);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && json_decode($req['reply_parameters'], true)['message_id'] === 204
        && ! str_contains($req['text'], 'دستیار هوش مصنوعی دناگیت'));
});

it('blocks untrusted owner, revoked rights and disabled Business connection before any model charge', function (): void {
    denizP2FakeHttp(canReply: false);
    denizP2Run(denizP2Job(205));
    Http::assertSentCount(1);

    denizP2FakeHttp(ownerId: 999999);
    denizP2Run(denizP2Job(206));
    Http::assertSentCount(1);

    config()->set('denagate_ai.business_replies_enabled', false);
    denizP2Run(denizP2Job(207));
    // Replacing Http::fake in this test resets the request recorder.
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $req): bool => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        || str_ends_with($req->url(), '/sendMessage'));
});

it('revalidates rights and operator switch after model inference and suppresses stale replies', function (): void {
    denizP2FakeHttp(static function (Request $request) {
        if ($request->url() === 'https://api.gapgpt.app/v1/chat/completions') {
            $settings = app(DenizBusinessSupportSettings::class);
            $settings->enabled = false;
            $settings->save();

            return Http::response(denizP2ProviderResponse());
        }

        return null;
    });

    denizP2Run(denizP2Job(208));
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage'));
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toBeEmpty();
});

it('suppresses a prepared answer if Telegram revokes can_reply between the two live checks', function (): void {
    $checks = 0;

    denizP2FakeHttp(static function (Request $request) use (&$checks) {
        if (str_ends_with($request->url(), '/getBusinessConnection')) {
            $checks++;
            if ($checks === 2) {
                return Http::response([
                    'ok' => true,
                    'result' => [
                        'id' => 'connection_A',
                        'user' => ['id' => 191010, 'is_bot' => false],
                        'rights' => ['can_reply' => false],
                        'is_enabled' => true,
                    ],
                ]);
            }
        }

        return null;
    });

    denizP2Run(denizP2Job(212));

    expect($checks)->toBe(2);
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toBeEmpty();
});

it('suppresses an AI answer if a human operator responds during model inference', function (): void {
    denizP2FakeHttp(static function (Request $request) {
        if ($request->url() === 'https://api.gapgpt.app/v1/chat/completions') {
            Cache::put('denix:business:handoff:'.hash('sha256', 'connection_A').':190011', true, now()->addDay());

            return Http::response(denizP2ProviderResponse());
        }

        return null;
    });

    denizP2Run(denizP2Job(213));

    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toBeEmpty();
});

it('sends a single safe fallback on provider error and does not store an unconfirmed answer', function (): void {
    denizP2FakeHttp(static fn (Request $req) => $req->url() === 'https://api.gapgpt.app/v1/chat/completions'
        ? Http::response(['error' => ['message' => 'PRIVATE-UPSTREAM-SECRET']], 503)
        : null);

    denizP2Run(denizP2Job(209));
    denizP2Run(denizP2Job(209));

    Http::assertSentCount(4);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && str_contains($req['text'], 'مشکلی پیش')
        && ! str_contains($req['text'], 'PRIVATE-UPSTREAM-SECRET'));
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toBeEmpty();
});

it('never retries a potentially successful but unconfirmed Telegram send', function (): void {
    denizP2FakeHttp(static fn (Request $req) => str_ends_with($req->url(), '/sendMessage')
        ? Http::response(['ok' => false], 500) : null);

    expect(fn () => denizP2Run(denizP2Job(210)))
        ->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');
    denizP2Run(denizP2Job(210));

    Http::assertSentCount(4);
    expect(app(DenixConversationState::class)->history(190011, null, 190011))->toBeEmpty();
});

it('routes a human request without AI and pauses that private conversation until explicit resume', function (): void {
    denizP2FakeHttp();

    denizP2Post($this, denizP2Payload(40200, text: 'پشتیبان انسانی'));
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->kind === 'handoff');
    denizP2Run(new AnswerDenizBusinessMessage('connection_A', 190011, 190011, 40200, now()->timestamp, 'پشتیبان انسانی', 'handoff'));

    expect(Cache::has('denix:business:handoff:'.hash('sha256', 'connection_A').':190011'))->toBeTrue();
    denizP2Post($this, denizP2Payload(40201));
    Queue::assertPushed(AnswerDenizBusinessMessage::class, 1);

    denizP2Post($this, denizP2Payload(40202, text: 'شروع دنیز'));
    Queue::assertPushed(AnswerDenizBusinessMessage::class, 2);
    denizP2Run(new AnswerDenizBusinessMessage('connection_A', 190011, 190011, 40202, now()->timestamp, 'شروع دنیز', 'resume'));

    expect(Cache::has('denix:business:handoff:'.hash('sha256', 'connection_A').':190011'))->toBeFalse();
    Http::assertSentCount(6);
    Http::assertNotSent(fn (Request $req): bool => $req->url() === 'https://api.gapgpt.app/v1/chat/completions');
});

it('pauses Deniz on a human reply from the support account but not on the bot outgoing echo', function (): void {
    $human = denizP2Payload(40203);
    $human['business_message']['from']['id'] = 191010;
    denizP2Post($this, $human);
    expect(Cache::has('denix:business:handoff:'.hash('sha256', 'connection_A').':190011'))->toBeTrue();

    denizP2Post($this, denizP2Payload(40204));
    Queue::assertNothingPushed();

    Cache::forget('denix:business:handoff:'.hash('sha256', 'connection_A').':190011');
    $echo = $human;
    $echo['update_id'] = 40205;
    $echo['business_message']['message_id'] = 40205;
    $echo['business_message']['sender_business_bot'] = ['id' => 100003, 'is_bot' => true];
    denizP2Post($this, $echo);
    expect(Cache::has('denix:business:handoff:'.hash('sha256', 'connection_A').':190011'))->toBeFalse();
});

it('never attempts Business delivery when the 24-hour reply window has expired in the queue', function (): void {
    denizP2FakeHttp();
    denizP2Run(denizP2Job(211, timestamp: now()->subDay()->subMinute()->timestamp));

    Http::assertNothingSent();
});

it('does not blame a first-time customer when shared Business capacity is depleted', function (): void {
    $global = 'denix:business:quota:global';
    for ($i = 0; $i < 200; $i++) {
        RateLimiter::hit($global, 86400);
    }

    denizP2Post($this, denizP2Payload(41101, 199991, 'سلام، اولین پیام من است'));
    denizP2Post($this, denizP2Payload(41102, 199992, 'لطفاً کمک کنید'));

    Queue::assertPushed(AnswerDenizBusinessMessage::class, 2);
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->chatId === 199991
            && $job->kind === 'capacity' && $job->retrySeconds > 0);
    expect(RateLimiter::attempts('denix:business:quota:user:'.hash('sha256', 'connection_A').':199991'))->toBe(0)
        ->and(RateLimiter::attempts($global))->toBe(200);

    // Notice cooldown prevents spam but never consumes a personal quota.
    denizP2Post($this, denizP2Payload(41103, 199991, 'باز هم پیام'));
    Queue::assertPushed(AnswerDenizBusinessMessage::class, 2);

    // Human handoff is independent of the AI safety budget.
    denizP2Post($this, denizP2Payload(41104, 199991, 'پشتیبان انسانی'));
    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->messageId === 41104 && $job->kind === 'handoff');
    Http::assertNothingSent();
});

it('keeps personal customer-limit notices independent from shared capacity', function (): void {
    for ($i = 0; $i < 20; $i++) {
        denizP2Post($this, denizP2Payload(41200 + $i, 199993, 'پیام '.$i));
    }
    denizP2Post($this, denizP2Payload(41220, 199993, 'پیام بیست‌ویکم'));

    Queue::assertPushed(AnswerDenizBusinessMessage::class,
        fn (AnswerDenizBusinessMessage $job): bool => $job->messageId === 41220
            && $job->kind === 'quota' && $job->retrySeconds > 0);
    expect(RateLimiter::attempts('denix:business:quota:global'))->toBe(20);
    Http::assertNothingSent();
});

it('tells capacity-limited customers the true cause and offers human support without inference', function (): void {
    denizP2FakeHttp();

    denizP2Run(new AnswerDenizBusinessMessage(
        'connection_A', 199994, 199994, 41210, now()->timestamp, 'سلام', 'capacity', 3700,
    ));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['business_connection_id'] === 'connection_A'
        && $req['chat_id'] === 199994
        && str_contains($req['text'], 'ظرفیت پاسخ‌گویی هوشمند دنیز')
        && str_contains($req['text'], 'مربوط به سهمیه شخصی شما نیست')
        && str_contains($req['text'], 'پشتیبان انسانی')
        && ! str_contains($req['text'], 'تعداد پیام‌های مجاز'));
    Http::assertNotSent(fn (Request $req): bool => str_contains($req->url(), 'gapgpt.app'));
});

it('provides read-only incident diagnostics and requires explicit consent to reopen only shared quota', function (): void {
    $global = 'denix:business:quota:global';
    $user = 'denix:business:quota:user:'.hash('sha256', 'connection_A').':199995';

    RateLimiter::hit($global, 86400);
    RateLimiter::hit($user, 600);

    expect(Artisan::call('deniz:business:status'))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('Shared Business usage: 1/200', 'Queue driver: database',
        'Pending ai jobs:', 'Failed Business ai jobs: 0', 'Failed management ai jobs: 0')
        ->not->toContain('connection_A', '199995', 'test-gapgpt-secret');

    expect(Artisan::call('deniz:business:quota:reopen'))->toBe(1)
        ->and(RateLimiter::attempts($global))->toBe(1);

    expect(Artisan::call('deniz:business:quota:reopen', ['--confirm' => true]))->toBe(0)
        ->and(RateLimiter::attempts($global))->toBe(0)
        ->and(RateLimiter::attempts($user))->toBe(1);
    Http::assertNothingSent();
});
