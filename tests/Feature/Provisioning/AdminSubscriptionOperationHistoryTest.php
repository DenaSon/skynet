<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminSubscriptionStatusUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use App\Modules\Provisioning\Jobs\ChangeFulfilledSubscriptionStatusJob;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994201', '994202']);
    config()->set('services.remnawave.url', 'https://rw-audit.test');
    config()->set('services.remnawave.token', 'fake-audit-token');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function adminHistoryBot(int $telegramId, User $actor): Nutgram
{
    TelegramAccount::query()->firstOrCreate(
        ['user_id' => $actor->id], ['telegram_user_id' => $telegramId],
    );
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array{root:User,buyer:User,order:int} */
function adminHistoryFixture(): array
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    adminHistoryBot(994201, $root);
    $buyer = User::factory()->create();

    $product = DB::table('sales_products')->insertGetId([
        'slug' => 'admin-history-product', 'name' => 'Managed',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plan = DB::table('sales_plans')->insertGetId([
        'product_id' => $product, 'slug' => 'admin-history-plan', 'name' => 'Managed',
        'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'public', 'status' => 'active',
        'min_days' => 1, 'max_days' => 365,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $order = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => 'admin-history-'.$buyer->id,
        'plan_id' => $plan,
        'product_slug' => 'admin-history-product', 'product_name' => 'Managed',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => 1000000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 1000000,
        'status' => 'fulfilled', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $order, 'event_key' => 'sales-order:'.$order.':create',
        'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reference = 'sales-order:'.$order;
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'remote_user_id' => 100 + $order,
        'state' => 'active',
        'last_operation_key' => 'sales-order:'.$order.':create',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('provisioning_order_links')->insert([
        'sales_order_id' => $order, 'provisioning_subscription_id' => $subscription,
        'created_at' => now(),
    ]);

    return compact('root', 'buyer', 'order');
}

/** @return array<string, mixed> */
function adminHistoryRemote(int $order, string $status = 'ACTIVE'): array
{
    $reference = 'sales-order:'.$order;

    return [
        'id' => 100 + $order,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'private-short-id',
        'status' => $status,
        'expireAt' => now()->utc()->addDays(12)->format('Y-m-d\TH:i:s\Z'),
        'trafficLimitBytes' => 10 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private.example.test/token',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 3 * 1073741824],
    ];
}

/** @return array<string,mixed> */
function adminHistoryPayload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }
    if (isset($payload['rich_message'])) {
        $rich = is_string($payload['rich_message'])
            ? json_decode($payload['rich_message'], true, flags: JSON_THROW_ON_ERROR)
            : $payload['rich_message'];
        $payload['rich_html'] = ($rich['is_rtl'] ?? false) ? (string) ($rich['html'] ?? '') : '';
        $payload['text'] = str_replace('</td><td>', ': ', $payload['rich_html']);
    }

    return $payload;
}

it('records actor, chosen reason and observed preflight status on a verified Admin status operation', function (): void {
    ['root' => $root, 'order' => $order] = adminHistoryFixture();
    config()->set('services.remnawave.writes_enabled', true);
    $remote = adminHistoryRemote($order);
    Http::fake(static function (Request $request) use (&$remote) {
        if ($request->method() === 'PATCH') {
            $remote['status'] = $request['status'];

            return Http::response(['response' => $remote], 200);
        }

        return Http::response(['response' => $remote], 200);
    });

    $billingBefore = DB::table('billing_journals')->count();
    $key = 'mvp04:'.$order.':'.str_repeat('a', 32);
    app(ManageFulfilledSubscriptionStatus::class)->change($root->id, $order, 'disable', $key, 'customer');

    $operation = DB::table('provisioning_operations')->where('idempotency_key', $key)->first();
    expect($operation)->not->toBeNull()
        ->and($operation->kind)->toBe('disable')
        ->and($operation->actor_user_id)->toBe($root->id)
        ->and($operation->reason_code)->toBe('customer')
        ->and($operation->preflight_status)->toBe('ACTIVE')
        ->and($operation->state)->toBe('completed')
        ->and(DB::table('billing_journals')->count())->toBe($billingBefore);

    $history = app(ManageFulfilledSubscriptionStatus::class)->history($root->id, $order);
    expect($history['items'])->toHaveCount(1)
        ->and($history['items'][0]->reason_code)->toBe('customer');
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['status'] === 'DISABLED');
    Http::assertNotSent(static fn (Request $request): bool => in_array($request->method(), ['POST', 'DELETE'], true));
});

it('pages only the source order Admin operations and shows truthful legacy metadata without contacting RW', function (): void {
    ['root' => $root, 'order' => $order] = adminHistoryFixture();
    $subscription = DB::table('provisioning_subscriptions')->where('reference', 'sales-order:'.$order)->value('id');
    for ($i = 1; $i <= 10; $i++) {
        $disable = $i % 2 === 0;
        DB::table('provisioning_operations')->insert([
            'subscription_id' => $subscription,
            'idempotency_key' => 'mvp04:'.$order.':'.str_pad((string) $i, 32, '0', STR_PAD_LEFT),
            'kind' => $disable ? 'disable' : 'enable',
            'payload_hash' => hash('sha256', 'history-'.$i),
            'expected_payload' => json_encode(['status' => $disable ? 'DISABLED' : 'ACTIVE'], JSON_THROW_ON_ERROR),
            'state' => $i === 10 ? 'needs_review' : 'completed',
            'actor_user_id' => $i === 10 ? $root->id : null,
            'reason_code' => $i === 10 ? 'support' : null,
            'preflight_status' => $i === 10 ? 'ACTIVE' : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('provisioning_operations')->insert([
        'subscription_id' => $subscription,
        'idempotency_key' => 'live:status:'.$order.':'.$root->id.':'.str_repeat('f', 32),
        'kind' => 'disable',
        'payload_hash' => hash('sha256', 'reseller-history'),
        'expected_payload' => '{"status":"DISABLED"}',
        'state' => 'completed',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $reader = app(ManageFulfilledSubscriptionStatus::class);
    $first = $reader->history($root->id, $order, 1);
    $second = $reader->history($root->id, $order, 2);
    expect($first['items'])->toHaveCount(8)
        ->and($first['hasNext'])->toBeTrue()
        ->and($first['items'][0]->state)->toBe('needs_review')
        ->and($first['items'][0]->reason_code)->toBe('support')
        ->and($second['items'])->toHaveCount(2)
        ->and($second['hasNext'])->toBeFalse();

    $admin = User::factory()->admin()->create();
    expect(fn () => $reader->history($admin->id, $order))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();

    adminHistoryBot(994201, $root)->hearCallbackQueryData('full:service:history:'.$order.':1')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $payload = adminHistoryPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);
            $text = (string) $payload['text'];

            return str_contains($text, 'تاریخچه عملیات سرویس #'.$order)
                && str_contains((string) ($payload['rich_html'] ?? ''), '<table bordered striped compact>')
                && str_contains((string) ($payload['rich_html'] ?? ''), '<footer>')
                && str_contains($text, 'نیازمند بررسی')
                && str_contains($text, 'بررسی پشتیبانی')
                && str_contains($text, 'در سوابق قبلی ثبت نشده')
                && str_contains($buttons, 'full:service:history:'.$order.':2')
                && ! str_contains($text, 'private-short-id')
                && ! str_contains($text, 'live:status:');
        }, index: 1);
    Http::assertNothingSent();
});

it('restores and executes a queued legacy status job that has no serialized reason field', function (): void {
    ['root' => $root, 'order' => $order] = adminHistoryFixture();
    config()->set('services.remnawave.writes_enabled', true);
    $remote = adminHistoryRemote($order);
    Http::fake(static function (Request $request) use (&$remote) {
        if ($request->method() === 'PATCH') {
            $remote['status'] = $request['status'];
        }

        return Http::response(['response' => $remote], 200);
    });

    $key = 'mvp04:'.$order.':'.str_repeat('c', 32);
    $oldJob = new ChangeFulfilledSubscriptionStatusJob($order, $root->id, 'disable', $key);
    unset($oldJob->reasonCode);
    $job = unserialize(serialize($oldJob));
    expect($job)->toBeInstanceOf(ChangeFulfilledSubscriptionStatusJob::class)
        ->and($job->reasonCode ?? null)->toBeNull();
    $job->handle(app(ManageFulfilledSubscriptionStatus::class));

    $row = DB::table('provisioning_operations')->where('idempotency_key', $key)->first();
    expect($row)->not->toBeNull()
        ->and($row->state)->toBe('completed')
        ->and($row->actor_user_id)->toBe($root->id)
        ->and($row->reason_code)->toBeNull();
    Http::assertSentCount(2);
});

it('falls back to an identical native history on a Rich transport error', function (): void {
    ['root' => $root, 'order' => $order] = adminHistoryFixture();
    $subscription = DB::table('provisioning_subscriptions')->where('reference', 'sales-order:'.$order)->value('id');
    DB::table('provisioning_operations')->insert([
        'subscription_id' => $subscription,
        'idempotency_key' => 'mvp04:'.$order.':'.str_repeat('b', 32),
        'kind' => 'disable',
        'payload_hash' => hash('sha256', 'fallback'),
        'expected_payload' => '{"status":"DISABLED"}',
        'state' => 'completed',
        'actor_user_id' => $root->id,
        'reason_code' => 'support',
        'preflight_status' => 'ACTIVE',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(994201, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(994201, false, 'Admin'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(static function (...$args): void {
        expect($args[0])->toBeInstanceOf(InputRichMessage::class)
            ->and($args[0]->html)->toContain('تاریخچه عملیات سرویس', '<table bordered striped compact>');
        throw new TelegramException('unsupported Rich message', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(static function (...$args) use ($order): void {
        expect($args[0])->toContain('تاریخچه عملیات سرویس #'.$order, 'بررسی پشتیبانی', 'تأییدشده')
            ->not->toContain('private-short-id', 'https://private.example.test/token');
        expect(json_encode($args[1], JSON_THROW_ON_ERROR))->toContain('full:service:view:'.$order);
    });

    app(AdminSubscriptionStatusUi::class)
        ->history($bot, (string) $order, '1');
    Http::assertNothingSent();
});

it('keeps the status confirmation one-use and records a deliberate reason without executing RW writes during navigation', function (): void {
    ['root' => $root, 'order' => $order] = adminHistoryFixture();
    config()->set('services.remnawave.writes_enabled', true);
    Queue::fake();
    Http::fake(['https://rw-audit.test/api/users/*' => Http::response(['response' => adminHistoryRemote($order)], 200)]);
    $bot = adminHistoryBot(994201, $root);

    $token = null;
    $bot->hearCallbackQueryData('full:service:prepare:'.$order.':disable')->reply()
        ->assertRaw(static function (NutgramRequest $request) use (&$token): bool {
            $payload = adminHistoryPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);
            if (preg_match('/full:service:reason:([a-f0-9]{32}):customer/', $buttons, $matches) !== 1) {
                return false;
            }
            $token = $matches[1];

            return str_contains((string) $payload['text'], 'دلیل')
                && ! str_contains($buttons, 'full:service:apply:');
        }, index: 1);
    expect($token)->not->toBeNull();

    $bot->hearCallbackQueryData('full:service:reason:'.$token.':customer')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($token): bool {
            $payload = adminHistoryPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains((string) $payload['text'], 'تأیید نهایی')
                && str_contains((string) $payload['text'], 'درخواست مشتری')
                && str_contains($buttons, 'full:service:apply:'.$token);
        }, index: 1);

    $bot->hearCallbackQueryData('full:service:apply:'.$token)->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains(
            (string) adminHistoryPayload($request)['text'], 'درخواست ثبت شد',
        ), index: 1);
    Queue::assertPushed(ChangeFulfilledSubscriptionStatusJob::class, static fn (ChangeFulfilledSubscriptionStatusJob $job): bool => $job->reasonCode === 'customer');

    $bot->hearCallbackQueryData('full:service:apply:'.$token)->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains(
            (string) adminHistoryPayload($request)['text'], 'قبلاً استفاده شده',
        ), index: 1);

    expect(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
});

it('keeps local Admin history accessible during RW outages and never reveals it to ordinary admins', function (): void {
    ['root' => $root, 'buyer' => $buyer, 'order' => $order] = adminHistoryFixture();
    Http::fake(['https://rw-audit.test/api/users/*' => Http::response(['message' => 'private-error'], 503)]);

    adminHistoryBot(994201, $root)->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $payload = adminHistoryPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains((string) $payload['text'], 'وضعیت زنده: نامشخص')
                && str_contains($buttons, 'full:service:history:'.$order.':1')
                && ! str_contains((string) $payload['text'], 'private-error');
        }, index: 1);

    $ordinary = User::factory()->admin()->create();
    adminHistoryBot(994203, $ordinary)->hearCallbackQueryData('full:service:history:'.$order.':1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
});
