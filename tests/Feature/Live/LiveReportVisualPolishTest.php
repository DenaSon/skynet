<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

it('renders the grouped report hierarchy and updates the selected reporting window without writes', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $owner->id, 'telegram_user_id' => 989451]);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'reports')
        ->assertSee('گزارش فروش')
        ->assertSee('مجموع فروش تسویه‌شده')
        ->assertSee('عملکرد فروش')
        ->assertSee('وضعیت فعلی حساب')
        ->assertSee('خریدهای جدید')
        ->assertSee('تمدیدها')
        ->assertSee('تراکنش‌های تأییدشده')
        ->assertSee('سفارش‌های در انتظار پرداخت')
        ->assertSee('کل سرویس‌های ثبت‌شده')
        ->assertSee('سرویس‌های دارای نام')
        ->assertSee('بازه گزارش')
        ->assertSee('7 روز')
        ->assertSee('30 روز')
        ->assertSee('aria-labelledby="report-revenue-title"', false)
        ->assertSee('aria-labelledby="report-sales-metrics-title"', false)
        ->assertSee('aria-labelledby="report-account-metrics-title"', false)
        ->assertSee('role="group"', false)
        ->assertSee('aria-pressed="true"', false)
        ->assertSee('بازه 30 روزه')
        ->call('selectReportDays', 7)
        ->assertSet('reportDays', 7)
        ->assertSee('بازه 7 روزه')
        ->call('selectReportDays', 30)
        ->assertSet('reportDays', 30)
        ->assertSee('بازه 30 روزه');

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    Http::assertNothingSent();
});

it('keeps all original report fields, price conversion and a single loading announcement', function (): void {
    $view = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $start = strpos($view, "@elseif (\$section === 'reports')");
    $end = strpos($view, "@endif\n                @if (\$salesBotUrl !== null", $start);

    expect($start)->not->toBeFalse()
        ->and($end)->not->toBeFalse();

    $reports = substr($view, $start, $end - $start);
    foreach (['revenueRials', 'from', 'to', 'sales', 'renewals', 'transactions', 'pending', 'services', 'namedServices'] as $field) {
        expect(substr_count($reports, "\$report['".$field."']"))->toBe(1);
    }

    expect($reports)->toContain("number_format(intdiv(\$report['revenueRials'], 10))")
        ->toContain("\\App\\Support\\PersianCalendar::date(\$report['from'])")
        ->toContain("\\App\\Support\\PersianCalendar::date(\$report['to'])")
        ->toContain("wire:click=\"selectReportDays({{ \$days }})\"")
        ->toContain('wire:loading.attr="disabled"')
        ->toContain('wire:loading.delay wire:target="selectReportDays" role="status"')
        ->toContain("aria-pressed=\"{{ \$report['days'] === \$days ? 'true' : 'false' }}\"")
        ->toContain('dg-card-accent')
        ->toContain('dg-stat')
        ->toContain('grid-cols-2')
        ->not->toContain('sales_orders')
        ->not->toContain('provisioning_operations');

    expect(substr_count($reports, 'wire:loading.delay wire:target="selectReportDays"'))->toBe(1);
});
