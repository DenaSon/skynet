<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminCommandCenter;
use App\Modules\Provisioning\Application\ReadAdminNotificationFailures;
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
    config()->set('super_admin.telegram_ids', ['992201', '992202']);
    config()->set('services.remnawave.url', 'https://denaban-issues.test');
    config()->set('services.remnawave.token', 'fixture');
    Http::preventStrayRequests();
});

function denabanIssuesRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 992201]);

    return $root;
}

function denabanIssuesBot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function denabanIssuesPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function denabanIssuesInsert(int $recipient, int $sequence, string $state = 'unavailable', string $reason = 'not_started'): int
{
    return DB::table('telegram_notification_deliveries')->insertGetId([
        'event_key' => 'denaban-issues-'.$sequence,
        'event_type' => $sequence % 2 === 0 ? 'WalletTopupApproved' : 'SubscriptionActivated',
        'recipient_user_id' => $recipient,
        'bot_key' => 'sales',
        'state' => $state,
        'attempts' => $sequence,
        'last_failure' => $reason,
        'available_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @param array<string,mixed> $payload */
function denabanIssuesRich(array $payload): string
{
    $rich = is_string($payload['rich_message'] ?? null)
        ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($payload['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? null) === true ? (string) ($rich['html'] ?? '') : '';
}

it('shows the review button only with terminal notification issues and hides it when resolved', function (): void {
    $root = denabanIssuesRoot();
    Http::fake(['https://denaban-issues.test/*' => Http::response([], 503)]);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return ! str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    $id = denabanIssuesInsert($root->id, 1, 'unavailable', 'not_started');
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return str_contains($p['text'], 'اعلان‌های ناموفق: 1')
                && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    // A status transition from the real dispatcher, not the read-only UI.
    DB::table('telegram_notification_deliveries')->where('id', $id)->update(['state' => 'sent']);
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return ! str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);
});

it('shows bounded chronological pages, human reasons and Persian dates without mutating outbox rows', function (): void {
    $root = denabanIssuesRoot();
    for ($i = 1; $i <= 7; $i++) {
        denabanIssuesInsert($root->id, $i, $i % 2 === 0 ? 'dead' : 'unavailable',
            $i % 2 === 0 ? 'transport' : 'stale_subscription');
    }
    denabanIssuesInsert($root->id, 8, 'sent', '');
    Http::fake();
    $before = DB::table('telegram_notification_deliveries')->orderBy('id')->get()->toArray();

    $summary = app(ReadAdminNotificationFailures::class)->handle($root->id, '1');
    expect($summary['total'])->toBe(7)
        ->and($summary['page'])->toBe(1)
        ->and($summary['pages'])->toBe(2)
        ->and(count($summary['items']))->toBe(5)
        ->and($summary['items'][0]['id'])->toBe(7);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:1')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);
            $buttons = json_encode($p['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains(denabanIssuesRich($p), '۷ سابقه') === false
                && str_contains(denabanIssuesRich($p), '7 سابقه · صفحه 1 از 2')
                && str_contains(denabanIssuesRich($p), '#7 · فعال‌شدن اشتراک')
                && str_contains(denabanIssuesRich($p), 'هشدار اشتراک دیگر معتبر نیست')
                && str_contains(denabanIssuesRich($p), 'مشکل ارتباط با تلگرام')
                && ! str_contains(denabanIssuesRich($p), '#2 ·')
                && str_contains(denabanIssuesRich($p), '۱۴۰۵/')
                && str_contains($buttons, 'denaban:notifications:2')
                && ! str_contains(denabanIssuesRich($p), 'fixture');
        }, index: 1);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:2')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);
            $buttons = json_encode($p['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains(denabanIssuesRich($p), '7 سابقه · صفحه 2 از 2')
                && str_contains(denabanIssuesRich($p), '#2 · تأیید شارژ کیف پول')
                && str_contains(denabanIssuesRich($p), '#1 · فعال‌شدن اشتراک')
                && ! str_contains(denabanIssuesRich($p), '#7 ·')
                && str_contains($buttons, 'denaban:notifications:1')
                && str_contains($buttons, 'denaban:show');
        }, index: 1);

    expect(DB::table('telegram_notification_deliveries')->orderBy('id')->get()->toArray())->toEqual($before);
    Http::assertNothingSent();
});

it('rejects group, ordinary, revoked and malformed page requests without leaking rows', function (): void {
    $root = denabanIssuesRoot();
    denabanIssuesInsert($root->id, 1);

    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 992203]);
    Http::fake();

    expect(fn () => app(ReadAdminNotificationFailures::class)->handle($admin->id, '1'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ReadAdminNotificationFailures::class)->handle($root->id, '1001'))
        ->toThrow(ValidationException::class);

    denabanIssuesBot(992203)->hearCallbackQueryData('denaban:notifications:1')->reply()
        ->assertCalled('answerCallbackQuery');
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:bad')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return str_contains($p['text'] ?? '', 'صفحه موردنظر معتبر نیست.')
                && ! str_contains($p['text'] ?? '', 'کاربر #');
        }, index: 1);

    $root->syncRoles(['member']);
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:1')->reply()
        ->assertCalled('answerCallbackQuery');

    $group = denabanIssuesBot(992201);
    $group->setCommonChat(Chat::make(-992201, ChatType::GROUP));
    $group->hearCallbackQueryData('denaban:notifications:1')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('ignores only superseded activation notices while preserving the full delivery history', function (): void {
    $root = denabanIssuesRoot();
    Http::fake(['https://denaban-issues.test/*' => Http::response([], 503)]);

    // Payment approval notices are superseded after the service is fulfilled.
    foreach ([16, 22, 26] as $sequence) {
        $id = denabanIssuesInsert($root->id, $sequence, 'unavailable', 'superseded_by_activation');
        DB::table('telegram_notification_deliveries')->where('id', $id)->update([
            'event_type' => 'PaymentReviewed',
        ]);
    }

    $before = DB::table('telegram_notification_deliveries')->orderBy('id')->get()->toArray();
    $read = app(ReadAdminNotificationFailures::class);
    $snapshot = app(ReadAdminCommandCenter::class)->handle($root->id);
    expect($snapshot['stats']['notificationIssues'])->toBe(0)
        ->and($read->handle($root->id, '1')['total'])->toBe(0)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(3);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanIssuesPayload($request);

            return str_contains($payload['text'], 'اعلان‌های ناموفق: 0')
                && ! str_contains(json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    // A transport failure is still actionable and must match the drill-down.
    $id = denabanIssuesInsert($root->id, 30, 'dead', 'transport');
    $issues = $read->handle($root->id, '1');
    $snapshot = app(ReadAdminCommandCenter::class)->handle($root->id);
    expect($snapshot['stats']['notificationIssues'])->toBe(1)
        ->and($issues['total'])->toBe(1)
        ->and($issues['items'][0]['id'])->toBe($id);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanIssuesPayload($request);

            return str_contains($payload['text'], 'اعلان‌های ناموفق: 1')
                && str_contains(json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:1')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanIssuesPayload($request);

            return str_contains(denabanIssuesRich($payload), '1 سابقه · صفحه 1 از 1')
                && str_contains(denabanIssuesRich($payload), 'مشکل ارتباط با تلگرام')
                && ! str_contains(denabanIssuesRich($payload), 'اعلان با فعال‌شدن سرویس جایگزین شده');
        }, index: 1);

    // Other unavailable failures, including records without a classified reason,
    // must not be dropped by SQL NULL comparison semantics.
    denabanIssuesInsert($root->id, 31, 'unavailable', 'not_started');
    $unknown = denabanIssuesInsert($root->id, 32, 'dead', 'transport');
    DB::table('telegram_notification_deliveries')->where('id', $unknown)->update(['last_failure' => null]);
    $issues = $read->handle($root->id, '1');
    $snapshot = app(ReadAdminCommandCenter::class)->handle($root->id);
    expect($snapshot['stats']['notificationIssues'])->toBe(3)
        ->and($issues['total'])->toBe(3)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(6)
        ->and(DB::table('telegram_notification_deliveries')->where('last_failure', 'superseded_by_activation')->count())->toBe(3);

    // No reads or navigation may mutate previously recorded delivery evidence.
    $preserved = DB::table('telegram_notification_deliveries')->whereIn('event_key', [
        'denaban-issues-16', 'denaban-issues-22', 'denaban-issues-26',
    ])->orderBy('id')->get()->toArray();
    expect($preserved)->toEqual($before);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    Http::assertNotSent(static fn (Request $request): bool => $request->method() !== 'GET');
});

it('suppresses only low-priority delivery noise while retaining all stored evidence', function (): void {
    $root = denabanIssuesRoot();
    Http::fake(['https://denaban-issues.test/*' => Http::response([], 503)]);

    $trial = denabanIssuesInsert($root->id, 101, 'dead', 'transport');
    $expiring = denabanIssuesInsert($root->id, 103, 'unavailable', 'not_started');
    $activation = denabanIssuesInsert($root->id, 105, 'dead', 'transport');
    DB::table('telegram_notification_deliveries')->where('id', $trial)
        ->update(['event_type' => 'TrialExpiring5m']);
    DB::table('telegram_notification_deliveries')->where('id', $expiring)
        ->update(['event_type' => 'SubscriptionLowTraffic']);

    $snapshot = app(ReadAdminCommandCenter::class)->handle($root->id);
    expect($snapshot['stats']['notificationIssues'])->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(3);

    $page = app(ReadAdminNotificationFailures::class)->handle($root->id, '1');
    expect($page['total'])->toBe(1)
        ->and($page['items'][0]['id'])->toBe($activation);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:1')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($activation): bool {
            $html = denabanIssuesRich(denabanIssuesPayload($request));

            return str_contains($html, '#'.$activation.' · فعال‌شدن اشتراک')
                && ! str_contains($html, 'پنج دقیقه پایانی تست')
                && ! str_contains($html, 'هشدار اتمام حجم');
        }, index: 1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
});
