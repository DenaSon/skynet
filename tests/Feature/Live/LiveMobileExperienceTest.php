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

it('keeps the mobile shell safe-area-aware in Telegram and ordinary browsers without new SDK hooks', function (): void {
    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));
    $dashboard = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($layout)
        ->toContain('<html lang="fa" dir="rtl">')
        ->toContain('width=device-width, initial-scale=1, viewport-fit=cover')
        ->toContain('min-h-dvh')
        ->toContain('prefers-reduced-motion: reduce')
        ->toContain('@livewireScriptConfig')
        ->toContain('resources/js/live.js')
        ->and($dashboard)
        ->toContain('aria-label="دسترسی سریع پنل"')
        ->toContain('grid-cols-4')
        ->toContain('lg:hidden')
        ->toContain('min-h-14')
        ->toContain('env(safe-area-inset-bottom)')
        ->toContain('var(--tg-content-safe-area-inset-bottom, 0px)')
        ->toContain('env(safe-area-inset-right)')
        ->toContain('var(--tg-content-safe-area-inset-right, 0px)')
        ->toContain('env(safe-area-inset-left)')
        ->toContain('var(--tg-content-safe-area-inset-left, 0px)')
        ->toContain('padding-inline-start:')
        ->toContain('padding-inline-end:')
        ->toContain('id="live-main"')
        ->toContain('pb-32')
        ->and($css)
        ->toContain('overflow-wrap: anywhere')
        ->toContain('font-variant-numeric: tabular-nums');

    expect($dashboard)->not->toContain('BackButton.show(')
        ->not->toContain('HapticFeedback.')
        ->not->toContain('requestFullscreen(');
});

it('renders and navigates the same four reseller sections with no extra writes or provider reads', function (): void {
    $owner = User::factory()->create();
    $owner->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $owner->id, 'telegram_user_id' => 998441]);

    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);

    Livewire::test(Dashboard::class)
        ->assertSee('نمای کلی')
        ->assertSee('دسترسی سریع پنل')
        ->assertSee('خرید سرویس')
        ->call('showSection', 'services')
        ->assertSet('section', 'services')
        ->assertSee('سرویس‌های من')
        ->call('showSection', 'credit')
        ->assertSet('section', 'credit')
        ->assertSee('اعتبار من')
        ->call('showSection', 'reports')
        ->assertSet('section', 'reports')
        ->assertSee('گزارش فروش')
        ->call('selectReportDays', 7)
        ->assertSet('reportDays', 7)
        ->call('showSection', 'overview')
        ->assertSet('section', 'overview')
        ->call('showSection', 'shop')
        ->assertSet('section', 'shop')
        ->assertSee('خرید سرویس');

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
    Http::assertNothingSent();
});

it('retains the signed Telegram entry and clear browser fallback without changing the login contract', function (): void {
    $entry = file_get_contents(resource_path('views/live/entry.blade.php'));

    expect($entry)
        ->toContain('id="telegram-login"')
        ->toContain('name="init_data" type="hidden"')
        ->toContain('app?.initData')
        ->toContain('form.requestSubmit()')
        ->toContain('برای ورود، پنل را از بات فروش دناگیت باز کنید.')
        ->toContain('role="status"')
        ->not->toContain('@vite(');

    $this->get('https://live.denagate.ir/')->assertOk()
        ->assertSee('telegram-login')
        ->assertSee('ورود از طریق بات فروش دناگیت');
    Http::assertNothingSent();
});
