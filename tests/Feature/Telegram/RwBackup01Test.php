<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\RwBackupOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;

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

it('shows the entry only through the Super Admin settings flow', function (): void {
    backup01User(true, 899101);
    $bot = backup01Bot(899101);

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
    $bot->hearCallbackQueryData('admin:rwbackup:list:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('admin:rwbackup:prepare')->reply()->assertCalled('editMessageText');
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
