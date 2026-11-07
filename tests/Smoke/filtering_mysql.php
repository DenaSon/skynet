<?php

declare(strict_types=1);

use App\Modules\Filtering\Application\FilteringFoundation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('FILTERING_MYSQL_SMOKE') !== '1'
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('FLT-01 MySQL smoke requires disposable CI MySQL and pcntl.');
}
Http::preventStrayRequests();
$migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_07_000001_create_filtering_monitoring_tables.php';
$migration->down();
if (Schema::hasTable('filtering_checks') || Schema::hasTable('filtering_incidents')) {
    throw new RuntimeException('Filtering rollback failed.');
}
$migration->up();

$node = '550e8400-e29b-41d4-a716-446655440099';
$at = now()->subMinutes(2);
DB::disconnect();
$pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
if ($pipes === false) {
    throw new RuntimeException('Cannot synchronize FLT-01 contenders.');
}
foreach ($pipes as $pipe) {
    stream_set_timeout($pipe, 20);
}
$pid = pcntl_fork();
if ($pid < 0) {
    throw new RuntimeException('FLT-01 fork failed.');
}
if ($pid === 0) {
    fclose($pipes[0]);
    try {
        DB::disconnect();
        fwrite($pipes[1], "ready\n");
        if (trim((string) fgets($pipes[1])) !== 'go') {
            throw new RuntimeException('Missing parent signal.');
        }
        app(FilteringFoundation::class)->record($node, 'regional', 'suspicious', $at, [
            'causes' => ['regional_possible'], 'failed_probes' => 2, 'completed_probes' => 4,
        ]);
        fwrite($pipes[1], "done\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite($pipes[1], 'error: '.$error->getMessage()."\n");
        exit(1);
    }
}
fclose($pipes[1]);
if (trim((string) fgets($pipes[0])) !== 'ready') {
    throw new RuntimeException('Child was not ready.');
}
fwrite($pipes[0], "go\n");
app(FilteringFoundation::class)->record($node, 'node', 'disrupted', $at, ['causes' => ['online_zero']]);
pcntl_waitpid($pid, $status);
$childResult = trim((string) fgets($pipes[0]));
fclose($pipes[0]);
if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || $childResult !== 'done') {
    throw new RuntimeException('Concurrent FLT-01 contender failed: '.$childResult);
}

$foundation = app(FilteringFoundation::class);
$foundation->record($node, 'node', 'disrupted', $at, ['causes' => ['online_zero']]);
$status = $foundation->read($node);
if ($status['state'] !== 'disrupted' || count($status['affected_modules']) !== 2
    || DB::table('filtering_incidents')->where('active_node_uuid', $node)->count() !== 1
    || DB::table('filtering_checks')->where('node_uuid', $node)->count() !== 2) {
    throw new RuntimeException('Composite concurrent incident/dedup validation failed.');
}
try {
    $migration->down();
    throw new RuntimeException('Evidence rollback unexpectedly succeeded.');
} catch (RuntimeException $error) {
    if (! str_contains($error->getMessage(), 'Filtering monitoring data exists')) {
        throw $error;
    }
}
if (! Schema::hasTable('filtering_incidents')) {
    throw new RuntimeException('Evidence rollback removed active incident.');
}
Http::assertNothingSent();
echo "FLT-01 MySQL: empty rollback, migration, competing opens, unique composite incident, replay and protected rollback: PASS\n";
