<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
});

it('renders the P0 visual primitives on overview, shopping and credit without business writes', function (): void {
    $buyer = User::factory()->create();
    $buyer->assignRole('reseller');
    TelegramAccount::query()->create([
        'user_id' => $buyer->id,
        'telegram_user_id' => 996623,
    ]);

    $this->actingAs($buyer)->withSession(['denagate_live.user_id' => $buyer->id]);

    Livewire::test(Dashboard::class)
        ->assertSee('dg-card-accent')
        ->assertSee('dg-stat')
        ->assertSee('dg-btn-primary')
        ->assertSee('dg-btn-secondary')
        ->assertSee('dg-heading')
        ->call('showSection', 'shop')
        ->assertSee('dg-heading')
        ->assertSee('dg-empty')
        ->assertSee('خرید سرویس')
        ->call('showSection', 'credit')
        ->assertSee('dg-card-accent')
        ->assertSee('dg-stat')
        ->assertSee('dg-btn-secondary')
        ->assertSee('dg-empty')
        ->assertSee('درخواست‌های اخیر شارژ');

    $this->assertDatabaseCount('sales_orders', 0);
    $this->assertDatabaseCount('billing_journals', 0);
    $this->assertDatabaseCount('provisioning_operations', 0);
});

it('limits visual changes to P2A screens and keeps Livewire bindings and compact list controls intact', function (): void {
    $template = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $layout = file_get_contents(resource_path('views/live/layout.blade.php'));
    $entry = file_get_contents(resource_path('views/live/entry.blade.php'));

    expect($template)->toBeString()
        ->toContain('class="dg-field min-h-12 px-4"')
        ->toContain('type="range" name="duration_days"')
        ->toContain('type="range" name="volume_gb"')
        ->toContain('x-model.number="value"')
        ->toContain('x-on:click="$wire.chooseBuilderVolume(value)"')
        ->toContain('x-on:click="$wire.chooseBuilderDays(value)"')
        ->toContain('wire:model="customerUsername"')
        ->toContain('wire:click="previewQuote"')
        ->toContain('wire:click="confirmOrder"')
        ->toContain('wire:click="prepareWalletPayment"')
        ->toContain('wire:click="confirmWalletPayment"')
        ->toContain('wire:click="cancelWalletPayment"')
        ->toContain('wire:click="openOrder(')
        ->toContain('wire:click="$refresh"')
        ->toContain('dg-status" data-tone="{{ match ($topup[\'status\'])')
        ->toContain('min-h-11 w-full items-center justify-between')
        ->toContain('wire:click="nextServices(')
        ->toContain('wire:click="firstServices"')
        ->and($layout)->toContain('@livewireScriptConfig')
        ->and($entry)->toContain('id="telegram-login"')
        ->not->toContain('@vite(');
});

it('uses opt-in compact plan styling shared with the read-only demo without changing operational controls', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    $live = file_get_contents(resource_path('views/live/dashboard.blade.php'));
    $demo = file_get_contents(resource_path('views/live/demo-preview.blade.php'));

    expect($css)->toContain('.denagate-live .dg-plan-card {')
        ->toContain('max-width: 23.5rem')
        ->toContain('.denagate-live .dg-plan-card[data-tone="blue"]')
        ->toContain('.denagate-live .dg-plan-card[data-tone="sand"]')
        ->toContain('.denagate-live .dg-plan-card[data-tone="builder"]')
        ->and($live)->toContain('data-tone="{{ [\'teal\', \'blue\', \'sand\'][$loop->index % 3] }}"')
        ->toContain('dg-card dg-plan-card')
        ->toContain('justify-items-center gap-3 sm:grid-cols-2')
        ->toContain('text-sm font-extrabold leading-7 text-[#354C58]')
        ->toContain('text-center text-2xl sm:text-3xl')
        ->toContain('wire:click="selectPlan({{ $item->plan->id }})"')
        ->toContain('wire:click="selectPlan({{ $builder->id }})"')
        ->toContain('wire:click="prepareWalletPayment"')
        ->toContain('wire:click="confirmWalletPayment"')
        ->and($demo)->toContain('dg-card dg-plan-card flex flex-col p-4 sm:p-5')
        ->toContain('data-tone="{{ [\'teal\', \'blue\', \'sand\'][$loop->index % 3] }}"')
        ->toContain('justify-items-center gap-3 sm:grid-cols-2')
        ->toContain('text-sm font-extrabold leading-7 text-[#354C58]')
        ->toContain('dg-stat block w-full text-center text-2xl sm:text-3xl')
        ->not->toContain('wire:click')
        ->not->toContain('wire:submit');
});
