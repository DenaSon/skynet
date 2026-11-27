<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ReadCrmDirectory;
use App\Modules\Identity\Application\ReadCrmProfile;
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
    foreach (range(1, 26) as $index) {
        crm01bUser('member', ['name' => 'Fixture '.$index]);
    }
    crm01bUser('member', ['name' => 'Different', 'is_active' => false]);
    $reader = app(ReadCrmDirectory::class);
    $page = $reader->handle($root->id, 'customers', crm01bFilters(['search' => 'Fixture', 'status' => 'active', 'sort' => 'id', 'page' => 2]))['rows'];
    expect($page->total())->toBe(26)->and($page->count())->toBe(1);
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
    expect($current['totalRials'])->toBe(300_000)
        ->and($current['channels']['reseller']['revenueRials'])->toBe(200_000)
        ->and($yesterday['totalRials'])->toBe(400_000);
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
    foreach (range(1, 30) as $index) {
        crm01bUser('member', ['name' => 'Growth '.$index]);
    }
    [$large, $after] = $measure();
    expect($small->total())->toBe(1)
        ->and($large->total())->toBe(31)
        ->and($large->count())->toBe(25)
        ->and(count($after))->toBeLessThanOrEqual(count($before) + 2)
        ->and(count($after))->toBeLessThan(25);
    foreach ($after as $query) {
        expect(preg_match('/^\s*(select|with)\b/i', $query['query']))->toBe(1);
    }
    Http::assertNothingSent();
});
