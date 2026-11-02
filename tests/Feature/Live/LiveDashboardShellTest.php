<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
});

function liveShellUser(): User
{
    $user = User::factory()->create(['name' => 'Sample Reseller']);
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 992233]);

    return $user;
}

it('renders a Persian Livewire dashboard only for verified reseller sessions', function (): void {
    $user = liveShellUser();

    $this->actingAs($user)
        ->withSession(['denagate_live.user_id' => $user->id])
        ->get('https://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSeeLivewire(Dashboard::class)
        ->assertSee('Sample Reseller')
        ->assertSee('سرویس‌ها')
        ->assertSee('اعتبار من')
        ->assertSee('گزارش‌ها')
        ->assertDontSee('اطلاعات واقعی سرویس‌ها')
        ->assertDontSee('در مرحله بعد');
});

it('provides navigable Livewire sections without reading or mutating commerce records', function (): void {
    $user = liveShellUser();

    $this->actingAs($user)->withSession(['denagate_live.user_id' => $user->id]);

    Livewire::test(Dashboard::class)
        ->assertSet('section', 'overview')
        ->call('showSection', 'credit')
        ->assertSet('section', 'credit')
        ->assertSee('هنوز درخواست شارژی ثبت نشده است.')
        ->call('showSection', 'malicious-section')
        ->assertSet('section', 'credit')
        ->call('showSection', 'overview')
        ->assertSet('section', 'overview');

    $this->assertDatabaseCount('sales_orders', 0);
    $this->assertDatabaseCount('billing_journals', 0);
});

it('does not expose the Livewire page when feature is disabled or the reseller session is absent', function (): void {
    $user = liveShellUser();

    config()->set('denagate_live.enabled', false);
    $this->actingAs($user)->withSession(['denagate_live.user_id' => $user->id])
        ->get('https://live.denagate.ir/dashboard')->assertNotFound();

    config()->set('denagate_live.enabled', true);
    $this->withSession(['denagate_live.user_id' => null])
        ->get('https://live.denagate.ir/dashboard')->assertRedirect();
});

it('uses a readable, locally licensed Persian light theme without disturbing Livewire navigation', function (): void {
    $user = liveShellUser();

    $this->actingAs($user)
        ->withSession(['denagate_live.user_id' => $user->id])
        ->get('https://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSee('denagate-live')
        ->assertSee('/fonts/vazirmatn-variable.woff2')
        ->assertSee('bg-[#F6F8FA]')
        ->assertSee('دسترسی سریع پنل')
        ->assertDontSee('action="/logout"', false)
        ->assertDontSee('خروج امن')
        ->assertDontSee('>خروج</button>', false)
        ->assertDontSee('bg-[#0B1220]');

    expect(is_file(public_path('fonts/vazirmatn-variable.woff2')))->toBeTrue()
        ->and(is_file(public_path('fonts/OFL-Vazirmatn.txt')))->toBeTrue();
});

it('provides a bundled Livewire runtime and HTTPS config for every reseller action', function (): void {
    $user = liveShellUser();

    $this->actingAs($user)
        ->withSession(['denagate_live.user_id' => $user->id])
        ->get('http://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSee('wire:click="showSection', false)
        ->assertSee('خرید سرویس')
        ->assertSee('livewireScriptConfig', false)
        ->assertDontSee('src="/livewire-', false);

    // Source-to-asset contract: the actual bundled JavaScript is verified by the CI Vite build.
    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));
    $runtime = file_get_contents(resource_path('js/live.js'));
    $vite = file_get_contents(base_path('vite.config.js'));
    expect($layout)->toContain("@vite(['resources/css/app.css', 'resources/js/live.js'])")
        ->toContain('@livewireScriptConfig')
        ->not->toContain('@livewireScripts')
        ->and($runtime)->toContain('vendor/livewire/livewire/dist/livewire.esm')
        ->toContain('Livewire.start()')
        ->and($vite)->toContain("'resources/js/live.js'");
});

it('provides one shared shell for real and demo views with identical navigation without operational controls', function (): void {
    $shell = file_get_contents(resource_path('views/components/live/shell.blade.php'));
    $dashboard = file_get_contents(resource_path('views/live/dashboard.blade.php'));

    expect($dashboard)->toContain('<x-live.shell :section="$section">')
        ->not->toContain('<aside ')
        ->not->toContain('aria-label="دسترسی سریع پنل"')
        ->and($shell)->toContain("@props(['section', 'demo' => false])")
        ->toContain('aria-label="منوی پنل نمایندگی"')
        ->toContain('aria-label="دسترسی سریع پنل"')
        ->toContain('safe-area-inset-bottom')
        ->not->toContain('confirmWalletPayment')
        ->not->toContain('confirmOrder')
        ->not->toContain('ChangeResellerSubscriptionStatusJob');

    $html = Blade::render('<x-live.shell section="overview" :demo="true"><p>نمونه آزمایشی</p></x-live.shell>');
    expect($html)->toContain('نمونه آزمایشی')
        ->toContain('حالت دمو')
        ->toContain('پیش‌نمایش نمایندگی')
        ->toContain('aria-label="دسترسی سریع پنل"')
        ->toContain('wire:click="showSection(&#039;overview&#039;)"')
        ->not->toContain('حساب نماینده')
        ->not->toContain('motion-safe:animate-pulse')
        ->not->toContain('wire:click="confirmOrder"');

    $real = Blade::render('<x-live.shell section="overview"><p>نماینده واقعی</p></x-live.shell>');
    expect($real)->toContain('نماینده واقعی')
        ->toContain('حساب نماینده')
        ->toContain('پنل اختصاصی نمایندگان')
        ->toContain('motion-safe:animate-pulse')
        ->not->toContain('حالت دمو');
});
