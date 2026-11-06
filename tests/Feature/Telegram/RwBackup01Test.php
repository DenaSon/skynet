<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\RwBackupDownloadUrl;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\RwBackupOperations;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('rw_backup.enabled', false);
    config()->set('super_admin.telegram_ids', ['899101', '899102']);
    config()->set('nutgram.token', '12345:backup_test');
    Cache::flush();
});

function backup01Bot(int $id): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Operator'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function backup01User(bool $root, int $id): User
{
    $user = User::factory()->create();
    if ($root) {
        $user->syncRoles(['super-admin']);
    }
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);

    return $user;
}

it('rejects invalid or path-traversing remote archive identifiers', function (): void {
    expect(RwBackupOperations::validId('rw-20261106T141129Z-1234abcd'))->toBeTrue()
        ->and(RwBackupOperations::validId('denagate-rw-20261106T141129Z'))->toBeTrue()
        ->and(RwBackupOperations::validId('../../etc/passwd'))->toBeFalse()
        ->and(RwBackupOperations::validId('rw-20261106T141129Z-1234abcd;id'))->toBeFalse()
        ->and(RwBackupOperations::validId('rw-20261106T141129Z'))->toBeFalse();
});

it('never exposes the download endpoint without a one-time grant', function (): void {
    config()->set('rw_backup.enabled', true);
    $this->get('/internal/rw-backups/'.str_repeat('a', 64))->assertNotFound();
    $this->get('/internal/rw-backups/invalid')->assertNotFound();
});

it('rejects a downloaded token after its user loses Super Admin eligibility', function (): void {
    config()->set('rw_backup.enabled', true);
    $user = backup01User(true, 899101);
    $token = str_repeat('b', 64);
    Cache::put('rwbackup:download:'.hash('sha256', $token), [
        'userId' => $user->id, 'telegramId' => 899101, 'id' => 'denagate-rw-20261106T141129Z',
    ], now()->addMinutes(10));
    $user->forceFill(['is_active' => false])->save();

    $this->get('/internal/rw-backups/'.$token)->assertNotFound();
    expect(Cache::has('rwbackup:download:'.hash('sha256', $token)))->toBeFalse();
});

it('keeps the backup entry under the root control panel and supports old settings callbacks', function (): void {
    backup01User(true, 899101);
    $bot = backup01Bot(899101);

    $bot->hearCallbackQueryData('menu:admin:control')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:welcome:show')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:rwbackup:list:1')->reply()->assertCalled('editMessageText');
});

it('does not contact the backup server for an untrusted user', function (): void {
    config()->set('rw_backup.enabled', true);
    backup01User(false, 899103);
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldNotReceive('all');
    $remote->shouldNotReceive('start');
    $remote->shouldNotReceive('delete');
    app()->instance(RwBackupOperations::class, $remote);

    $bot = backup01Bot(899103);
    $bot->hearCallbackQueryData('admin:rwbackup:list:1')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('admin:rwbackup:prepare')->reply()->assertCalled('answerCallbackQuery');
});

it('lists old and managed backups without making a remote mutation', function (): void {
    config()->set('rw_backup.enabled', true);
    backup01User(true, 899101);
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldReceive('all')->once()->andReturn([
        ['id' => 'denagate-rw-20261106T141129Z', 'state' => 'ready',
            'size' => 129761280, 'timestamp' => 1793973600],
        ['id' => 'rw-20261106T150000Z-1234abcd', 'state' => 'running',
            'size' => 0, 'timestamp' => 1793977200],
    ]);
    $remote->shouldNotReceive('start');
    $remote->shouldNotReceive('delete');
    app()->instance(RwBackupOperations::class, $remote);

    $bot = backup01Bot(899101);
    $bot->hearCallbackQueryData('admin:rwbackup:list:1')->reply()->assertCalled('editMessageText');
});

it('generates backup URLs from the canonical HTTPS origin behind an HTTP proxy', function (): void {
    config()->set('app.url', 'https://backup.denagate.example');

    $originalRequest = app('request');
    try {
        // Runflare terminates TLS before the HTTP request reaches PHP-FPM.
        app('url')->setRequest(Request::create(
            'http://internal.runflare.test/api/telegram/webhook', 'POST'
        ));
        $token = str_repeat('a', 64);
        expect(RwBackupDownloadUrl::forToken($token))
            ->toBe('https://backup.denagate.example/internal/rw-backups/'.$token);
    } finally {
        app('url')->setRequest($originalRequest);
    }
});

it('refuses insecure or malformed public origins for backup links', function (): void {
    $token = str_repeat('a', 64);
    foreach ([
        'http://backup.denagate.example',
        'https://alice:pass@backup.denagate.example',
        'https://backup.denagate.example?token=bad',
        'https://backup.denagate.example/path',
    ] as $origin) {
        config()->set('app.url', $origin);
        expect(fn () => RwBackupDownloadUrl::forToken($token))
            ->toThrow(RuntimeException::class);
    }
});

it('sends the download link from an authorized Telegram backup callback', function (): void {
    config()->set('rw_backup.enabled', true);
    config()->set('app.url', 'https://backup.denagate.example');
    backup01User(true, 899101);

    $id = 'denagate-rw-20261106T141129Z';
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldReceive('info')->once()->with($id)->andReturn([
        'size' => 128993280,
        'sha256' => 'cb89e3e01a0306352411103b195b605d6c4a94f51d56efd46e3280cd37f9489c',
    ]);
    app()->instance(RwBackupOperations::class, $remote);

    $bot = backup01Bot(899101);
    $bot->hearCallbackQueryData('admin:rwbackup:download:'.$id)
        ->reply()->assertCalled('sendMessage');
});

/** @return array<string, mixed> */
function backupUiPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);
    }
    if (is_string($data['rich_message'] ?? null)) {
        $data['rich_message'] = json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('polishes the RTL backup overview without naming the upstream provider or changing callbacks', function (): void {
    config()->set('rw_backup.enabled', true);
    backup01User(true, 899101);

    $id = 'denagate-rw-20261106T141129Z';
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldReceive('all')->once()->andReturn([
        ['id' => $id, 'state' => 'ready', 'size' => 128993280, 'timestamp' => 1793974372],
    ]);
    $remote->shouldNotReceive('start');
    $remote->shouldNotReceive('delete');
    app()->instance(RwBackupOperations::class, $remote);

    backup01Bot(899101)->hearCallbackQueryData('admin:rwbackup:list:1')->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($id): bool {
            $data = backupUiPayload($request);
            $html = (string) ($data['rich_message']['html'] ?? '');
            $buttons = $data['reply_markup']['inline_keyboard'] ?? [];

            return ($data['rich_message']['is_rtl'] ?? false) === true
                && str_contains($html, '<h2>🗄️ مدیریت بک‌آپ</h2>')
                && ! str_contains($html, 'Remnawave')
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($html, 'تاریخ ایجاد')
                && str_contains($html, 'وضعیت')
                && str_contains($html, '<footer>')
                && ($buttons[0][0]['callback_data'] ?? null) === 'admin:rwbackup:prepare'
                && ($buttons[1][0]['callback_data'] ?? null) === 'admin:rwbackup:view:'.$id
                && ($buttons[2][0]['callback_data'] ?? null) === 'admin:rwbackup:list:1'
                && ($buttons[3][0]['callback_data'] ?? null) === 'menu:admin:control';
        }, index: 0);
});

it('keeps archive details compact and only offers destructive actions for ready archives', function (): void {
    config()->set('rw_backup.enabled', true);
    backup01User(true, 899101);

    $id = 'denagate-rw-20261106T141129Z';
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldReceive('all')->twice()->andReturn(
        [['id' => $id, 'state' => 'ready', 'size' => 128993280, 'timestamp' => 1793974372]],
        [['id' => $id, 'state' => 'running', 'size' => 0, 'timestamp' => 1793974372]],
    );
    $remote->shouldNotReceive('delete');
    app()->instance(RwBackupOperations::class, $remote);

    backup01Bot(899101)->hearCallbackQueryData('admin:rwbackup:view:'.$id)->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($id): bool {
            $data = backupUiPayload($request);
            $html = (string) ($data['rich_message']['html'] ?? '');
            $buttons = $data['reply_markup']['inline_keyboard'] ?? [];

            return str_contains($html, '<h2>📦 جزئیات بک‌آپ</h2>')
                && str_contains($html, '<table bordered striped compact>')
                && str_contains($html, $id)
                && ($buttons[0][0]['callback_data'] ?? null) === 'admin:rwbackup:download:'.$id
                && ($buttons[1][0]['callback_data'] ?? null) === 'admin:rwbackup:delete:'.$id
                && ($buttons[2][0]['callback_data'] ?? null) === 'admin:rwbackup:list:1';
        }, index: 0);

    backup01Bot(899101)->hearCallbackQueryData('admin:rwbackup:view:'.$id)->reply()
        ->assertRaw(static function (NutgramRequest $request) use ($id): bool {
            $data = backupUiPayload($request);
            $html = (string) ($data['rich_message']['html'] ?? '');
            $buttons = $data['reply_markup']['inline_keyboard'] ?? [];

            return str_contains($html, 'در حال ایجاد')
                && ($buttons[0][0]['callback_data'] ?? null) === 'admin:rwbackup:view:'.$id
                && ($buttons[1][0]['callback_data'] ?? null) === 'admin:rwbackup:list:1'
                && ! str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'admin:rwbackup:delete:');
        }, index: 0);
});

it('uses formal language in backup confirmation and preserves create confirmation callbacks', function (): void {
    config()->set('rw_backup.enabled', true);
    backup01User(true, 899101);
    $remote = Mockery::mock(RwBackupOperations::class);
    $remote->shouldNotReceive('start');
    app()->instance(RwBackupOperations::class, $remote);

    backup01Bot(899101)->hearCallbackQueryData('admin:rwbackup:prepare')->reply()
        ->assertRaw(static function (NutgramRequest $request): bool {
            $data = backupUiPayload($request);
            $html = (string) ($data['rich_message']['html'] ?? '');
            $buttons = $data['reply_markup']['inline_keyboard'] ?? [];

            return str_contains($html, 'آیا ایجاد بک‌آپ جدید را تأیید می‌کنید؟')
                && ! str_contains($html, 'Remnawave')
                && str_starts_with((string) ($buttons[0][0]['callback_data'] ?? ''), 'admin:rwbackup:apply:')
                && ($buttons[1][0]['callback_data'] ?? null) === 'admin:rwbackup:list:1';
        }, index: 0);
});
