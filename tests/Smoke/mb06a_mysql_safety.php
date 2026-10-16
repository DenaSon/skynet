<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Only the disposable CI database may be modified by this manual migration smoke.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::getDatabaseName() !== 'skynet_mb02_ci' || getenv('MB02_MYSQL_SMOKE') !== '1') {
    throw new RuntimeException('MB-06A migration smoke is restricted to the disposable CI MySQL database.');
}

function mb06Assert(bool $result, string $description): void
{
    if (! $result) {
        throw new RuntimeException($description);
    }
}

$timestamp = now();
$userId = (int) DB::table('users')->insertGetId([
    'name' => 'Synthetic CI user', 'email' => 'mb06a@example.test',
    'password' => 'not-a-live-password', 'is_active' => true,
    'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
$productId = (int) DB::table('sales_products')->insertGetId([
    'slug' => 'mb06a-ci', 'name' => 'Synthetic',
    'is_active' => false, 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
$orderId = (int) DB::table('sales_orders')->insertGetId([
    'buyer_user_id' => $userId, 'idempotency_key' => str_repeat('a', 32),
    'product_slug' => 'mb06a-ci', 'product_name' => 'Synthetic', 'duration_days' => 30,
    'traffic_gb' => 10, 'public_price_rials' => 1000, 'discount_percent' => 0,
    'discount_rials' => 0, 'total_price_rials' => 1000,
    'created_at' => $timestamp, 'updated_at' => $timestamp,
]);

// Model a pre-MB-05A deployment with a real historical receipt row.
$notifications = require dirname(__DIR__, 2).'/database/migrations/2026_10_15_000003_create_telegram_notification_deliveries.php';
$notifications->down();
mb06Assert(! Schema::hasColumn('sales_bank_payment_claims', 'source_bot_key'), 'Legacy claim schema not restored.');

$claimId = (int) DB::table('sales_bank_payment_claims')->insertGetId([
    'sales_order_id' => $orderId, 'buyer_user_id' => $userId,
    'photo_encrypted' => Crypt::encryptString('offline-synthetic-file-id'),
    'photo_fingerprint' => hash('sha256', 'synthetic-photo'),
    'telegram_message_key' => 'tg:mb06a-legacy:10',
    'status' => 'submitted', 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
$notifications->up();

mb06Assert(
    DB::table('sales_bank_payment_claims')->where('id', $claimId)->value('source_bot_key') === 'admin',
    'Legacy claim source bot was not backfilled as Admin.',
);
mb06Assert(
    Crypt::decryptString((string) DB::table('sales_bank_payment_claims')->where('id', $claimId)->value('photo_encrypted'))
        === 'offline-synthetic-file-id',
    'Existing receipt image metadata changed during upgrade.',
);

DB::table('telegram_notification_deliveries')->insert([
    'event_key' => 'mb06a:synthetic:'.$claimId,
    'event_type' => 'ReceiptSubmitted',
    'recipient_user_id' => $userId,
    'bot_key' => 'admin',
    'claim_id' => $claimId,
    'order_id' => $orderId,
    'state' => 'suppressed',
    'attempts' => 0,
    'available_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
$blocked = false;

try {
    $notifications->down();
} catch (RuntimeException) {
    $blocked = true;
}
mb06Assert($blocked, 'Notification evidence was not protected from destructive rollback.');
mb06Assert(DB::table('telegram_notification_deliveries')->count() === 1, 'Notification evidence was lost.');

$planId = (int) DB::table('sales_plans')->insertGetId([
    'product_id' => $productId, 'slug' => 'mb06a-test', 'name' => 'Synthetic plan',
    'kind' => 'hybrid', 'mode' => 'preset', 'audience' => 'public',
    'status' => 'draft', 'traffic_gb' => 10, 'duration_days' => 30,
    'min_gb' => 1, 'max_gb' => 100, 'min_days' => 30, 'max_days' => 30,
    'revision' => 1, 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);

$bindings = require dirname(__DIR__, 2).'/database/migrations/2026_10_16_000001_create_sales_plan_internal_squad_bindings.php';
DB::table('sales_plan_internal_squad_bindings')->insert([
    'plan_id' => $planId, 'internal_squad_uuid' => '11111111-1111-4111-8111-111111111111',
    'plan_revision' => 1, 'verified_at' => null, 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
$blocked = false;

try {
    $bindings->down();
} catch (RuntimeException) {
    $blocked = true;
}
mb06Assert($blocked, 'Plan–Squad evidence was not protected from destructive rollback.');
mb06Assert(DB::table('sales_plan_internal_squad_bindings')->count() === 1, 'Binding contract was lost.');
mb06Assert(DB::table('telegram_updates')->where('bot_key', 'admin')->count() === 2, 'MB-02 historical receipts disappeared.');

echo "MB-06A real MySQL legacy receipt upgrade, protected outbox/binding rollback: PASS\n";
