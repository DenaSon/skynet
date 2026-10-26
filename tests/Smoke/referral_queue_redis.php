<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ReferralRewardFixture as Fixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || getenv('REFERRAL_REDIS_SMOKE') !== '1'
    || DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'skynet_mb02_ci'
    || config('database.redis.default.host') !== '127.0.0.1'
    || (string) config('database.redis.default.database') !== '15'
    || config('database.redis.default.url') !== null) {
    throw new RuntimeException('Referral queue smoke requires isolated CI MySQL and local Redis DB 15.');
}
Http::preventStrayRequests();
config()->set('cache.default', 'array');
config()->set('queue.default', 'redis');
config()->set('queue.connections.redis.queue', 'ref02-smoke');
config()->set('database.redis.options.prefix', 'denagate-ref02-ci:');
config()->set('referral', [
    'rewards_enabled' => true, 'reward_tomans' => 30000, 'max_rewards_per_referrer' => 7,
    'rewards_start_at' => now()->subDay()->utc()->format('Y-m-d\TH:i:s\Z'),
]);
$referrer = Fixture::member();
$order = Fixture::order(Fixture::member(), $referrer);
$outbox = app(ReferralRewardOutbox::class);
DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->update($outbox->captureFields());
$before = DB::table('referral_rewards')->count();
for ($attempt = 0; $attempt < 2; $attempt++) {
    if (! $outbox->enqueue($order) || Queue::connection('redis')->size('ref02-smoke') !== 1) {
        throw new RuntimeException('Referral intent was not enqueued on real Redis.');
    }
    if (Artisan::call('queue:work', [
        'connection' => 'redis', '--queue' => 'ref02-smoke', '--once' => true,
        '--tries' => 1, '--timeout' => 30, '--sleep' => 0,
    ]) !== 0) {
        throw new RuntimeException('Referral worker did not finish.');
    }
}
if (Queue::connection('redis')->size('ref02-smoke') !== 0
    || DB::table('failed_jobs')->count() !== 0
    || DB::table('referral_rewards')->count() !== $before + 1
    || app(ViewWallet::class)->handle($referrer->id)->balanceRials !== 300000
    || DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('referral_state') !== 'credited') {
    throw new RuntimeException('Referral worker lost or duplicated financial credit.');
}
Http::assertNothingSent();
echo "PASS: real Redis referral worker, duplicate serialized delivery, one local financial credit, no provider calls.\n";
