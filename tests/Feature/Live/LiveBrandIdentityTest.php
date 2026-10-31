<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
});

it('serves the actual supplied logo and a separate illustrative SVG mark', function (): void {
    $logo = public_path('images/denagate-logo.png');
    $mark = public_path('images/denagate-mark.svg');

    expect(is_file($logo))->toBeTrue()
        ->and(is_file($mark))->toBeTrue()
        ->and(file_get_contents($logo, false, null, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $size = getimagesize($logo);
    expect($size)->toBeArray()
        ->and($size[0])->toBeGreaterThanOrEqual(40)
        ->and($size[1])->toBeGreaterThanOrEqual(40)
        ->and($size['mime'])->toBe('image/png');

    $svg = file_get_contents($mark);
    expect($svg)->toContain('<svg xmlns="http://www.w3.org/2000/svg"')
        ->toContain('<linearGradient')
        ->toContain('<path ')
        ->not->toContain('<script')
        ->not->toContain('<image');
});

it('brands both reseller navigation surfaces consistently and respects reduced motion', function (): void {
    $dashboard = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $css = file_get_contents(resource_path('css/app.css'));
    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));

    expect(substr_count($dashboard, 'src="/images/denagate-logo.png"'))->toBe(2)
        ->and(substr_count($dashboard, 'dg-live-status-dot'))->toBe(2)
        ->and($dashboard)->toContain('>DenaGate</p>')
        ->toContain('پنل نمایندگی')
        ->toContain('wire:click="showSection')
        ->toContain('aria-label="دسترسی سریع پنل"')
        ->not->toContain('دناگیت لایو')
        ->not->toContain('>DenaGate Live');
    expect($css)->toContain('.denagate-live .dg-live-status')
        ->toContain('@keyframes dg-live-breathe')
        ->toContain('animation: dg-live-breathe')
        ->toContain('@media (prefers-reduced-motion: reduce)')
        ->toContain('.denagate-live .dg-live-status-dot { animation: none; }');
    expect($layout)->toContain('<title>DenaGate | پنل نمایندگی</title>')
        ->toContain('@livewireScriptConfig');
});

it('keeps the signed no-Vite entry and the authorized dashboard functional with real branding', function (): void {
    $entry = $this->get('https://live.denagate.ir/')->assertOk()
        ->assertSee('DenaGate')
        ->assertSee('/images/denagate-logo.png')
        ->assertSee('telegram-login')
        ->assertSee('ورود از طریق بات فروش دناگیت')
        ->assertDontSee('DenaGate Live');

    $owner = User::factory()->create();
    $owner->assignRole('reseller');
    TelegramAccount::query()->create(['user_id' => $owner->id, 'telegram_user_id' => 991002]);

    $dashboard = $this->actingAs($owner)
        ->withSession(['denagate_live.user_id' => $owner->id])
        ->get('https://live.denagate.ir/dashboard')
        ->assertOk()
        ->assertSee('لوگوی دناگیت')
        ->assertSee('DenaGate')
        ->assertSee('dg-live-status-dot')
        ->assertSee('فعال')
        ->assertSee('نمای کلی')
        ->assertSee('اعتبار من')
        ->assertSee('گزارش‌ها')
        ->assertDontSee('دناگیت لایو');

    expect($entry->baseResponse->headers->get('Content-Type'))->toContain('text/html')
        ->and($dashboard->baseResponse->headers->get('Content-Type'))->toContain('text/html');

    Http::assertNothingSent();
});
