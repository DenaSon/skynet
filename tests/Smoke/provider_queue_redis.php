<?php

declare(strict_types=1);

use App\Modules\Provisioning\Jobs\ChangeFulfilledSubscriptionStatusJob;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Only synthetic invalid order/actor IDs on disposable CI stores. No live Redis
// flush, queue purge, financial fixtures or provider request is permitted here.
if (! $app->environment('testing') || getenv('PROVIDER_REDIS_SMOKE') !== '1'
    || DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'skynet_mb02_ci'
    || config('database.redis.default.host') !== '127.0.0.1'
    || (string) config('database.redis.default.database') !== '15'
    || config('database.redis.default.url') !== null) {
    throw new RuntimeException('Provider queue smoke requires disposable CI MySQL and local Redis DB 15.');
}

function providerQueueAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

config()->set('cache.default', 'array');
config()->set('queue.default', 'redis');
config()->set('queue.connections.redis.queue', 'patch02-smoke');
config()->set('queue.connections.database.queue', 'patch02-smoke');
config()->set('database.redis.options.prefix', 'denagate-patch02-ci:');
config()->set('services.remnawave.writes_enabled', false);
Http::preventStrayRequests();
Http::fake();

providerQueueAssert(! DB::table('sales_orders')->whereIn('id', [990201, 990202])->exists()
    && ! DB::table('users')->where('id', 990202)->exists(), 'Synthetic IDs are not isolated.');
providerQueueAssert(Queue::connection('redis')->size('patch02-smoke') === 0
    && DB::table('jobs')->count() === 0, 'CI queue stores must start empty.');

FulfillPaidPlanOrderJob::dispatch(990201);
ChangeFulfilledSubscriptionStatusJob::dispatch(990202, 990202, 'disable');
providerQueueAssert(Queue::connection('redis')->size('patch02-smoke') === 2,
    'Both provider job types must enter the configured Redis backend.');
providerQueueAssert(DB::table('jobs')->count() === 0, 'Redis dispatch must not strand jobs in the database.');
Http::assertNothingSent();

for ($number = 0; $number < 2; $number++) {
    providerQueueAssert(Artisan::call('queue:work', [
        'connection' => 'redis', '--queue' => 'patch02-smoke', '--once' => true,
        '--tries' => 1, '--timeout' => 60, '--sleep' => 0,
    ]) === 0, 'Redis worker did not finish successfully.');
}
providerQueueAssert(Queue::connection('redis')->size('patch02-smoke') === 0,
    'Real Redis worker must consume both serialized jobs.');

// A pre-deploy serialized job keeps its original connection and can still be
// safely drained through a database worker. No migration or payload rewrite.
$legacy = new FulfillPaidPlanOrderJob(990201);
$legacy->onConnection('database');
Queue::connection('database')->push($legacy);
providerQueueAssert(DB::table('jobs')->count() === 1, 'Legacy database job was not retained.');
providerQueueAssert(Artisan::call('queue:work', [
    'connection' => 'database', '--queue' => 'patch02-smoke', '--once' => true,
    '--tries' => 1, '--timeout' => 60, '--sleep' => 0,
]) === 0, 'Legacy database worker did not finish successfully.');
providerQueueAssert(DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0,
    'Worker smoke must leave no queued or failed jobs.');
providerQueueAssert(DB::table('billing_journals')->count() === 0
    && DB::table('provisioning_operations')->count() === 0, 'Synthetic queue smoke must not mutate money or provider intent.');
Http::assertNothingSent();
echo "PASS: Redis enqueue/real worker for both provider jobs, legacy database drain, no external calls.\n";
