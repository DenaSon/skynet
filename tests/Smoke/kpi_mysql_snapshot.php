<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use App\Modules\Sales\Application\ReadAdminSalesChannelKpis;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Fixture writes are allowed only on the disposable CI database.
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('KPI_MYSQL_SMOKE') !== '1') {
    throw new RuntimeException('KPI snapshot smoke requires the disposable CI MySQL database.');
}

function kpiAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

kpiAssert(DB::selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level === 'REPEATABLE-READ',
    'KPI read transactions require the existing MySQL REPEATABLE READ isolation.');
Http::preventStrayRequests();
Carbon::setTestNow(Carbon::parse('2020-06-05 15:30:00', 'Asia/Tehran')->utc());
config()->set('super_admin.telegram_ids', ['989601', '989602']);
Role::findOrCreate('member', 'web');
Role::findOrCreate('super-admin', 'web');
$root = User::factory()->create();
$root->syncRoles(['super-admin']);
TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 989601]);

// Separate PDO connections let us commit a writer exactly between report queries,
// without timing sleeps or an outer test transaction hiding the regression.
config()->set('database.connections.kpi_writer', config('database.connections.'.DB::getDefaultConnection()));
$writer = DB::connection('kpi_writer');
kpiAssert($writer->getPdo() !== DB::connection()->getPdo(), 'Writer must use an independent MySQL connection.');
$orders = [];
foreach ([100, 100, 100, 100, 100, 1000, 2000] as $index => $amount) {
    $buyer = User::factory()->create();
    $orderId = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id, 'buyer_category' => $index === 6 ? 'reseller' : 'direct',
        'idempotency_key' => hash('md5', 'ci-kpi-snapshot-'.$index),
        'product_slug' => 'ci-kpi', 'product_name' => 'Synthetic snapshot order',
        'duration_days' => 30, 'traffic_gb' => 10,
        'public_price_rials' => $amount, 'discount_percent' => 0, 'discount_rials' => 0,
        'total_price_rials' => $amount, 'status' => 'fulfilled',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $journalId = (int) DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => 'ci:kpi:snapshot:'.$index, 'created_at' => now(),
    ]);
    $orders[] = ['id' => $orderId, 'buyer' => $buyer->id, 'journal' => $journalId, 'amount' => $amount];
    if ($index < 5) {
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $orderId, 'billing_journal_id' => $journalId,
            'method' => 'wallet', 'amount_rials' => $amount, 'settled_at' => now()->subHour(),
        ]);
    }
}
$lateWallet = $orders[5];
$lateBank = $orders[6];
$claimId = (int) DB::table('sales_bank_payment_claims')->insertGetId([
    'sales_order_id' => $lateBank['id'], 'buyer_user_id' => $lateBank['buyer'],
    'photo_encrypted' => 'ci-fixture', 'photo_fingerprint' => hash('sha256', 'ci-kpi-bank'),
    'telegram_message_key' => 'ci-kpi-bank', 'status' => 'approved',
    'reviewed_by' => $root->id, 'reviewed_at' => now()->subHour(),
    'created_at' => now(), 'updated_at' => now(),
]);
$writer->beginTransaction();
$writer->table('sales_order_settlements')->insert([
    'sales_order_id' => $lateWallet['id'], 'billing_journal_id' => $lateWallet['journal'],
    'method' => 'wallet', 'amount_rials' => $lateWallet['amount'], 'settled_at' => now()->subHour(),
]);
$writer->table('sales_bank_settlements')->insert([
    'sales_order_id' => $lateBank['id'], 'payment_claim_id' => $claimId,
    'billing_journal_id' => $lateBank['journal'], 'verified_by' => $root->id,
    'payment_reference' => 'CIKPISNAPSHOT', 'amount_rials' => $lateBank['amount'],
    'settled_at' => now()->subHour(),
]);
$stage = 'totals';
DB::listen(function (QueryExecuted $query) use ($writer, &$stage): void {
    if ($query->connectionName !== DB::getDefaultConnection()) {
        return;
    }
    if (($stage === 'totals' && str_contains($query->sql, 'as settled_count'))
        || ($stage === 'channels' && str_contains($query->sql, 'as category'))) {
        $stage = 'committed';
        $writer->commit();
    }
});

$reader = app(ReadAdminSalesChannelKpis::class);
$report = $reader->handle($root->id, 'day');
kpiAssert($stage === 'committed', 'Late settlements were not committed between report queries.');
kpiAssert($report['management']['current']['revenueRials'] === 500, 'Total must use the original snapshot.');
kpiAssert($report['management']['current']['transactions'] === 5, 'Order count changed during the report.');
kpiAssert($report['current']['channels']['direct']['revenueRials'] === 500, 'Channels mixed old and new snapshots.');
kpiAssert($report['current']['channels']['reseller']['revenueRials'] === 0, 'Late bank sale leaked into channels.');
kpiAssert($report['current']['topThreeRevenueRials'] === 300, 'Top buyers mixed database snapshots.');
kpiAssert($report['current']['topThreeSharePercent'] === 60.0, 'Concentration used mismatched totals.');
kpiAssert(DB::transactionLevel() === 0, 'Report leaked a transaction.');
$fresh = $reader->handle($root->id, 'day');
kpiAssert($fresh['management']['current']['revenueRials'] === 3500, 'Next report did not see committed sales.');
kpiAssert($fresh['current']['topThreeRevenueRials'] === 3100, 'Next report missed new top buyers.');

// Standalone channel readers must also retain one snapshot if a wallet correction
// commits after grouping but before selecting the top buyers.
$writer->beginTransaction();
$writer->table('sales_orders')->where('id', $lateWallet['id'])->update(['status' => 'refunded']);
$stage = 'channels';
$breakdown = app(ReadAdminBusinessReport::class)->settledChannelBreakdownBetween(
    $root->id, Carbon::now('Asia/Tehran')->startOfDay()->utc(), now(),
);
kpiAssert($stage === 'committed', 'Wallet correction did not commit between breakdown queries.');
kpiAssert(array_sum(array_column($breakdown['channels'], 'revenueRials')) === 3500, 'Breakdown lost its snapshot.');
kpiAssert($breakdown['topThreeRevenueRials'] === 3100, 'Correction leaked into the top-buyer read.');
kpiAssert(DB::transactionLevel() === 0, 'Standalone breakdown leaked a transaction.');
$afterCorrection = $reader->handle($root->id, 'day');
kpiAssert($afterCorrection['management']['current']['revenueRials'] === 2500, 'New report missed the wallet correction.');
kpiAssert($afterCorrection['current']['topThreeRevenueRials'] === 2200, 'New top buyers do not reflect the correction.');

try {
    $reader->handle($root->id, 'invalid');
    throw new RuntimeException('Invalid period was accepted.');
} catch (\Illuminate\Validation\ValidationException) {
    kpiAssert(DB::transactionLevel() === 0, 'Failed read leaked a transaction.');
}
Http::assertNothingSent();
Carbon::setTestNow();
echo "KPI MySQL snapshots: late bank/wallet commits, wallet correction, refresh and rollback passed.\n";
