<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Run only against the disposable MySQL database created by the MB-02 CI job.
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($app->environment('testing') === false
    || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci'
    || getenv('MB02_MYSQL_SMOKE') !== '1') {
    throw new RuntimeException('MB-02 MySQL smoke is restricted to the disposable CI database.');
}

function assertMb02(bool $condition, string $message): void
{
    if ($condition === false) {
        throw new RuntimeException($message);
    }
}

// The full migrations ran beforehand. Recreate the actual pre-MB-02 update
// table to exercise an upgrade of existing receipts, not just an empty install.
Schema::drop('telegram_updates');
Schema::create('telegram_updates', function (Blueprint $table): void {
    $table->unsignedBigInteger('update_id')->primary();
    $table->string('status', 16);
    $table->timestamp('processing_until')->nullable();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
});

$time = now()->startOfSecond();
DB::table('telegram_updates')->insert([
    [
        'update_id' => 101,
        'status' => 'processed',
        'processing_until' => null,
        'processed_at' => $time,
        'created_at' => $time,
        'updated_at' => $time,
    ],
    [
        'update_id' => 102,
        'status' => 'processing',
        'processing_until' => $time->copy()->addMinute(),
        'processed_at' => null,
        'created_at' => $time,
        'updated_at' => $time,
    ],
]);

$migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_000001_scope_telegram_updates_by_bot.php';
$migration->up();

assertMb02(
    DB::table('telegram_updates')->where('bot_key', 'admin')->count() === 2,
    'Historical Admin receipt rows were not preserved.',
);
assertMb02(
    DB::table('telegram_updates')->where('bot_key', 'admin')->where('update_id', 102)->value('status') === 'processing',
    'An in-flight Admin receipt was altered.',
);
assertMb02(
    DB::table('telegram_updates')->where('bot_key', 'admin')->where('update_id', 101)->value('status') === 'processed',
    'A completed Admin receipt was altered.',
);

DB::table('telegram_updates')->insert([
    'bot_key' => 'sales',
    'update_id' => 101,
    'status' => 'processed',
    'processed_at' => $time,
    'created_at' => $time,
    'updated_at' => $time,
]);
assertMb02(DB::table('telegram_updates')->count() === 3, 'Bot-scoped key collision.');

$rollbackRejected = false;
try {
    $migration->down();
} catch (RuntimeException) {
    $rollbackRejected = true;
}
assertMb02($rollbackRejected, 'Unsafe rollback with Sales receipts was not rejected.');
assertMb02(DB::table('telegram_updates')->count() === 3, 'Unsafe rollback lost receipts.');

DB::table('telegram_updates')->where('bot_key', 'sales')->delete();
$migration->down();
assertMb02(Schema::hasColumn('telegram_updates', 'bot_key') === false, 'Safe rollback did not restore legacy schema.');
assertMb02(DB::table('telegram_updates')->count() === 2, 'Safe rollback lost Admin receipts.');

// Leave the ephemeral schema in the new state for a final checked upgrade.
$migration->up();
assertMb02(DB::table('telegram_updates')->where('bot_key', 'admin')->count() === 2, 'Repeated upgrade lost data.');

echo "MB-02 real MySQL legacy-data migration and guarded rollback: PASS\n";
