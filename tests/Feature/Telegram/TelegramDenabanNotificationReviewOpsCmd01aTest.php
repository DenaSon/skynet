<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ReadAdminNotificationFailures;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        'event_type' => $sequence % 2 === 0 ? 'WalletTopupApproved' : 'SubscriptionLowTraffic',
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

it('shows the review button only with terminal notification issues and hides it when resolved', function (): void {
    $root = denabanIssuesRoot();
    Http::fake(['https://denaban-issues.test/*' => Http::response([], 503)]);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:show')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return ! str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    $id = denabanIssuesInsert($root->id, 1, 'unavailable', 'not_started');
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:show')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);

            return str_contains($p['text'], 'اعلان‌های ناموفق: 1')
                && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:notifications:1');
        }, index: 1);

    // A status transition from the real dispatcher, not the read-only UI.
    DB::table('telegram_notification_deliveries')->where('id', $id)->update(['state' => 'sent']);
    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:show')->reply()
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

            return str_contains($p['text'], '۷ سابقه') === false
                && str_contains($p['text'], '7 سابقه · صفحه 1 از 2')
                && str_contains($p['text'], '#7 · هشدار اتمام حجم')
                && str_contains($p['text'], 'هشدار اشتراک دیگر معتبر نیست')
                && str_contains($p['text'], 'مشکل ارتباط با تلگرام')
                && ! str_contains($p['text'], '#2 ·')
                && str_contains($p['text'], '۱۴۰۵/')
                && str_contains($buttons, 'denaban:notifications:2')
                && ! str_contains($p['text'], 'fixture');
        }, index: 1);

    denabanIssuesBot(992201)->hearCallbackQueryData('denaban:notifications:2')->reply()
        ->assertRaw(static function (NutgramRequest $r): bool {
            $p = denabanIssuesPayload($r);
            $buttons = json_encode($p['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($p['text'], '7 سابقه · صفحه 2 از 2')
                && str_contains($p['text'], '#2 · تأیید شارژ کیف پول')
                && str_contains($p['text'], '#1 · هشدار اتمام حجم')
                && ! str_contains($p['text'], '#7 ·')
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
