<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use App\Modules\Provisioning\Application\ReadCustomerLiveServices;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['994201', '994202']);
    config()->set('services.remnawave.url', 'https://rw-live.test');
    config()->set('services.remnawave.token', 'secret-token-never-in-chat');
    config()->set('services.remnawave.writes_enabled', false);
    Http::preventStrayRequests();
});

function c360LiveBot(int $telegramId, User $actor): Nutgram
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

/** @return array<string,mixed> */
function c360LivePayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function c360LiveOrder(User $buyer, int $sequence, string $orderStatus = 'fulfilled', ?string $subStatus = 'active'): int
{
    $orderId = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => 'c360-live-'.$buyer->id.'-'.$sequence,
        'product_slug' => 'live-plan',
        'product_name' => 'Plan <private> '.$sequence,
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => 1000000, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => 1000000,
        'status' => $orderStatus, 'created_at' => now(), 'updated_at' => now(),
    ]);

    if ($subStatus !== null) {
        $reference = 'live-ref-'.$orderId;
        $id = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => $reference,
            'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
            'remote_user_id' => 41 + $orderId,
            'state' => $subStatus,
            'last_operation_key' => 'live-op-'.$orderId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $orderId,
            'provisioning_subscription_id' => $id,
            'created_at' => now(),
        ]);
    }

    return $orderId;
}

/** @return array<string,mixed> */
function c360LiveRemote(int $orderId, string $status = 'ACTIVE'): array
{
    $reference = 'live-ref-'.$orderId;

    return [
        'id' => 41 + $orderId,
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'shortUuid' => 'private-short-id',
        'status' => $status,
        'expireAt' => now()->addDays(12)->utc()->toIso8601String(),
        'trafficLimitBytes' => 10 * 1073741824,
        'trafficLimitStrategy' => 'NO_RESET',
        'subscriptionUrl' => 'https://private.example.test/credential',
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
        'activeInternalSquads' => [],
        'userTraffic' => ['usedTrafficBytes' => 3 * 1073741824],
    ];
}

it('shows a bounded per-owner service directory without any remote calls or disrupting profile navigation', function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    for ($i = 1; $i <= 7; $i++) {
        c360LiveOrder($buyer, $i, 'fulfilled', 'active');
    }
    c360LiveOrder($buyer, 8, 'pending_payment', null);
    c360LiveOrder($other, 9);

    c360LiveBot(994203, $admin)->hearCallbackQueryData("users:profile:{$buyer->id}:3:s")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($buyer): bool {
            $payload = c360LivePayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($buttons, "c360:l:{$buyer->id}:3:s:1")
                && str_contains($buttons, "users:view:{$buyer->id}:3:s");
        }, index: 1);

    c360LiveBot(994203, $admin)->hearCallbackQueryData("c360:l:{$buyer->id}:3:s:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($buyer): bool {
            $payload = c360LivePayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($payload['text'], 'سرویس‌های مشتری')
                && str_contains($payload['text'], 'Plan &lt;private&gt; 7')
                && ! str_contains($payload['text'], 'Plan &lt;private&gt; 1')
                && ! str_contains($payload['text'], 'Plan &lt;private&gt; 8')
                && ! str_contains($payload['text'], 'Plan &lt;private&gt; 9')
                && str_contains($buttons, "c360:l:{$buyer->id}:3:s:2")
                && str_contains($buttons, "users:profile:{$buyer->id}:3:s");
        }, index: 1);

    c360LiveBot(994203, $admin)->hearCallbackQueryData("c360:l:{$buyer->id}:3:s:2")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = c360LivePayload($request);

            return str_contains($payload['text'], 'Plan &lt;private&gt; 1')
                && ! str_contains($payload['text'], 'Plan &lt;private&gt; 7');
        }, index: 1);
    Http::assertNothingSent();
});

it('fetches a single owner-verified provider snapshot and renders credential-free Persian usage and expiry', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create(['name' => 'Customer']);
    $order = c360LiveOrder($buyer, 1);
    c360LiveBot(994201, $root);
    Http::fake(['https://rw-live.test/api/users/42' => Http::response(['response' => c360LiveRemote($order)], 200)]);
    $before = DB::table('provisioning_subscriptions')->first();

    $service = app(ReadCustomerLiveServices::class)->detail($root->id, $buyer->id, $order);
    expect($service['state'])->toBe('available')
        ->and($service['identityMatched'])->toBeTrue()
        ->and($service['remoteStatus'])->toBe('ACTIVE')
        ->and($service['remainingBytes'])->toBe(7 * 1073741824)
        ->and($service['daysLeft'])->toBeGreaterThanOrEqual(11);

    c360LiveBot(994201, $root)->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:2:s:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($buyer, $order): bool {
            $payload = c360LivePayload($request);
            $text = $payload['text'];
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($text, 'Plan &lt;private&gt; 1')
                && str_contains($text, 'تطبیق شناسه سرویس: تأیید شد')
                && str_contains($text, 'حجم کل: 10.00 گیگابایت')
                && str_contains($text, 'مصرف‌شده: 3.00 گیگابایت')
                && str_contains($text, 'باقی‌مانده: 7.00 گیگابایت')
                && str_contains($text, '۱۴۰۵/')
                && str_contains($buttons, "c360:v:{$buyer->id}:{$order}:2:s:1")
                && str_contains($buttons, "c360:l:{$buyer->id}:2:s:1")
                && ! str_contains($text, 'credential')
                && ! str_contains($text, 'secret-token')
                && ! str_contains($text, 'private-short-id')
                && ! str_contains($text, '42');
        }, index: 1);

    expect(DB::table('provisioning_subscriptions')->first())->toEqual($before);
    Http::assertSentCount(2);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://rw-live.test/api/users/42');
});

it('treats expired and exhausted provider status as actual panel data without leaking credentials', function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $order = c360LiveOrder($buyer, 11);
    Http::fake(['https://rw-live.test/api/users/42' => Http::response([
        'response' => [
            ...c360LiveRemote($order, 'LIMITED'),
            'trafficLimitBytes' => 1073741824,
            'userTraffic' => ['usedTrafficBytes' => 2 * 1073741824],
        ],
    ], 200)]);

    $data = app(ReadCustomerLiveServices::class)->detail($admin->id, $buyer->id, $order);
    expect($data['state'])->toBe('available')
        ->and($data['remoteStatus'])->toBe('LIMITED')
        ->and($data['remainingBytes'])->toBe(0);

    c360LiveBot(994203, $admin)->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $text = c360LivePayload($request)['text'];

            return str_contains($text, 'حجم تمام‌شده')
                && str_contains($text, 'باقی‌مانده: 0.00 گیگابایت')
                && str_contains($text, 'برای بررسی مشکل سرویس');
        }, index: 1);
});

it('never sends a provider request for unpaid, non-owned or non-active linked service', function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $paid = c360LiveOrder($buyer, 12);
    $pending = c360LiveOrder($buyer, 13, 'pending_payment', null);
    $review = c360LiveOrder($buyer, 14, 'paid', 'needs_review');
    $foreign = c360LiveOrder($other, 15);

    $reader = app(ReadCustomerLiveServices::class);
    expect(fn () => $reader->detail($admin->id, $buyer->id, $foreign))
        ->toThrow(ValidationException::class);
    expect(fn () => $reader->detail($admin->id, $buyer->id, $pending))
        ->toThrow(ValidationException::class);
    expect($reader->detail($admin->id, $buyer->id, $review)['state'])->toBe('unavailable');

    DB::table('sales_orders')->where('id', $paid)->update(['status' => 'refunded']);
    expect(fn () => $reader->detail($admin->id, $buyer->id, $paid))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('fails closed for RW errors, forged ownership markers, identity mismatches or malformed responses', function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $order = c360LiveOrder($buyer, 16);
    $reader = app(ReadCustomerLiveServices::class);

    $httpStatus = 503;
    $payload = ['message' => 'credential-private'];
    Http::fake(['https://rw-live.test/api/users/42' => static function () use (&$httpStatus, &$payload) {
        return Http::response($payload, $httpStatus);
    }]);

    foreach ([401, 403, 429, 503] as $code) {
        $httpStatus = $code;
        $payload = ['message' => 'credential-private'];
        expect($reader->detail($admin->id, $buyer->id, $order)['state'])->toBe('unavailable');
    }

    foreach ([
        ['description' => 'foreign marker'],
        ['id' => 999],
        ['username' => 'foreign-user'],
        ['subscriptionUrl' => 'malformed://token'],
    ] as $invalid) {
        $httpStatus = 200;
        $payload = ['response' => [...c360LiveRemote($order), ...$invalid]];
        expect($reader->detail($admin->id, $buyer->id, $order)['state'])->toBe('unavailable', 'invalid: '.json_encode($invalid, JSON_THROW_ON_ERROR));
    }

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    c360LiveBot(994203, $admin)->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $text = c360LivePayload($request)['text'];

            return str_contains($text, 'وضعیت زنده: نامشخص')
                && ! str_contains($text, 'credential-private');
        }, index: 1);
});

it('prevents provider GET and private chat disclosure for unauthorized, revoked, forged or group requests', function (): void {
    $admin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $buyer = User::factory()->create();
    $order = c360LiveOrder($buyer, 17);
    $reader = app(ReadCustomerLiveServices::class);

    expect(fn () => $reader->detail($member->id, $buyer->id, $order))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->page($member->id, $buyer->id, 1))->toThrow(AuthorizationException::class)
        ->and(fn () => $reader->page($admin->id, $buyer->id, 1001))->toThrow(ValidationException::class);

    $group = c360LiveBot(994203, $admin);
    $group->setCommonChat(Chat::make(-994203, ChatType::GROUP));
    $group->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()->assertNoReply();

    $admin->syncRoles(['member']);
    c360LiveBot(994203, $admin)->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    c360LiveBot(994201, $root)->hearCallbackQueryData("c360:v:{$buyer->id}:bad:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            return str_contains(c360LivePayload($request)['text'], 'درخواست معتبر نیست.');
        }, index: 1);
    Http::assertNothingSent();
});

it('reauthorizes a changed local owner binding after network GET before publishing the snapshot', function (): void {
    $admin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $order = c360LiveOrder($buyer, 18);

    Http::fake(['https://rw-live.test/api/users/42' => function () use ($order, $other) {
        DB::table('sales_orders')->where('id', $order)->update(['buyer_user_id' => $other->id]);

        return Http::response(['response' => c360LiveRemote($order)], 200);
    }]);

    expect(fn () => app(ReadCustomerLiveServices::class)->detail($admin->id, $buyer->id, $order))
        ->toThrow(AuthorizationException::class);
    Http::assertSentCount(1);
});

it('opens the existing confirmed subscription manager only for a root-owned fulfilled service', function (): void {
    config()->set('services.remnawave.writes_enabled', true);

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $ordinaryAdmin = User::factory()->admin()->create();
    $buyer = User::factory()->create();
    $order = c360LiveOrder($buyer, 101);
    c360LiveBot(994201, $root);

    // Complete the real eligibility tuple required by the existing MVP-04 Action.
    $product = DB::table('sales_products')->insertGetId([
        'slug' => 'c360-managed-product', 'name' => 'Managed',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plan = DB::table('sales_plans')->insertGetId([
        'product_id' => $product, 'slug' => 'c360-managed-plan',
        'name' => 'Managed', 'kind' => 'hybrid', 'mode' => 'preset',
        'audience' => 'public', 'status' => 'active',
        'min_days' => 1, 'max_days' => 365,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_orders')->where('id', $order)->update(['plan_id' => $plan]);
    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $order, 'event_key' => 'sales-order:'.$order.':create',
        'state' => 'completed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $reference = 'sales-order:'.$order;
    DB::table('provisioning_subscriptions')->where('reference', 'live-ref-'.$order)->update([
        'reference' => $reference,
        'remote_username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
    ]);
    $remote = [
        ...c360LiveRemote($order),
        'username' => 'sk_'.substr(hash('sha256', $reference), 0, 24),
        'description' => 'SkyNetwork ref:'.hash('sha256', $reference),
    ];
    Http::fake(['https://rw-live.test/api/users/42' => static function () use (&$remote) {
        return Http::response(['response' => $remote], 200);
    }]);

    app(ManageFulfilledSubscriptionStatus::class)->authorizeView($root->id, $order);
    $snapshot = app(ReadCustomerLiveServices::class)->detail($root->id, $buyer->id, $order);
    expect($snapshot['state'])->toBe('available')
        ->and($snapshot['identityMatched'])->toBeTrue();

    $rootBot = c360LiveBot(994201, $root);
    $rootBot->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:3:r:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $data = c360LivePayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return str_contains($buttons, 'full:service:prepare:'.$order.':disable')
                && str_contains($buttons, '⏸️ غیرفعال‌سازی سرویس')
                && str_contains($buttons, 'c360:l:');
        }, index: 1);

    // Direct button opens the existing second-step confirmation, never writes to RW.
    $rootBot->hearCallbackQueryData('full:service:prepare:'.$order.':disable')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = c360LivePayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains((string) $data['text'], 'تأیید نهایی')
                && str_contains($buttons, 'full:service:apply:');
        }, index: 1);

    $rootBot->hearCallbackQueryData('full:service:view:'.$order)->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($buyer, $order): bool {
            $data = c360LivePayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains((string) $data['text'], '🟢 فعال')
                && str_contains($buttons, 'full:service:prepare:'.$order.':disable')
                && str_contains($buttons, "users:profile:{$buyer->id}:1:a")
                && str_contains($buttons, 'full:view:'.$order);
        }, index: 1);

    $remote['status'] = 'DISABLED';
    $rootBot->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $data = c360LivePayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            return str_contains((string) $data['text'], '⏸️ غیرفعال')
                && str_contains($buttons, 'full:service:prepare:'.$order.':enable')
                && str_contains($buttons, '✅ فعال‌سازی مجدد سرویس')
                && ! str_contains($buttons, 'full:service:prepare:'.$order.':disable');
        }, index: 1);

    // Existing RW write flag must prevent direct action even when status is known.
    config()->set('services.remnawave.writes_enabled', false);
    $rootBot->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $data = c360LivePayload($request);
            $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($buttons, 'full:service:view:'.$order)
                && ! str_contains($buttons, 'full:service:prepare:');
        }, index: 1);

    c360LiveBot(994203, $ordinaryAdmin)
        ->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($order): bool {
            $buttons = json_encode(c360LivePayload($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return ! str_contains($buttons, 'full:service:view:'.$order)
                && ! str_contains($buttons, 'full:service:prepare:'.$order.':');
        }, index: 1);

    // The historic confirmed provider route also re-checks root privileges.
    c360LiveBot(994203, $ordinaryAdmin)
        ->hearCallbackQueryData('full:service:view:'.$order)->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    // A previously fulfilled purchase corrected/refunded later must not be
    // offered as manageable, even if its remote account is still ACTIVE.
    DB::table('sales_orders')->where('id', $order)->update(['status' => 'refunded']);
    $rootBot->hearCallbackQueryData("c360:v:{$buyer->id}:{$order}:1:a:1")->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = c360LivePayload($request);

            return str_contains((string) $data['text'], 'سرویس موردنظر پیدا نشد.');
        }, index: 1);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});
