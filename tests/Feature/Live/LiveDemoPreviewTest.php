<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', '321:sales-bot');
    config()->set('nutgram.token', '123:admin-bot');
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

function demoSignedData(int $telegramId, ?int $date = null, string $token = '321:sales-bot'): string
{
    $fields = [
        'auth_date' => (string) ($date ?? time()),
        'query_id' => 'AA-demo',
        'user' => json_encode(['id' => $telegramId, 'first_name' => 'Demo'], JSON_THROW_ON_ERROR),
    ];
    ksort($fields, SORT_STRING);
    $check = implode("\n", array_map(
        static fn (string $key, string $value): string => $key.'='.$value,
        array_keys($fields), array_values($fields),
    ));
    $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

    return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
}

function demoMember(int $telegramId = 661100): User
{
    $user = User::factory()->create();
    $user->assignRole('member');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

it('keeps the demo host-scoped, disabled with Live and inaccessible without a signed member session', function (): void {
    $this->get('https://example.org/demo')->assertNotFound();
    $this->get('https://live.denagate.ir/demo')->assertOk()
        ->assertSee('پیش‌نمایش نمایندگی')
        ->assertSee('action="https://live.denagate.ir/demo/auth/telegram"', false)
        ->assertDontSee('type="password"', false);
    $this->get('https://live.denagate.ir/demo/preview')
        ->assertRedirect('https://live.denagate.ir/demo');

    $this->actingAs(demoMember())->get('https://live.denagate.ir/demo/preview')
        ->assertRedirect('https://live.denagate.ir/demo');

    config()->set('denagate_live.enabled', false);
    $this->get('https://live.denagate.ir/demo')->assertNotFound();
    $this->get('https://live.denagate.ir/demo/preview')->assertNotFound();
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661100)])
        ->assertNotFound();
});

it('allows only signed active member viewers without granting the reseller web guard', function (): void {
    $user = demoMember();

    $this->post('https://live.denagate.ir/demo/auth/telegram', [
        'init_data' => demoSignedData(661100),
    ])->assertRedirect('https://live.denagate.ir/demo/preview');
    $this->assertGuest('web');

    $this->get('https://live.denagate.ir/demo/preview')->assertOk()
        ->assertSee('DenaGate')
        ->assertSee('حالت دمو')
        ->assertSee('آمار و سرویس‌ها نمایشی‌اند')
        ->assertSee('اعتبار قابل استفاده')
        ->assertSee('مشتری نمونه ۱')
        ->assertSee('گزارش فروش')
        ->assertSee('پلن‌های نمایندگی')
        ->assertSee('خروج از دمو')
        ->assertSee('x-on:click="demoSection =', false)
        ->assertDontSee('wire:click="showSection', false)
        ->assertDontSee('wire:click="confirmOrder', false)
        ->assertDontSee('لینک اشتراک');

    $this->get('https://live.denagate.ir/dashboard')->assertRedirect();
    expect($user->fresh()->getRoleNames()->all())->toBe(['member']);
    $this->assertDatabaseCount('sales_orders', 0);
    $this->assertDatabaseCount('billing_journals', 0);
    $this->assertDatabaseCount('provisioning_operations', 0);
    $this->assertDatabaseCount('sales_panel_requests', 0);
    Http::assertNothingSent();
});

it('rejects forged, expired, cross-bot, unlinked, reseller and inactive identities', function (): void {
    // Verify all denial categories independently of the separate login rate limiter.
    $this->withoutMiddleware(ThrottleRequests::class);
    demoMember(661100);
    $raw = demoSignedData(661100);
    foreach ([
        $raw.'&auth_date=1',
        str_replace('AA-demo', 'AA-forged', $raw),
        demoSignedData(661100, time() - 3600),
        demoSignedData(661100, time() + 90),
        demoSignedData(661100, null, '123:admin-bot'),
        demoSignedData(778899),
        'user=661100',
    ] as $invalid) {
        $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => $invalid])
            ->assertForbidden()->assertDontSee($invalid);
    }

    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $reseller->id, 'telegram_user_id' => 661101]);
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661101)])
        ->assertForbidden();

    $inactive = demoMember(661102);
    $inactive->forceFill(['is_active' => false])->save();
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661102)])
        ->assertForbidden();
    $this->assertGuest('web');
});

it('fails closed after a member is revoked or the Telegram account binding changes', function (): void {
    $user = demoMember();
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661100)])
        ->assertRedirect();
    $user->syncRoles(['reseller']);
    $this->get('https://live.denagate.ir/demo/preview')->assertForbidden();
    $this->get('https://live.denagate.ir/demo/preview')->assertRedirect();

    $user->syncRoles(['member']);
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661100)])
        ->assertRedirect();
    TelegramAccount::query()->where('user_id', $user->id)->update(['telegram_user_id' => 661199]);
    $this->get('https://live.denagate.ir/demo/preview')->assertForbidden();

    $this->withSession(['denagate_demo.user_id' => $user->id, 'denagate_demo.telegram_id' => 661199]);
    $user->forceFill(['is_active' => false])->save();
    $this->get('https://live.denagate.ir/demo/preview')->assertForbidden();
});

it('keeps signed Demo sessions and exit independent of an existing real reseller session', function (): void {
    $real = User::factory()->create();
    $real->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $real->id, 'telegram_user_id' => 771122]);
    demoMember();

    $this->actingAs($real)->withSession(['denagate_live.user_id' => $real->id]);
    $this->post('https://live.denagate.ir/demo/auth/telegram', [
        'init_data' => demoSignedData(661100),
    ])->assertRedirect('https://live.denagate.ir/demo/preview');

    $this->assertAuthenticatedAs($real, 'web');
    $this->get('https://live.denagate.ir/dashboard')->assertOk();
    $this->get('https://live.denagate.ir/demo/preview')->assertOk();

    $this->post('https://live.denagate.ir/demo/exit')
        ->assertRedirect('https://live.denagate.ir/demo?closed=1');
    $this->get('https://live.denagate.ir/demo/preview')->assertRedirect();
    $this->get('https://live.denagate.ir/demo?closed=1')
        ->assertOk()->assertSee('پیش‌نمایش بسته شد.');
    $this->assertAuthenticatedAs($real, 'web');
    $this->get('https://live.denagate.ir/dashboard')->assertOk();
    Http::assertNothingSent();
});

it('keeps Demo UI close to the real dashboard while exposing no operational callbacks', function (): void {
    $preview = file_get_contents(resource_path('views/live/demo-preview.blade.php'));
    $dashboard = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $metric = file_get_contents(resource_path('views/components/live/report-metric.blade.php'));

    expect($preview)->toContain('<x-live.shell section="overview" :demo="true">')
        ->toContain('dg-card-accent relative overflow-hidden bg-gradient-to-bl')
        ->toContain('grid grid-cols-1 gap-3 sm:grid-cols-3 md:gap-5')
        ->toContain('grid grid-cols-3 gap-2.5 sm:gap-3')
        ->toContain('<x-live.report-metric :label="$label" :value="$value" :historical="$historical" />')
        ->toContain("demoSection === 'shop'")
        ->toContain("demoSection === 'services'")
        ->toContain("demoSection === 'credit'")
        ->toContain("demoSection === 'reports'")
        ->toContain("route('denagate-live.demo.exit')")
        ->toContain("route('denagate-live.demo.preview', ['section' => 'shop'])")
        ->not->toContain('wire:click')
        ->not->toContain('wire:submit')
        ->not->toContain('ListQuotedPresets')
        ->not->toContain('sales_plan_prices')
        ->not->toContain('Remnawave')
        ->not->toContain('تمام اطلاعات این صفحه')
        ->not->toContain('هیچ کیف پول')
        ->and($dashboard)->toContain('<x-live.report-metric :label="$label" :value="$value" :historical="$isHistorical" />')
        ->and($metric)->toContain('min-h-28')
        ->toContain('rounded-[18px]');
});

it('never reads a live catalog or real operational data in DEMO-01', function (): void {
    demoMember();
    $this->post('https://live.denagate.ir/demo/auth/telegram', ['init_data' => demoSignedData(661100)])
        ->assertRedirect();

    // The presentational view cannot call real pricing, owner-scoped actions, or Provider APIs.
    $preview = file_get_contents(resource_path('views/live/demo-preview.blade.php'));
    expect($preview)->toContain('<x-live.shell section="overview" :demo="true">')
        ->toContain("demoSection === 'shop'")
        ->not->toContain('ListQuotedPresets')
        ->not->toContain('sales_plan_prices')
        ->not->toContain('CalculatePlanQuote')
        ->not->toContain('Remnawave')
        ->not->toContain('wire:click')
        ->not->toContain('sales_orders');

    $this->get('https://live.denagate.ir/demo/preview')->assertOk()
        ->assertDontSee('987654321')
        ->assertDontSee('98,765,432');
    Http::assertNothingSent();
});
