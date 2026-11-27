<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\ProcessSalesTelegramUpdate;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Billing\Application\ReadWalletDailyAverage;
use App\Modules\Identity\Application\ReadCrmAnalytics;
use App\Modules\Identity\Application\ReadCrmDirectory;
use App\Modules\Identity\Application\ReadCrmInteractions;
use App\Modules\Identity\Application\ReadCrmProfile;
use App\Modules\Identity\Application\ReadCrmResellerPerformance;
use App\Modules\Identity\Application\RecordIdentityAudit;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ReadAdminBusinessReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'Asia/Tehran')->utc());
    config()->set('denagate_crm.host', 'crm.denagate.ir');
    config()->set('super_admin.telegram_ids', ['997101', '997102']);
    foreach (['member', 'reseller', 'admin', 'super-admin'] as $role) {
        Role::findOrCreate($role, 'web');
    }
    Http::preventStrayRequests();
});

function crm01bUser(string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->syncRoles([$role]);
    if ($role === 'super-admin') {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 997101]);
    }

    return $user;
}

/** @return array<string, mixed> */
function crm01bFilters(array $overrides = []): array
{
    return array_replace([
        'period' => 'all',
        'search' => '',
        'status' => 'all',
        'telegram' => 'all',
        'purchase' => 'all',
        'phone' => 'all',
        'orderStatus' => 'all',
        'sort' => 'revenue_rials',
        'direction' => 'desc',
        'page' => 1,
    ], $overrides);
}

function crm01bOrder(User $buyer, ?string $category, int $rials, string $status = 'fulfilled', bool $settle = true): int
{
    $order = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => $category,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'crm-fixture',
        'product_name' => 'CRM fixture',
        'duration_days' => 30,
        'traffic_gb' => 20,
        'public_price_rials' => $rials,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => $rials,
        'status' => $status,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    if ($settle) {
        $journal = DB::table('billing_journals')->insertGetId([
            'kind' => 'purchase',
            'idempotency_key' => 'crm-fixture-'.$order,
            'created_at' => now(),
        ]);
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $order,
            'billing_journal_id' => $journal,
            'method' => 'wallet',
            'amount_rials' => $rials,
            'settled_at' => now(),
        ]);
    }

    return $order;
}

it('shows verified cash separately from sales and current ledger balances across CRM date filters', function (): void {
    config()->set('billing.topups_enabled', true);
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $reseller->assignRole('member');
    $credit = app(CreditVerifiedAdminBankTopup::class);
    $topup = $credit->handle($root->id, $member->id, 1_000_000, 'CRMFINMEMBER', 'Fixture verified credit');
    $this->travelTo(Carbon::parse('2026-11-26 23:59:59', 'Asia/Tehran')->utc());
    $credit->handle($root->id, $reseller->id, 2_000_000, 'CRMFINRESELLER', 'Fixture verified credit');
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'Asia/Tehran')->utc());
    $reseller->forceFill(['is_active' => false])->save();

    $wallet = (int) DB::table('billing_wallets')->where('user_id', $member->id)->value('id');
    $correction = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $wallet, 'kind' => 'topup_amount_correction',
        'idempotency_key' => 'topup_amount_correction:'.$topup, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $correction, 'wallet_id' => $wallet, 'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 100_000],
        ['journal_id' => $correction, 'wallet_id' => null, 'account_code' => 'cash_clearing', 'side' => 'credit', 'amount_rials' => 100_000],
    ]);
    DB::table('billing_topup_amount_corrections')->insert([
        'topup_id' => $topup, 'billing_journal_id' => $correction,
        'verified_amount_rials' => 900_000, 'adjustment_amount_rials' => 100_000,
        'corrected_by' => $root->id, 'reason' => 'Fixture correction', 'created_at' => now(),
    ]);
    $order = crm01bOrder($member, 'direct', 300_000);
    $journal = DB::table('sales_order_settlements')->where('sales_order_id', $order)->value('billing_journal_id');
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $wallet, 'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 300_000],
        ['journal_id' => $journal, 'wallet_id' => null, 'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => 300_000],
    ]);
    DB::table('billing_topups')->insert([
        'wallet_id' => $wallet, 'request_key' => 'crm-fin-pending', 'amount_rials' => 500_000,
        'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reader = app(ReadCrmDirectory::class);
    $today = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'today']));
    $yesterday = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'yesterday']));
    $custom = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'custom', 'from' => '2026-11-26', 'until' => '2026-11-27']));
    $legacy = app(ReadAdminCashReceipts::class)->handle($root->id, '7');
    expect($today['cash']['cashInRials'])->toBe(900_000)
        ->and($today['cash']['topupsRials'])->toBe(900_000)
        ->and($today['totalRials'])->toBe(300_000)
        ->and($yesterday['cash']['cashInRials'])->toBe(2_000_000)
        ->and($yesterday['totalRials'])->toBe(0)
        ->and($custom['cash']['cashInRials'])->toBe(2_900_000)
        ->and($custom['cash']['cashInRials'])->toBe($legacy['cashInRials'])
        ->and($custom['totalRials'])->toBe($legacy['salesRials'])
        ->and($today['wallets']['balanceRials'])->toBe(2_600_000)
        ->and($today['wallets']['memberBalanceRials'])->toBe(600_000)
        ->and($today['wallets']['resellerBalanceRials'])->toBe(2_000_000)
        ->and($today['wallets'])->toBe($yesterday['wallets'])
        ->and($today['wallets'])->toBe(app(ReadAdminWalletSummary::class)->handle($root->id));
    $this->actingAs($root, 'web')->get('https://crm.denagate.ir/admin/crm')
        ->assertOk()->assertSee('مجموع واریزی‌های تأییدشده')
        ->assertSee('۲۹۰٬۰۰۰ تومان')->assertSee('۲۶۰٬۰۰۰ تومان')->assertSee('اعتبار نمایندگان');
    expect(fn () => app(ReadAdminCashReceipts::class)->incomingTotalsBetween($member->id, now()->subDay(), now()))
        ->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('separates commercial account cohorts, canonical sales and downstream contact counts', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $reseller->assignRole('member');
    $other = crm01bUser('reseller', ['is_active' => false]);
    $admin = crm01bUser('admin');
    $admin->assignRole('reseller');

    crm01bOrder($member, 'direct', 100_000);
    $first = crm01bOrder($reseller, 'reseller', 200_000);
    $second = crm01bOrder($reseller, 'reseller', 300_000);
    $unknown = crm01bOrder($other, null, 900_000);
    $pending = crm01bOrder($reseller, 'reseller', 800_000, 'pending_payment', false);
    crm01bOrder($reseller, 'reseller', 700_000, 'refunded');
    foreach ([$first, $second, $unknown] as $order) {
        DB::table('sales_service_customer_labels')->insert(['sales_order_id' => $order, 'phone' => '+989121234567', 'label' => 'مشتری']);
    }
    DB::table('sales_service_customer_labels')->insert(['sales_order_id' => $pending, 'phone' => null, 'label' => 'بدون شماره']);

    $reader = app(ReadCrmDirectory::class);
    $dashboard = $reader->handle($root->id, 'dashboard', crm01bFilters());
    expect($dashboard['customers'])->toBe(1)
        ->and($dashboard['resellers'])->toBe(2)
        ->and($dashboard['phones'])->toBe(2)
        ->and($dashboard['nameOnly'])->toBe(1)
        ->and($dashboard['purchasedPhones'])->toBe(2)
        ->and($dashboard['purchasedNameOnly'])->toBe(0)
        ->and($dashboard['totalRials'])->toBe(1_500_000)
        ->and($dashboard['channels']['unknown']['revenueRials'])->toBe(900_000)
        ->and((int) $dashboard['top']->first()->revenue_rials)->toBe(500_000);

    $canonical = app(ReadAdminBusinessReport::class)->settledTotalsBetween($root->id, Carbon::parse('1970-01-01', 'UTC'), Carbon::now('UTC')->addDay());
    expect($dashboard['totalRials'])->toBe($canonical['revenueRials']);

    $members = $reader->handle($root->id, 'customers', crm01bFilters())['rows'];
    expect($members->total())->toBe(1)->and((int) $members->items()[0]->id)->toBe($member->id);
    $rank = $reader->handle($root->id, 'resellers', crm01bFilters())['rows'];
    expect($rank->total())->toBe(2)
        ->and((int) $rank->items()[0]->id)->toBe($reseller->id)
        ->and((int) $rank->items()[1]->revenue_rials)->toBe(0);
    $contacts = $reader->handle($root->id, 'contacts', crm01bFilters(['reseller' => $reseller->id, 'purchase' => 'no']))['rows'];
    expect($contacts->total())->toBe(1)->and((int) $contacts->items()[0]->order_id)->toBe($pending);
    $grouped = $reader->handle($root->id, 'contacts', crm01bFilters(['groupContacts' => true]))['rows'];
    expect($grouped->total())->toBe(3)
        ->and((int) collect($grouped->items())->firstWhere('reseller_id', $reseller->id)->reseller_id)->toBe($reseller->id);
    expect(collect($grouped->items())->filter(fn ($row) => $row->phone === '+989121234567')->count())->toBe(2)
        ->and((int) collect($grouped->items())->max('records_count'))->toBe(2);
    Http::assertNothingSent();
});

it('requires a freshly trusted root for every CRM read and every page', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    foreach (['', '/customers', '/resellers', '/reseller-customers'] as $path) {
        $url = 'https://crm.denagate.ir/admin/crm'.$path;
        $this->actingAs($member, 'web')->get($url)->assertForbidden();
        $this->actingAs($root, 'web')->get($url)->assertOk();
    }

    $root->syncRoles(['reseller']);
    expect(fn () => app(ReadCrmDirectory::class)->handle($root->id, 'dashboard', crm01bFilters()))
        ->toThrow(AuthorizationException::class);
});

it('applies bounded server-side search and pagination to direct accounts', function (): void {
    $root = crm01bUser('super-admin');
    foreach (range(1, 51) as $index) {
        crm01bUser('member', ['name' => 'Fixture '.$index]);
    }
    crm01bUser('member', ['name' => 'Different', 'is_active' => false]);
    $reader = app(ReadCrmDirectory::class);
    $filters = crm01bFilters(['search' => 'Fixture', 'status' => 'active', 'sort' => 'id']);
    $first = $reader->handle($root->id, 'customers', $filters)['rows'];
    $second = $reader->handle($root->id, 'customers', [...$filters, 'page' => 2])['rows'];
    expect($first->total())->toBe(51)
        ->and($first->perPage())->toBe(50)
        ->and($first->count())->toBe(50)
        ->and($first->lastPage())->toBe(2)
        ->and($second->count())->toBe(1)
        ->and(collect($first->items())->pluck('id')->intersect(collect($second->items())->pluck('id'))->isEmpty())->toBeTrue();
    expect($reader->handle($root->id, 'customers', [...$filters, 'search' => 'No matching account'])['rows']->total())->toBe(0);
    expect(fn () => $reader->handle($root->id, 'customers', crm01bFilters(['sort' => 'invalid'])))
        ->toThrow(ValidationException::class);
});

it('rechecks authority on real Livewire requests from all three new directories', function (): void {
    $root = crm01bUser('super-admin');
    $this->actingAs($root, 'web');
    $updateUrl = 'https://crm.denagate.ir'.app(HandleRequests::class)->getUpdateUri();
    foreach (['customers', 'resellers', 'reseller-customers'] as $path) {
        $html = $this->get('https://crm.denagate.ir/admin/crm/'.$path)->assertOk()->getContent() ?: '';
        expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);
        $snapshot = htmlspecialchars_decode($matches[1], ENT_QUOTES);
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['search' => 'Fixture'], 'calls' => []]]];
        $this->postJson($updateUrl, $payload, ['X-Livewire' => 'true'])->assertOk();
        config()->set('super_admin.telegram_ids', ['997102', '997103']);
        $this->postJson($updateUrl, $payload, ['X-Livewire' => 'true'])->assertForbidden();
        config()->set('super_admin.telegram_ids', ['997101', '997102']);
    }
});

/** @return array<string, mixed> */
function crm01cFilters(array $overrides = []): array
{
    return array_replace([
        'tab' => 'overview',
        'period' => 'all',
        'status' => 'all',
        'search' => '',
        'phone' => 'all',
        'purchase' => 'all',
        'groupContacts' => false,
        'page' => 1,
    ], $overrides);
}

it('scopes CRM profiles, canonical settlement history and contacts to their account', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $other = crm01bUser('reseller');
    $order = crm01bOrder($reseller, 'reseller', 200_000);
    $unknown = crm01bOrder($reseller, null, 100_000);
    $pending = crm01bOrder($reseller, 'reseller', 900_000, 'pending_payment', false);
    $foreign = crm01bOrder($other, 'reseller', 500_000);
    foreach ([$order, $pending, $foreign] as $id) {
        DB::table('sales_service_customer_labels')->insert(['sales_order_id' => $id, 'label' => 'Fixture', 'phone' => '+989121234567']);
    }
    $reader = app(ReadCrmProfile::class);
    $overview = $reader->handle($root->id, $reseller->id, true, crm01cFilters());
    expect($overview['summary']['reseller'])->toBe(200_000)
        ->and($overview['summary']['unknown'])->toBe(100_000)
        ->and($overview['summary']['noContact'])->toBe(1)
        ->and($overview['summary']['phones'])->toBe(1)
        ->and($overview['walletState'])->toBe('available');
    $orders = $reader->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => 'orders', 'status' => 'pending_payment']))['rows'];
    expect($orders->total())->toBe(1)->and((int) $orders->items()[0]->id)->toBe($pending);
    $payments = $reader->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => 'payments']))['rows'];
    expect($payments->total())->toBe(2)
        ->and(collect($payments->items())->pluck('order_id')->map(fn ($id) => (int) $id)->all())->toContain($order, $unknown);
    $contacts = $reader->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => 'contacts', 'groupContacts' => true]))['rows'];
    expect($contacts->total())->toBe(1)->and((int) $contacts->items()[0]->records_count)->toBe(2);
    expect(fn () => $reader->handle($root->id, $reseller->id, false, crm01cFilters()))->toThrow(NotFoundHttpException::class);
    expect(fn () => $reader->handle($member->id, $reseller->id, true, crm01cFilters()))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('renders CRM account profiles only for trusted roots and rejects incorrect cohorts', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $base = 'https://crm.denagate.ir/admin/crm';
    $this->actingAs($member, 'web')->get($base.'/customers/'.$member->id)->assertForbidden();
    $this->actingAs($root, 'web')->get($base.'/customers/'.$member->id)->assertOk()->assertSee('پرونده مشتری');
    $this->get($base.'/resellers/'.$reseller->id)->assertOk()->assertSee('پرونده نماینده');
    $this->get($base.'/customers/'.$reseller->id)->assertNotFound();
    $this->get($base.'/resellers/'.$member->id)->assertNotFound();
    $this->get($base.'/customers/'.$root->id)->assertNotFound();
    $this->get($base.'/customers/99999999')->assertNotFound();
    Http::assertNothingSent();
});

it('rejects profile snapshot tampering and rechecks the trusted root on updates', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $page = $this->actingAs($root, 'web')->get('https://crm.denagate.ir/admin/crm/customers/'.$member->id)->assertOk();
    $html = $page->getContent() ?: '';
    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);
    $snapshot = htmlspecialchars_decode($matches[1], ENT_QUOTES);
    $url = 'https://crm.denagate.ir'.app(HandleRequests::class)->getUpdateUri();
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => ['tab' => 'orders'], 'calls' => []]]];
    $this->postJson($url, $payload, ['X-Livewire' => 'true'])->assertOk();
    config()->set('app.debug', false);
    $tampered = json_decode($snapshot, true, flags: JSON_THROW_ON_ERROR);
    $tampered['data']['subjectId'] = $root->id;
    $this->postJson($url, ['components' => [['snapshot' => json_encode($tampered, JSON_THROW_ON_ERROR), 'updates' => [], 'calls' => []]]], ['X-Livewire' => 'true'])->assertStatus(419);
    config()->set('super_admin.telegram_ids', ['997102', '997103']);
    $this->postJson($url, $payload, ['X-Livewire' => 'true'])->assertForbidden();
    Http::assertNothingSent();
});

it('returns only local linked services for the selected customer and never calls the provider', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $other = crm01bUser('member');
    $own = crm01bOrder($member, 'direct', 100_000);
    $foreign = crm01bOrder($other, 'direct', 200_000);
    foreach ([$own, $foreign] as $order) {
        $subscription = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'crm-local-'.$order,
            'remote_username' => 'crm-local-'.$order,
            'state' => 'active',
            'last_operation_key' => 'crm-local-'.$order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $order,
            'provisioning_subscription_id' => $subscription,
        ]);
    }
    $rows = app(ReadCrmProfile::class)->handle($root->id, $member->id, false, crm01cFilters(['tab' => 'services']))['rows'];
    expect($rows->total())->toBe(1)
        ->and((int) $rows->items()[0]->id)->toBe($own)
        ->and($rows->items()[0]->state)->toBe('active')
        ->and((array) $rows->items()[0])->not->toHaveKey('remote_username');
    Http::assertNothingSent();
});

it('renders real profile links for populated directories and dashboard', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    crm01bOrder($reseller, 'reseller', 100_000);
    $base = 'https://crm.denagate.ir/admin/crm';
    $this->actingAs($root, 'web')->get($base.'/customers')->assertOk()->assertSee($base.'/customers/'.$member->id, false);
    $this->get($base.'/resellers')->assertOk()->assertSee($base.'/resellers/'.$reseller->id, false);
    $this->get($base)->assertOk()->assertSee($base.'/resellers/'.$reseller->id, false);
});

it('keeps local services available when the reporting period on another tab is incomplete', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reader = app(ReadCrmProfile::class);
    $filters = crm01cFilters(['period' => 'custom', 'from' => '2026-11-27', 'until' => null]);
    expect(fn () => $reader->handle($root->id, $member->id, false, $filters))->toThrow(ValidationException::class);
    $data = $reader->handle($root->id, $member->id, false, array_replace($filters, ['tab' => 'services']));
    expect($data['rows']->total())->toBe(0);
    Http::assertNothingSent();
});

it('matches canonical bank and wallet purchases and excludes voided claims across Tehran date boundaries', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $bank = crm01bOrder($reseller, 'reseller', 200_000, settle: false);
    $voided = crm01bOrder($reseller, 'reseller', 900_000, settle: false);
    foreach ([$bank, $voided] as $id) {
        $journal = DB::table('billing_journals')->insertGetId([
            'kind' => 'purchase',
            'idempotency_key' => 'crm-bank-'.$id,
            'created_at' => now(),
        ]);
        $claim = DB::table('sales_bank_payment_claims')->insertGetId([
            'sales_order_id' => $id,
            'buyer_user_id' => $reseller->id,
            'photo_encrypted' => 'fixture',
            'photo_fingerprint' => hash('sha256', 'crm-bank-'.$id),
            'telegram_message_key' => 'crm-bank-'.$id,
            'status' => $id === $voided ? 'voided' : 'approved',
            'reviewed_by' => $root->id,
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('sales_bank_settlements')->insert([
            'sales_order_id' => $id,
            'payment_claim_id' => $claim,
            'billing_journal_id' => $journal,
            'verified_by' => $root->id,
            'payment_reference' => 'CRMBANK'.$id,
            'amount_rials' => $id === $voided ? 900_000 : 200_000,
            'settled_at' => now(),
        ]);
    }
    crm01bOrder($member, 'direct', 100_000);
    $previous = crm01bOrder($member, 'direct', 400_000);
    DB::table('sales_order_settlements')->where('sales_order_id', $previous)
        ->update(['settled_at' => Carbon::parse('2026-11-26 23:59:59', 'Asia/Tehran')->utc()]);
    crm01bOrder($reseller, 'reseller', 300_000, 'refunded');

    $reader = app(ReadCrmDirectory::class);
    $current = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'today']));
    $yesterday = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'yesterday']));
    $analytics = app(ReadCrmAnalytics::class)->handle($root->id, ['period' => 'today', 'channel' => 'all']);
    expect($analytics['salesRials'])->toBe(300_000)
        ->and($analytics['cashRials'])->toBe(200_000)
        ->and($analytics['previousSalesRials'])->toBe(0);
    expect($current['totalRials'])->toBe(300_000)
        ->and($current['channels']['reseller']['revenueRials'])->toBe(200_000)
        ->and($yesterday['totalRials'])->toBe(400_000)
        ->and($current['cash']['bankSalesRials'])->toBe(200_000)
        ->and($current['cash']['cashInRials'])->toBe(200_000)
        ->and($yesterday['cash']['cashInRials'])->toBe(0);
    $rows = app(ReadCrmProfile::class)->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => 'payments']))['rows'];
    expect($rows->total())->toBe(1)
        ->and((int) $rows->items()[0]->order_id)->toBe($bank)
        ->and($rows->items()[0]->payment_method)->toBe('bank');
    Http::assertNothingSent();
});

it('keeps directory queries bounded as accounts grow and performs no business writes', function (): void {
    $root = crm01bUser('super-admin');
    crm01bUser('member');
    $reader = app(ReadCrmDirectory::class);
    $filters = crm01bFilters();
    $measure = static function () use ($reader, $root, $filters): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = $reader->handle($root->id, 'customers', $filters)['rows'];
            $queries = DB::getQueryLog();

            return [$result, $queries];
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    };
    [$small, $before] = $measure();
    foreach (range(1, 60) as $index) {
        crm01bUser('member', ['name' => 'Growth '.$index]);
    }
    [$large, $after] = $measure();
    expect($small->total())->toBe(1)
        ->and($large->total())->toBe(61)
        ->and($large->count())->toBe(50)
        ->and(count($after))->toBeLessThanOrEqual(count($before) + 2)
        ->and(count($after))->toBeLessThan(25);
    foreach ($after as $query) {
        expect(preg_match('/^\s*(select|with)\b/i', $query['query']))->toBe(1);
    }
    Http::assertNothingSent();
});

it('records only verified commercial private Sales Bot interactions without retaining payloads', function (): void {
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '12345678:crm-sales');
    config()->set('telegram.sales.webhook_secret', 'crm-sales-secret');
    config()->set('telegram.webhook_secret', 'crm-admin-secret');
    config()->set('nutgram.token', '87654321:crm-admin');
    $this->app->instance(ProcessSalesTelegramUpdate::class, new class implements ProcessSalesTelegramUpdate
    {
        public function handle(): void {}
    });
    $this->app->instance(ProcessTelegramUpdate::class, new class implements ProcessTelegramUpdate
    {
        public function handle(): void {}
    });

    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $other = crm01bUser('member');
    TelegramAccount::query()->create(['user_id' => $member->id, 'telegram_user_id' => 997110]);
    $headers = ['X-Telegram-Bot-Api-Secret-Token' => 'crm-sales-secret'];
    $url = '/api/telegram/sales/webhook';
    $message = ['update_id' => 60201, 'message' => [
        'from' => ['id' => 997110],
        'chat' => ['id' => 997110, 'type' => 'private'],
        'text' => 'PRIVATE-CONTENT',
    ]];

    $this->postJson($url, $message)->assertForbidden();
    $this->postJson('/api/telegram/admin/webhook', $message, ['X-Telegram-Bot-Api-Secret-Token' => 'crm-admin-secret'])->assertNoContent();
    expect(DB::table('sales_bot_interactions')->count())->toBe(0);
    $reader = app(ReadCrmInteractions::class);
    expect($reader->handle($root->id, $member->id)['recordedCount'])->toBeNull();

    $this->postJson($url, $message, $headers)->assertNoContent();
    $this->postJson($url, $message, $headers)->assertNoContent();
    $first = DB::table('sales_bot_interactions')->first();
    $this->travel(8)->days();
    $this->artisan('telegram:prune-updates')->assertSuccessful();
    $this->postJson($url, $message, $headers)->assertNoContent();
    expect(DB::table('sales_bot_interactions')->count())->toBe(1)
        ->and(DB::table('sales_bot_interactions')->value('recorded_at'))->toBe($first->recorded_at);

    $callback = ['update_id' => 60202, 'callback_query' => [
        'from' => ['id' => 997110],
        'message' => ['chat' => ['id' => 997110, 'type' => 'private']],
        'data' => 'PRIVATE-CALLBACK',
    ]];
    $this->postJson($url, $callback, $headers)->assertNoContent();
    $group = $message;
    $group['update_id'] = 60203;
    $group['message']['chat']['type'] = 'group';
    $this->postJson($url, $group, $headers)->assertNoContent();
    $member->forceFill(['is_active' => false])->save();
    $message['update_id'] = 60204;
    $this->postJson($url, $message, $headers)->assertNoContent();
    expect(DB::table('sales_bot_interactions')->count())->toBe(2)
        ->and(DB::table('sales_bot_interactions')->orderBy('id')->pluck('kind')->all())->toBe(['message', 'callback']);
    $data = $reader->handle($root->id, $member->id);
    expect($data['recordedCount'])->toBe(2)
        ->and($data['firstObservedAt'])->toBe($first->recorded_at)
        ->and($reader->handle($root->id, $other->id)['recordedCount'])->toBe(0)
        ->and($reader->handle($root->id, $other->id)['lastSeenAt'])->toBeNull();
    expect(fn () => $reader->handle($other->id, $member->id))->toThrow(AuthorizationException::class);
    expect(json_encode(DB::table('sales_bot_interactions')->get(), JSON_THROW_ON_ERROR))->not->toContain('PRIVATE');
    Http::assertNothingSent();
});

it('distinguishes valid purchase orders from settlement transactions and trial requests from activation', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    crm01bOrder($member, 'direct', 100_000);
    crm01bOrder($member, 'direct', 300_000, 'pending_payment', false);
    crm01bOrder($member, 'direct', 200_000, 'refunded');
    DB::table('trial_claims')->insert([
        'user_id' => $member->id,
        'telegram_user_id' => 997111,
        'phone_fingerprint' => hash('sha256', 'crm-trial-fixture'),
        'state' => 'reserved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $reader = app(ReadCrmProfile::class);
    $data = $reader->handle($root->id, $member->id, false, crm01cFilters());
    expect($data['summary']['purchases'])->toBe(1)
        ->and($data['summary']['renewals'])->toBe(0)
        ->and($data['summary']['firstPurchaseAt'])->not->toBeNull()
        ->and($data['summary']['lastPurchaseAt'])->toBe($data['summary']['firstPurchaseAt'])
        ->and($data['trial']['claimed'])->toBeTrue()
        ->and($data['trial']['activatedAt'])->toBeNull();
    DB::table('trial_claims')->where('user_id', $member->id)->update(['activated_at' => now(), 'state' => 'active']);
    expect($reader->handle($root->id, $member->id, false, crm01cFilters())['trial']['activatedAt'])->not->toBeNull();
    Http::assertNothingSent();
});

it('renders Persian dates and numbers and resets directory filters without changing the cohort', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    crm01bUser('reseller');
    crm01bOrder($member, 'direct', 123_450);
    $base = 'https://crm.denagate.ir/admin/crm';
    $this->actingAs($root, 'web')->get($base.'/customers/'.$member->id)
        ->assertOk()->assertSee('۱۴۰۵/۰۹/۰۶')->assertSee('۱۲٬۳۴۵ تومان');
    $html = $this->get($base.'/customers')->assertOk()->assertSee('پاک‌کردن فیلترها')->assertSee('تعداد نتایج: ۱')->getContent() ?: '';
    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);
    $response = $this->postJson('https://crm.denagate.ir'.app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => htmlspecialchars_decode($matches[1], ENT_QUOTES),
            'updates' => ['search' => 'Not a real customer', 'purchase' => 'no', 'period' => '7'],
            'calls' => [['path' => '', 'method' => 'resetFilters', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true'])->assertOk();
    $state = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($state['section'])->toBe('customers')
        ->and($state['period'])->toBe('all')
        ->and($state['search'])->toBe('')
        ->and($state['purchase'])->toBe('all');
    Http::assertNothingSent();
});

it('charts only the selected recent Tehran days using canonical settlements and commercial account cohorts', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $reseller->assignRole('member');
    crm01bOrder($member, 'direct', 100_000);
    crm01bOrder($reseller, 'reseller', 200_000);
    crm01bOrder($member, 'direct', 800_000, 'refunded');
    crm01bOrder($member, 'direct', 500_000, 'pending_payment', false);
    $reader = app(ReadCrmDirectory::class);
    $data = $reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'today']));
    expect($data['trend'])->toBe([['date' => '2026-11-27', 'revenueRials' => 300_000, 'registrations' => 2, 'directRegistrations' => 1, 'resellerRegistrations' => 1]])
        ->and($data['totalRials'])->toBe(300_000);
    $seven = $reader->handle($root->id, 'dashboard', crm01bFilters())['trend'];
    expect($seven)->toHaveCount(7)
        ->and(array_sum(array_column($seven, 'directRegistrations')))->toBe(1)
        ->and(array_sum(array_column($seven, 'resellerRegistrations')))->toBe(1);
    expect($reader->handle($root->id, 'dashboard', crm01bFilters(['period' => 'custom', 'from' => '2026-11-28', 'until' => '2026-11-30']))['trend'])->toBe([]);
    $html = $this->actingAs($root, 'web')->get('https://crm.denagate.ir/admin/crm')
        ->assertOk()->assertSee('crm-chart-cleanup', false)->assertSee('خریدهای تسویه‌شده (تومان)')
        ->assertDontSee('<progress', false)->getContent() ?: '';
    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);
    $response = $this->postJson('https://crm.denagate.ir'.app(HandleRequests::class)->getUpdateUri(), [
        'components' => [[
            'snapshot' => htmlspecialchars_decode($matches[1], ENT_QUOTES),
            'updates' => ['period' => 'today'],
            'calls' => [],
        ]],
    ], ['X-Livewire' => 'true'])->assertOk();
    $state = json_decode($response->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($state['period'])->toBe('today')
        ->and($response->json('components.0.effects.html'))->toContain('۱۴۰۵/۰۹/۰۶')
        ->and($response->json('components.0.effects.html'))->toContain('crm-chart-cleanup');
    Http::assertNothingSent();
});

it('paginates reseller accounts and all CRM contact and profile tables at fifty rows', function (): void {
    $root = crm01bUser('super-admin');
    $reseller = crm01bUser('reseller');
    foreach (range(1, 50) as $index) {
        crm01bUser('reseller', ['name' => 'Reseller '.$index]);
    }
    foreach (range(1, 51) as $index) {
        $order = crm01bOrder($reseller, 'reseller', 100_000);
        DB::table('sales_service_customer_labels')->insert([
            'sales_order_id' => $order,
            'phone' => null,
            'label' => 'Customer '.$index,
        ]);
        $subscription = DB::table('provisioning_subscriptions')->insertGetId([
            'reference' => 'crm-page-'.$order,
            'remote_username' => 'crm-page-'.$order,
            'state' => 'active',
            'last_operation_key' => 'crm-page-'.$order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('provisioning_order_links')->insert([
            'sales_order_id' => $order,
            'provisioning_subscription_id' => $subscription,
        ]);
    }

    $directory = app(ReadCrmDirectory::class);
    $profile = app(ReadCrmProfile::class);
    $assertPages = static function (callable $read): void {
        $first = $read(1);
        $second = $read(2);
        expect($first->perPage())->toBe(50)
            ->and($first->total())->toBe(51)
            ->and($first->lastPage())->toBe(2)
            ->and($first->count())->toBe(50)
            ->and($second->count())->toBe(1)
            ->and(collect($first->items())->pluck('id')->intersect(collect($second->items())->pluck('id'))->isEmpty())->toBeTrue();
    };

    $assertPages(fn (int $page) => $directory->handle($root->id, 'resellers', crm01bFilters(['page' => $page]))['rows']);
    foreach ([false, true] as $grouped) {
        $assertPages(fn (int $page) => $directory->handle($root->id, 'contacts', crm01bFilters(['groupContacts' => $grouped, 'page' => $page]))['rows']);
        $assertPages(fn (int $page) => $profile->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => 'contacts', 'groupContacts' => $grouped, 'page' => $page]))['rows']);
    }
    foreach (['orders', 'payments', 'services'] as $tab) {
        $assertPages(fn (int $page) => $profile->handle($root->id, $reseller->id, true, crm01cFilters(['tab' => $tab, 'page' => $page]))['rows']);
    }
    Http::assertNothingSent();
});

it('aggregates bounded analytics with independent cash, distinct buyers, historical channels and reseller filters', function (): void {
    config()->set('billing.topups_enabled', true);
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    $reseller->assignRole('member');
    $other = crm01bUser('reseller');

    $this->travelTo(Carbon::parse('2026-11-26 10:00:00', 'Asia/Tehran')->utc());
    $previous = crm01bOrder($member, 'direct', 400_000);
    app(CreditVerifiedAdminBankTopup::class)->handle($root->id, $reseller->id, 1_000_000, 'CRM-ANALYTICS-PREV', 'Fixture verified credit');
    $this->travelTo(Carbon::parse('2026-11-26 19:00:00', 'Asia/Tehran')->utc());
    crm01bOrder($member, 'direct', 900_000);
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'Asia/Tehran')->utc());
    $topup = app(CreditVerifiedAdminBankTopup::class)->handle($root->id, $member->id, 2_000_000, 'CRM-ANALYTICS-NOW', 'Fixture verified credit');
    $journal = DB::table('billing_journals')->insertGetId([
        'kind' => 'topup_amount_correction',
        'idempotency_key' => 'topup_amount_correction:'.$topup,
        'created_at' => now(),
    ]);
    DB::table('billing_topup_amount_corrections')->insert([
        'topup_id' => $topup, 'billing_journal_id' => $journal,
        'verified_amount_rials' => 1_800_000, 'adjustment_amount_rials' => 200_000,
        'corrected_by' => $root->id, 'reason' => 'Fixture correction', 'created_at' => now(),
    ]);

    crm01bOrder($member, 'direct', 100_000);
    crm01bOrder($member, 'direct', 200_000);
    $renewal = crm01bOrder($member, 'direct', 300_000);
    crm01bOrder($reseller, 'reseller', 500_000);
    crm01bOrder($other, 'reseller', 200_000);
    crm01bOrder($reseller, null, 700_000);
    crm01bOrder($member, 'direct', 900_000, 'refunded');
    crm01bOrder($member, 'direct', 800_000, 'pending_payment', false);
    $subscription = DB::table('provisioning_subscriptions')->insertGetId([
        'reference' => 'crm-analytics-renewal',
        'remote_username' => 'crm-analytics-renewal',
        'state' => 'active',
        'last_operation_key' => 'crm-analytics-renewal',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('sales_renewal_orders')->insert([
        'sales_order_id' => $renewal, 'source_order_id' => $previous,
        'provisioning_subscription_id' => $subscription, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $reader = app(ReadCrmAnalytics::class);
    $filters = ['period' => 'today', 'channel' => 'all'];
    $all = $reader->handle($root->id, $filters);
    expect($all['salesRials'])->toBe(2_000_000)
        ->and($all['cashRials'])->toBe(1_800_000)
        ->and($all['previousSalesRials'])->toBe(400_000)
        ->and($all['previousCashRials'])->toBe(1_000_000)
        ->and($all['buyers'])->toBe(3)
        ->and($all['newPurchases'])->toBe(5)
        ->and($all['renewals'])->toBe(1)
        ->and($all['channels'])->toBe(['direct' => 600_000, 'reseller' => 700_000, 'unknown' => 700_000])
        ->and($all['trend'])->toHaveCount(1)
        ->and($all['trend'][0]['directRegistrations'])->toBe(1)
        ->and($all['trend'][0]['resellerRegistrations'])->toBe(2)
        ->and((int) $all['products']->first()->purchases)->toBe(6)
        ->and((int) $all['top']->first()->id)->toBe($reseller->id);
    $filtered = $reader->handle($root->id, [...$filters, 'channel' => 'reseller', 'reseller' => $reseller->id]);
    expect($filtered['selectedRials'])->toBe(500_000)
        ->and($filtered['buyers'])->toBe(1)
        ->and($filtered['newPurchases'])->toBe(1)
        ->and($filtered['cashRials'])->toBe($all['cashRials'])
        ->and($filtered['salesRials'])->toBe($all['salesRials'])
        ->and($filtered['channels'])->toBe($all['channels'])
        ->and($filtered['trend'][0]['resellerRegistrations'])->toBe(2)
        ->and((int) $filtered['products']->first()->purchases)->toBe(1);
    $unknown = $reader->handle($root->id, [...$filters, 'channel' => 'unknown']);
    expect($unknown['selectedRials'])->toBe(700_000)
        ->and($unknown['buyers'])->toBe(1);
    $custom = $reader->handle($root->id, ['period' => 'custom', 'from' => '2026-11-26', 'until' => '2026-11-27', 'channel' => 'all']);
    expect($custom['salesRials'])->toBe(3_300_000)
        ->and($custom['cashRials'])->toBe(2_800_000)
        ->and($custom['buyers'])->toBe(3)
        ->and(array_sum(array_column($custom['trend'], 'buyers')))->toBe(4)
        ->and($custom['trend'])->toHaveCount(2);
    $ninety = $reader->handle($root->id, ['period' => '90', 'channel' => 'all']);
    expect(count($ninety['trend']))->toBeLessThanOrEqual(60)
        ->and($ninety['salesRials'])->toBe($custom['salesRials']);
    $history = $reader->handle($root->id, ['period' => 'all', 'channel' => 'all']);
    expect($history['salesRials'])->toBe($custom['salesRials'])
        ->and($history['previousSalesRials'])->toBeNull()
        ->and($history['start'])->toBe('2026-11-26');
    expect(fn () => $reader->handle($member->id, $filters))->toThrow(AuthorizationException::class);
    expect(fn () => $reader->handle($root->id, [...$filters, 'reseller' => $member->id]))->toThrow(ValidationException::class);
    expect(fn () => $reader->handle($root->id, [...$filters, 'channel' => 'direct', 'reseller' => $reseller->id]))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('renders analytics and resets filters through Livewire while rechecking host and root access', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $reseller = crm01bUser('reseller');
    crm01bOrder($member, 'direct', 100_000);
    crm01bOrder($reseller, 'reseller', 200_000);

    $url = 'https://crm.denagate.ir/admin/crm/analytics';
    $this->get($url)->assertRedirect('https://crm.denagate.ir/login');
    $this->actingAs($member, 'web')->get($url)->assertForbidden();
    $html = $this->actingAs($root, 'web')->get($url)->assertOk()
        ->assertSee('تحلیل و آمار')->assertSee('خریداران یکتای کل بازه')
        ->assertSee('analytics-financial', false)->assertSee('analytics-products', false)
        ->getContent() ?: '';
    expect(preg_match('/wire:snapshot="([^"]+)"/', $html, $matches))->toBe(1);
    $payload = ['components' => [[
        'snapshot' => htmlspecialchars_decode($matches[1], ENT_QUOTES),
        'updates' => ['period' => 'today', 'channel' => 'reseller', 'reseller' => (string) $reseller->id],
        'calls' => [],
    ]]];
    $update = app(HandleRequests::class)->getUpdateUri();
    $response = $this->postJson('https://crm.denagate.ir'.$update, $payload, ['X-Livewire' => 'true'])->assertOk();
    expect($response->json('components.0.effects.html'))->toContain('۲۰٬۰۰۰ تومان');
    $snapshot = $response->json('components.0.snapshot');
    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => [],
        'calls' => [['path' => '', 'method' => 'resetFilters', 'params' => []]],
    ]]];
    $reset = $this->postJson('https://crm.denagate.ir'.$update, $payload, ['X-Livewire' => 'true'])->assertOk();
    $state = json_decode($reset->json('components.0.snapshot'), true, flags: JSON_THROW_ON_ERROR)['data'];
    expect($state['period'])->toBe('30')->and($state['channel'])->toBe('all')->and($state['reseller'])->toBe('');
    $this->postJson('https://example.test'.$update, $payload, ['X-Livewire' => 'true'])->assertNotFound();
    config()->set('super_admin.telegram_ids', ['997102', '997103']);
    $this->postJson('https://crm.denagate.ir'.$update, $payload, ['X-Livewire' => 'true'])->assertForbidden();
    Http::assertNothingSent();
});

it('calculates daily wallet balances from opening postings and compensating journals without writes', function (): void {
    $root = crm01bUser('super-admin');
    $member = crm01bUser('member');
    $empty = crm01bUser('member');
    $negative = crm01bUser('reseller');
    $absent = crm01bUser('reseller');
    $opening = Carbon::parse('2026-10-27 23:59:59', 'Asia/Tehran')->utc();
    $wallet = DB::table('billing_wallets')->insertGetId(['user_id' => $member->id, 'created_at' => $opening, 'updated_at' => $opening]);
    $negativeWallet = DB::table('billing_wallets')->insertGetId(['user_id' => $negative->id, 'created_at' => $opening, 'updated_at' => $opening]);
    DB::table('billing_wallets')->insert(['user_id' => $empty->id, 'created_at' => $opening, 'updated_at' => $opening]);
    $post = static function (int $walletId, string $kind, string $side, int $amount, Carbon $at): void {
        $journal = DB::table('billing_journals')->insertGetId([
            'wallet_id' => $walletId, 'kind' => $kind, 'idempotency_key' => bin2hex(random_bytes(16)), 'created_at' => $at,
        ]);
        DB::table('billing_postings')->insert([
            'journal_id' => $journal, 'wallet_id' => $walletId,
            'account_code' => 'wallet_liability', 'side' => $side, 'amount_rials' => $amount,
        ]);
    };
    $post($wallet, 'topup', 'credit', 900_000, $opening);
    $post($wallet, 'purchase', 'debit', 300_000, Carbon::parse('2026-10-28 00:00:00', 'Asia/Tehran')->utc());
    $post($wallet, 'referral_reward', 'credit', 60_000, Carbon::parse('2026-11-25 12:00:00', 'Asia/Tehran')->utc());
    $post($wallet, 'topup_amount_correction', 'debit', 30_000, Carbon::parse('2026-11-26 12:00:00', 'Asia/Tehran')->utc());
    $post($wallet, 'topup_reversal', 'debit', 30_000, Carbon::parse('2026-11-26 23:59:59', 'Asia/Tehran')->utc());
    $post($negativeWallet, 'purchase', 'debit', 30_000, $opening);
    $reader = app(ReadWalletDailyAverage::class);
    $cutoff = Carbon::parse('2026-11-27 00:00:00', 'Asia/Tehran');
    $ids = [$member->id, $empty->id, $negative->id, $absent->id];

    $rows = $reader->handle($root->id, $ids, $cutoff);
    expect($rows[$member->id]['averageRials'])->toBe(602_000.0)
        ->and($rows[$member->id]['closingRials'])->toBe(600_000)
        ->and($rows[$empty->id]['averageRials'])->toBe(0.0)
        ->and($rows[$negative->id]['averageRials'])->toBe(-30_000.0)
        ->and($rows)->not->toHaveKey($absent->id);
    expect($rows[$member->id]['closingRials'])->toBe((int) ReadAdminWalletSummary::walletBalancesQuery()->where('w.user_id', $member->id)->first()->balance_rials);
    $post($wallet, 'topup', 'credit', 100_000, $cutoff->copy()->utc());
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        expect($reader->handle($root->id, $ids, $cutoff))->toBe($rows);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
    foreach ($queries as $query) {
        expect(preg_match('/^\s*(select|with)\b/i', $query['query']))->toBe(1);
    }
    expect(fn () => $reader->handle($member->id, $ids, $cutoff))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('ranks the same complete commercial cohort across filters and records missing role evidence honestly', function (): void {
    config()->set('billing.topups_enabled', true);
    $this->travelTo(Carbon::parse('2026-09-01 10:00:00', 'Asia/Tehran')->utc());
    $root = crm01bUser('super-admin');
    $a = crm01bUser('reseller', ['name' => 'Rank A']);
    $b = crm01bUser('reseller', ['name' => 'Rank B']);
    $c = crm01bUser('reseller', ['name' => 'Rank C']);
    $inactive = crm01bUser('reseller');
    $staff = crm01bUser('reseller');
    $staff->assignRole('admin');
    $a->assignRole('member');
    $audit = app(RecordIdentityAudit::class);
    foreach ([$a, $b, $c, $inactive, $staff] as $user) {
        $audit->handle($root, 'identity.roles.assigned', 'user', $user->id, ['before' => ['member'], 'after' => ['reseller']]);
    }
    $unknown = crm01bUser('reseller');
    $recent = crm01bUser('reseller');

    $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'Asia/Tehran')->utc());
    crm01bOrder($a, 'reseller', 200_000);
    $this->travelTo(Carbon::parse('2026-10-27 23:59:59', 'Asia/Tehran')->utc());
    app(CreditVerifiedAdminBankTopup::class)->handle($root->id, $a->id, 1_000_000, 'CRM-RANK-A', 'Fixture verified credit');
    app(CreditVerifiedAdminBankTopup::class)->handle($root->id, $b->id, 2_000_000, 'CRM-RANK-B', 'Fixture verified credit');

    $this->travelTo(Carbon::parse('2026-11-15 10:00:00', 'Asia/Tehran')->utc());
    $audit->handle($root, 'identity.roles.assigned', 'user', $recent->id, ['before' => ['reseller'], 'after' => ['member']]);
    $audit->handle($root, 'identity.roles.assigned', 'user', $recent->id, ['before' => ['member'], 'after' => ['reseller']]);
    $audit->handle($root, 'identity.roles.assigned', 'user', $a->id, ['before' => ['reseller'], 'after' => ['reseller', 'member']]);
    crm01bOrder($a, 'reseller', 200_000);
    $this->travelTo(Carbon::parse('2026-11-26 23:59:59', 'Asia/Tehran')->utc());
    crm01bOrder($a, 'reseller', 300_000);
    crm01bOrder($a, null, 900_000);
    crm01bOrder($a, 'reseller', 800_000, 'refunded');
    crm01bOrder($b, 'reseller', 700_000, 'pending_payment', false);
    $this->travelTo(Carbon::parse('2026-11-27 12:00:00', 'Asia/Tehran')->utc());
    crm01bOrder($a, 'reseller', 1_000_000);
    $inactive->forceFill(['is_active' => false])->save();

    $reader = app(ReadCrmResellerPerformance::class);
    $result = $reader->handle($root->id);
    $rows = $result['rows'];
    expect($result['eligibleCount'])->toBe(3)
        ->and($result['start'])->toBe('2026-10-28')
        ->and($result['end'])->toBe('2026-11-26')
        ->and($rows[$a->id]['rank'])->toBe(1)
        ->and($rows[$a->id]['score'])->toBe(81.0)
        ->and($rows[$a->id]['tier'])->toBe('gold')
        ->and($rows[$a->id]['revenueRials'])->toBe(500_000)
        ->and($rows[$a->id]['purchases'])->toBe(2)
        ->and($rows[$a->id]['purchaseDays'])->toBe(2)
        ->and($rows[$a->id]['growthPercent'])->toBe(150.0)
        ->and($rows[$a->id]['points']['wallet'])->toBe(5.0)
        ->and($rows[$a->id]['previousRank'])->toBe(1)
        ->and($rows[$b->id]['score'])->toBe(0.0)
        ->and($rows[$c->id]['averageWalletRials'])->toBeNull()
        ->and($rows[$b->id]['rank'])->toBe($rows[$c->id]['rank'])
        ->and($rows[$inactive->id]['state'])->toBe('inactive')
        ->and($rows[$unknown->id]['state'])->toBe('unknown')
        ->and($rows[$recent->id]['state'])->toBe('evaluating')
        ->and($rows)->not->toHaveKey($staff->id);

    $directory = app(ReadCrmDirectory::class);
    $ranked = $directory->handle($root->id, 'resellers', crm01bFilters(['sort' => 'performance_rank', 'direction' => 'asc']))['rows'];
    expect((int) $ranked->items()[0]->id)->toBe($a->id)
        ->and($ranked->items()[0]->performance['rank'])->toBe(1);
    $filtered = $directory->handle($root->id, 'resellers', crm01bFilters(['search' => 'Rank B', 'period' => 'today', 'sort' => 'performance_rank']))['rows'];
    expect($filtered->total())->toBe(1)
        ->and($filtered->items()[0]->performance['rank'])->toBe($rows[$b->id]['rank']);
    $profile = app(ReadCrmProfile::class)->handle($root->id, $a->id, true, crm01cFilters(['tab' => 'overview', 'period' => 'today']));
    expect($profile['performance']['row']['score'])->toBe($rows[$a->id]['score']);
    $this->actingAs($root, 'web')->get('https://crm.denagate.ir/admin/crm/resellers')
        ->assertOk()->assertSee('رتبه عملکرد');
    $this->get('https://crm.denagate.ir/admin/crm/resellers/'.$a->id)
        ->assertOk()->assertSee('میانگین مانده پایان روز کیف پول')->assertSee('طلایی');
    expect(fn () => $reader->handle($b->id))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('shows evaluation only for current active resellers with valid recent role evidence', function (): void {
    $root = crm01bUser('super-admin');
    $unknown = crm01bUser('reseller');
    $recent = crm01bUser('reseller');
    $inactive = crm01bUser('reseller');
    $former = crm01bUser('member');
    $staff = crm01bUser('reseller');
    $staff->assignRole('admin');
    $future = crm01bUser('reseller');
    $audit = app(RecordIdentityAudit::class);
    foreach ([$recent, $inactive, $former, $staff, $future] as $user) {
        $audit->handle($root, 'identity.roles.assigned', 'user', $user->id, ['before' => ['member'], 'after' => ['reseller']]);
    }
    $inactive->forceFill(['is_active' => false])->save();
    DB::table('identity_audit_events')->where('subject_type', 'user')->where('subject_id', $future->id)
        ->update(['created_at' => now()->addDay()]);

    $rows = app(ReadCrmResellerPerformance::class)->handle($root->id)['rows'];
    expect($rows[$recent->id]['state'])->toBe('evaluating')
        ->and($rows[$recent->id]['rank'])->toBeNull()
        ->and($rows[$unknown->id]['state'])->toBe('unknown')
        ->and($rows[$inactive->id]['state'])->toBe('inactive')
        ->and($rows[$future->id]['state'])->toBe('unknown')
        ->and($rows)->not->toHaveKey($former->id)
        ->and($rows)->not->toHaveKey($staff->id);

    $page = $this->actingAs($root, 'web')->get('https://crm.denagate.ir/admin/crm/resellers')
        ->assertOk()->assertSee('در حال ارزیابی')->assertSee('تعداد نتایج')
        ->assertDontSee('مبلغ خرید نماینده نزد دناگیت بر اساس کانال ثبت‌شده سفارش است.');
    $html = $page->getContent() ?: '';
    expect(substr_count($html, 'در حال ارزیابی'))->toBe(1);
    $this->get('https://crm.denagate.ir/admin/crm/customers')
        ->assertOk()->assertDontSee('در حال ارزیابی');
    $this->get('https://crm.denagate.ir/admin/crm/resellers/'.$former->id)->assertNotFound();
    Http::assertNothingSent();
});
