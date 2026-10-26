<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Trial\Application\ReserveFreeTrial;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('TRIAL_MYSQL_SMOKE') !== '1'
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Trial smoke requires disposable CI MySQL and pcntl.');
}
Http::preventStrayRequests();
config()->set('trial.enabled', true);
config()->set('trial.phone_hmac_key', str_repeat('ci-only-key-', 5));
config()->set('trial.daily_limit', 1);
Role::findOrCreate('member', 'web');
$members = [];
foreach ([970001, 970002, 970003] as $telegram) {
    $user = User::factory()->create();
    $user->syncRoles(['member']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegram]);
    $members[] = [$user->id, $telegram];
}
$children = [];
$pipes = [];
DB::disconnect();
foreach ($members as $index => [$userId, $telegram]) {
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    if ($pair === false) {
        throw new RuntimeException('Cannot synchronize trial contenders.');
    }
    $pid = pcntl_fork();
    if ($pid === -1) {
        throw new RuntimeException('Cannot fork trial contender.');
    }
    if ($pid === 0) {
        fclose($pair[0]);
        stream_set_timeout($pair[1], 20);
        fgets($pair[1]);
        try {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            app(ReserveFreeTrial::class)->handle($userId, $telegram, $telegram, '+98912345000'.$index);
            exit(0);
        } catch (ValidationException) {
            exit(2);
        } catch (Throwable) {
            exit(1);
        }
    }
    fclose($pair[1]);
    $children[] = $pid;
    $pipes[] = $pair[0];
}
foreach ($pipes as $pipe) {
    fwrite($pipe, "start\n");
}
$outcomes = [];
foreach ($children as $pid) {
    pcntl_waitpid($pid, $status);
    $outcomes[] = pcntl_wexitstatus($status);
}
sort($outcomes);
if ($outcomes !== [0, 2, 2] || DB::table('trial_claims')->count() !== 1
    || (int) DB::table('trial_admission_days')->sum('reserved_count') !== 1) {
    throw new RuntimeException('Concurrent trial admission exceeded the final available slot.');
}
// Permanent evidence is guarded on rollback, including SQL quota data.
foreach (['2026_10_26_000001_create_trial_claims.php', '2026_10_26_000002_create_trial_admission_days.php'] as $file) {
    $migration = require dirname(__DIR__, 2).'/database/migrations/'.$file;
    $blocked = false;
    try {
        $migration->down();
    } catch (RuntimeException) {
        $blocked = true;
    }
    if (! $blocked) {
        throw new RuntimeException('Rollback erased permanent trial evidence.');
    }
}
Http::assertNothingSent();
echo "MySQL: three overlapping trial admissions, one remaining slot, permanent rollback guards: PASS\n";
