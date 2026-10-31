<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    Role::findOrCreate('reseller', 'web');
});

it('distinguishes an unsubmitted top-up from a receipt awaiting review without crediting the wallet', function (): void {
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    TelegramAccount::query()->create([
        'user_id' => $reseller->id,
        'telegram_user_id' => 998812,
    ]);

    $topupId = app(RequestTopup::class)->handle($reseller->id, 250_000, 'p1-ux:'.bin2hex(random_bytes(8)));

    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);

    Livewire::test(Dashboard::class)
        ->call('showSection', 'credit')
        ->assertSee('در انتظار ارسال رسید')
        ->assertDontSee('در انتظار بررسی واریز');

    app(SubmitTopupReference::class)->handle($reseller->id, $topupId, 'UXP1'.strtoupper(bin2hex(random_bytes(6))));

    Livewire::test(Dashboard::class)
        ->call('showSection', 'credit')
        ->assertSee('در انتظار بررسی واریز')
        ->assertDontSee('در انتظار ارسال رسید');

    $this->assertDatabaseHas('billing_topups', ['id' => $topupId, 'status' => 'submitted']);
    expect(DB::table('billing_journals')->count())->toBe(0);
});
