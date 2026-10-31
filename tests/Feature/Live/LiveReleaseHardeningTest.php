<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

function live6bOwner(): User
{
    $user = User::factory()->create();
    $user->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 998201]);

    return $user;
}

it('prevents caches and referrer disclosure on the private Live entry and dashboard only', function (): void {
    $this->get('http://live.denagate.ir/')->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSee('telegram-web-app.js');

    $owner = live6bOwner();
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id])
        ->get('http://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertHeader('Cache-Control', 'private, no-store')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertSeeLivewire(Dashboard::class);

    $this->get('http://example.org/')->assertOk()
        ->assertHeaderMissing('Referrer-Policy');

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not disclose a private error body in HTML or logs when a Live read fails', function (): void {
    $owner = live6bOwner();
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id]);

    $private = 'https://provider.private.example/SECRET-LIVE-6B?token=private';
    app()->bind(ViewWallet::class, static fn () => throw new RuntimeException($private));

    Log::spy();
    Livewire::test(Dashboard::class)
        ->assertSee('دریافت اطلاعات امکان‌پذیر نیست')
        ->assertSee('تلاش دوباره')
        ->assertDontSee('SECRET-LIVE-6B')
        ->assertDontSee($private);

    Log::shouldHaveReceived('warning')->once()->with(
        'denagate_live.operation_failed',
        ['operation' => 'dashboard_read', 'exception_type' => RuntimeException::class],
    );
    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the readable loading status while avoiding a full-page live announcement', function (): void {
    $owner = live6bOwner();
    $this->actingAs($owner)->withSession(['denagate_live.user_id' => $owner->id])
        ->get('https://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSee('aria-label="محتوای پنل نمایندگی"', false)
        ->assertSee('role="status"', false)
        ->assertDontSee('id="live-main" aria-live="polite"', false);

    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));
    expect($layout)->toContain('prefers-reduced-motion: reduce')
        ->toContain('animation-duration: 0.01ms')
        ->toContain('transition-duration: 0.01ms')
        ->toContain('@livewireScriptConfig')
        ->toContain("resources/js/live.js");

    expect(is_file(public_path('fonts/vazirmatn-variable.woff2')))->toBeTrue()
        ->and(is_file(public_path('fonts/OFL-Vazirmatn.txt')))->toBeTrue();
    Http::assertNothingSent();
});

it('keeps disabled Live hidden without affecting the existing public web entry', function (): void {
    config()->set('denagate_live.enabled', false);
    $this->get('https://live.denagate.ir/')->assertNotFound();
    $this->get('https://live.denagate.ir/dashboard')->assertNotFound();
    $this->get('https://example.org/')->assertOk();
    Http::assertNothingSent();
});
