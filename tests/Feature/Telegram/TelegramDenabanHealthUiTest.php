<?php

declare(strict_types=1);

use App\Modules\Administration\Application\ReadAdminHealth;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['993201', '993202']);
    config()->set('denagate_health.enabled', false);
    Http::preventStrayRequests();
});

function denabanHealthRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 993201]);

    return $root;
}

function denabanHealthBot(int $telegramId = 993201): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function denabanHealthPayload(NutgramRequest $request): array
{
    $payload = FakeNutgram::getActualData($request);
    if (is_string($payload['reply_markup'] ?? null)) {
        $payload['reply_markup'] = json_decode($payload['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $payload;
}

function denabanHealthStore(string $batch, string $name, string $status, string $when): void
{
    DB::table('health_check_result_history_items')->insert([
        'batch' => $batch,
        'check_name' => $name,
        'check_label' => $name,
        'status' => $status,
        'notification_message' => 'sensitive service message',
        'short_summary' => 'private connection information',
        'meta' => json_encode(['secret' => 'hidden-secret-token'], JSON_THROW_ON_ERROR),
        'ended_at' => $when,
        'created_at' => $when,
        'updated_at' => $when,
    ]);
}

it('returns disabled and waiting instead of fabricating green results', function (): void {
    $root = denabanHealthRoot();
    $reader = app(ReadAdminHealth::class);

    expect($reader->handle($root->id)['state'])->toBe('disabled');
    config()->set('denagate_health.enabled', true);
    expect($reader->handle($root->id)['state'])->toBe('waiting');

    denabanHealthBot()->hearCallbackQueryData('denaban:health:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanHealthPayload($request);

            return str_contains($payload['text'], 'در انتظار اولین نتیجه')
                && str_contains(json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR), 'denaban:show')
                && ! str_contains($payload['text'], 'sensitive');
        }, index: 1);
    Http::assertNothingSent();
});

it('shows only the latest complete batch, status summaries and RTL Rich markup', function (): void {
    $root = denabanHealthRoot();
    config()->set('denagate_health.enabled', true);
    $older = '12121212-1212-4121-8121-121212121212';
    $latest = '34343434-3434-4343-8343-343434343434';
    denabanHealthStore($older, 'DatabaseCheck', 'failed', now()->subMinutes(8)->toDateTimeString());

    foreach ([
        'DatabaseCheck' => 'ok',
        'RedisCheck' => 'ok',
        'QueueCheck' => 'failed',
        'ScheduleCheck' => 'warning',
        'UsedDiskSpaceCheck' => 'ok',
    ] as $name => $status) {
        denabanHealthStore($latest, $name, $status, now()->toDateTimeString());
    }

    $snapshot = app(ReadAdminHealth::class)->handle($root->id);
    expect($snapshot['state'])->toBe('failed')
        ->and($snapshot['total'])->toBe(5)
        ->and($snapshot['items'])->toHaveCount(5);

    denabanHealthBot()->hearCallbackQueryData('denaban:health')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanHealthPayload($request);
            $rich = is_string($payload['rich_message'] ?? null)
                ? json_decode($payload['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($payload['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? null) === true
                && str_contains($html, '🩺 دنابان | سلامت سیستم')
                && str_contains($html, 'اختلال شناسایی شد')
                && str_contains($html, 'Queue Worker')
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($buttons, 'denaban:health:simple')
                && str_contains($buttons, 'denaban:show')
                && ! str_contains($html, 'hidden-secret-token')
                && ! str_contains($html, 'sensitive service message')
                && ! str_contains($html, 'private connection information');
        }, index: 1);

    Http::assertNothingSent();
});

it('marks stale and partial batches unknown instead of healthy', function (): void {
    $root = denabanHealthRoot();
    config()->set('denagate_health.enabled', true);
    $staleBatch = '56565656-5656-4565-8565-565656565656';
    foreach (['DatabaseCheck', 'QueueCheck', 'ScheduleCheck', 'UsedDiskSpaceCheck'] as $name) {
        denabanHealthStore($staleBatch, $name, 'ok', now()->subMinutes(6)->toDateTimeString());
    }

    $reader = app(ReadAdminHealth::class);
    expect($reader->handle($root->id)['state'])->toBe('stale');

    $freshBatch = '78787878-7878-4787-8787-787878787878';
    denabanHealthStore($freshBatch, 'DatabaseCheck', 'ok', now()->toDateTimeString());
    expect($reader->handle($root->id)['state'])->toBe('unknown');
});

it('blocks ordinary, revoked and group users and does not execute checks or provider calls', function (): void {
    $root = denabanHealthRoot();
    $ordinary = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $ordinary->id, 'telegram_user_id' => 993203]);
    config()->set('denagate_health.enabled', true);

    expect(fn () => app(ReadAdminHealth::class)->handle($ordinary->id))
        ->toThrow(AuthorizationException::class);
    denabanHealthBot(993203)->hearCallbackQueryData('denaban:health')->reply()
        ->assertCalled('answerCallbackQuery');

    $root->syncRoles(['member']);
    denabanHealthBot()->hearCallbackQueryData('denaban:health:simple')->reply()
        ->assertCalled('answerCallbackQuery');

    $groupBot = denabanHealthBot();
    $groupBot->setCommonChat(Chat::make(-993201, ChatType::GROUP));
    $groupBot->hearCallbackQueryData('denaban:health')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('adds the Health button to the existing Denaban keyboard without changing its metrics', function (): void {
    denabanHealthRoot();
    config()->set('services.remnawave.url', 'https://health-ui.test');
    config()->set('services.remnawave.token', 'fixture');
    Http::fake(['https://health-ui.test/*' => Http::response([], 503)]);

    denabanHealthBot()->hearCallbackQueryData('denaban:simple')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $payload = denabanHealthPayload($request);
            $buttons = json_encode($payload['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($buttons, 'denaban:health')
                && str_contains($buttons, 'denaban:show')
                && str_contains($payload['text'], '🏔️ دنابان | وضعیت دنا');
        }, index: 1);

    Http::assertSent(static fn ($request): bool => $request->method() === 'GET');
});
