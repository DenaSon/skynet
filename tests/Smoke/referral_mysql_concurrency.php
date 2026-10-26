<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Referral\Application\GrantReferralReward;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ReferralRewardFixture as Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('REFERRAL_MYSQL_SMOKE') !== '1'
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Referral smoke requires disposable CI MySQL and pcntl.');
}
Http::preventStrayRequests();
config()->set('referral', [
    'rewards_enabled' => true, 'reward_tomans' => 30000,
    'max_rewards_per_referrer' => 1, 'rewards_start_at' => now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z'),
]);
foreach (['foundation', 'worker'] as $mode) {
    foreach ([false, true] as $duplicate) {
        $referrer = Fixture::member();
        $first = Fixture::order(Fixture::member(), $referrer);
        $second = $duplicate ? $first : Fixture::order(Fixture::member(), $referrer);
        if ($mode === 'worker') {
            DB::table('sales_fulfillment_outbox')->whereIn('sales_order_id', [$first, $second])
                ->update(app(ReferralRewardOutbox::class)->captureFields());
        }
        DB::disconnect();
        $pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pipes === false) {
            throw new RuntimeException('Cannot synchronize referral contenders.');
        }
        foreach ($pipes as $pipe) {
            stream_set_timeout($pipe, 20);
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Cannot fork referral contender.');
        }
        if ($pid === 0) {
            fclose($pipes[0]);
            try {
                DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                DB::beginTransaction();
                // Force an old consistent snapshot BEFORE the competing award commits.
                DB::table('referral_rewards')->where('referrer_user_id', $referrer->id)->get();
                fwrite($pipes[1], "snapshot\n");
                if (trim((string) fgets($pipes[1])) !== 'committed') {
                    throw new RuntimeException('Parent award did not commit.');
                }
                if (DB::table('referral_rewards')->where('referrer_user_id', $referrer->id)->count() !== 0) {
                    throw new RuntimeException('Stale-snapshot condition not exercised.');
                }
                $result = $mode === 'foundation' ? app(GrantReferralReward::class)->handle($second) : null;
                if ($mode === 'worker') {
                    app(ReferralRewardOutbox::class)->process($second);
                }
                DB::commit();
                if ($mode === 'foundation' && ($result !== null) !== $duplicate) {
                    throw new RuntimeException('Incorrect concurrent award result.');
                }
                exit(0);
            } catch (Throwable $error) {
                fwrite($pipes[1], $error::class.': '.$error->getMessage()."\n");
                exit(1);
            }
        }
        fclose($pipes[1]);
        if (trim((string) fgets($pipes[0])) !== 'snapshot') {
            throw new RuntimeException('Child snapshot was not established.');
        }
        if ($mode === 'worker') {
            app(ReferralRewardOutbox::class)->process($first);
        } else {
            app(GrantReferralReward::class)->handle($first);
        }
        fwrite($pipes[0], "committed\n");
        pcntl_waitpid($pid, $status);
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Referral contender failed: '.stream_get_contents($pipes[0]));
        }
        fclose($pipes[0]);
        if (DB::table('referral_rewards')->where('referrer_user_id', $referrer->id)->count() !== 1
            || app(ViewWallet::class)->handle($referrer->id)->balanceRials !== 300000) {
            throw new RuntimeException('Referral credit exceeded quota or duplicated.');
        }
        if ($mode === 'worker') {
            $state = DB::table('sales_fulfillment_outbox')->where('sales_order_id', $second)->value('referral_state');
            if ($state !== ($duplicate ? 'credited' : 'suppressed')) {
                throw new RuntimeException('Concurrent worker terminal state is incorrect.');
            }
        }
    }
}
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_26_000005_create_referral_rewards.php';
try {
    $migration->down();
    throw new LogicException('Financial evidence rollback unexpectedly succeeded.');
} catch (RuntimeException $error) {
    if (! str_contains($error->getMessage(), 'Referral rewards exist')) {
        throw $error;
    }
}
Http::assertNothingSent();
echo "MySQL referral rewards: foundation and worker stale snapshot cap, duplicate delivery, balanced single credit and rollback guard: PASS\n";
