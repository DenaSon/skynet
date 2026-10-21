<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Never run this fixture-writing smoke on an operator/customer database.
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('PAYMENT_MYSQL_SMOKE') !== '1'
    || ! function_exists('pcntl_fork')) {
    throw new RuntimeException('Wallet concurrency smoke requires the disposable CI MySQL database and pcntl.');
}

function paymentAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

Http::preventStrayRequests();
Http::fake();
Queue::fake();
config()->set('sales.wallet_settlement_enabled', true);
Role::findOrCreate('member', 'web');
Role::findOrCreate('reseller', 'web');
$buyer = User::factory()->create(['email' => 'wallet-concurrency@example.test']);
$buyer->syncRoles(['reseller']);
TelegramAccount::query()->create(['user_id' => $buyer->id, 'telegram_user_id' => 979901]);
$walletId = (int) DB::table('billing_wallets')->insertGetId([
    'user_id' => $buyer->id, 'created_at' => now(), 'updated_at' => now(),
]);
$journalId = (int) DB::table('billing_journals')->insertGetId([
    'wallet_id' => $walletId, 'kind' => 'topup',
    'idempotency_key' => 'ci:wallet:concurrency:credit', 'created_at' => now(),
]);
DB::table('billing_postings')->insert([
    ['journal_id' => $journalId, 'wallet_id' => null, 'account_code' => 'cash_clearing', 'side' => 'debit', 'amount_rials' => 1_000_000],
    ['journal_id' => $journalId, 'wallet_id' => $walletId, 'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => 1_000_000],
]);
$orders = [];
for ($number = 1; $number <= 2; $number++) {
    $orders[] = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id, 'idempotency_key' => hash('md5', 'ci-concurrent-order-'.$number),
        'product_slug' => 'ci-wallet', 'product_name' => 'Synthetic concurrency order',
        'duration_days' => 30, 'traffic_gb' => 10,
        'public_price_rials' => 800_000, 'discount_percent' => 0, 'discount_rials' => 0,
        'total_price_rials' => 800_000, 'status' => 'pending_payment',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

// No PDO socket may be inherited across fork. Each process reconnects itself.
DB::disconnect();
$pipes = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
paymentAssert($pipes !== false, 'Cannot create bounded process synchronization channel.');
foreach ($pipes as $pipe) {
    stream_set_timeout($pipe, 20);
}
$pid = pcntl_fork();
paymentAssert($pid !== -1, 'Cannot fork wallet smoke process.');

if ($pid === 0) {
    fclose($pipes[0]);
    try {
        paymentAssert(trim((string) fgets($pipes[1])) === 'locked', 'Parent did not lock wallet.');
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        // Identity reads in SettleWalletOrder establish this same old snapshot.
        DB::table('users')->where('id', $buyer->id)->value('id');
        fwrite($pipes[1], "snapshot\n");
        paymentAssert(trim((string) fgets($pipes[1])) === 'committed', 'First payment did not commit.');

        // Prove the regression condition: plain SUM still sees the old balance
        // despite the first order having paid while this transaction was open.
        $oldBalance = (int) DB::table('billing_postings')->where('wallet_id', $walletId)
            ->where('account_code', 'wallet_liability')->where('side', 'credit')->sum('amount_rials')
            - (int) DB::table('billing_postings')->where('wallet_id', $walletId)
                ->where('account_code', 'wallet_liability')->where('side', 'debit')->sum('amount_rials');
        paymentAssert($oldBalance === 1_000_000, 'REPEATABLE READ regression condition was not exercised.');

        try {
            app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(979901), $orders[1]);
            DB::commit();
            fwrite($pipes[1], "overspent\n");
            exit(1);
        } catch (ValidationException $error) {
            paymentAssert(isset($error->errors()['wallet']), 'Second payment failed for an unrelated reason.');
            DB::rollBack();
            fwrite($pipes[1], "denied\n");
            exit(0);
        }
    } catch (Throwable $error) {
        fwrite($pipes[1], 'error:'.$error::class."\n");
        exit(1);
    }
}

fclose($pipes[1]);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::beginTransaction();
DB::table('billing_wallets')->where('id', $walletId)->lockForUpdate()->first();
fwrite($pipes[0], "locked\n");
paymentAssert(trim((string) fgets($pipes[0])) === 'snapshot', 'Second transaction did not establish its snapshot.');
app(SettleWalletOrder::class)->handle(SubscriptionViewer::telegram(979901), $orders[0]);
DB::commit();
fwrite($pipes[0], "committed\n");
$outcome = trim((string) fgets($pipes[0]));
pcntl_waitpid($pid, $status);
paymentAssert($outcome === 'denied' && pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0,
    'Concurrent stale-snapshot payment was not safely rejected: '.$outcome);
paymentAssert(app(ViewWallet::class)->handle($buyer->id)->balanceRials === 200_000, 'Wallet was overspent.');
paymentAssert(DB::table('sales_order_settlements')->whereIn('sales_order_id', $orders)->count() === 1, 'Expected exactly one settlement.');
paymentAssert(DB::table('sales_fulfillment_outbox')->whereIn('sales_order_id', $orders)->count() === 1, 'Expected exactly one fulfillment intent.');
paymentAssert(DB::table('sales_orders')->where('id', $orders[1])->value('status') === 'pending_payment', 'Rejected order changed.');
Http::assertNothingSent();
echo "MySQL REPEATABLE READ: two overlapping wallet transactions, stale SUM reproduced, only one debit: PASS\n";
