<?php

declare(strict_types=1);

use App\Interfaces\Web\CRM\Shell;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('denagate_crm.host', 'crm.denagate.ir');
    config()->set('super_admin.telegram_ids', ['997001', '997002']);

    Role::findOrCreate('super-admin', 'web');
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('admin', 'web');
});

function crm01aRootUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);

    TelegramAccount::query()->create([
        'user_id' => $user->id,
        'telegram_user_id' => 997001,
    ]);

    return $user;
}

it('redirects the CRM host root to the private dashboard', function (): void {
    $this->get('https://crm.denagate.ir/')
        ->assertRedirect('https://crm.denagate.ir/admin/crm')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('redirects guests to the existing login route over HTTPS', function (): void {
    $this->get('https://crm.denagate.ir/admin/crm')
        ->assertRedirect('https://crm.denagate.ir/login');
});

it('renders the isolated Mary UI shell for a trusted Super Admin', function (): void {
    $root = crm01aRootUser();

    $this->actingAs($root, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertOk()
        ->assertSeeLivewire(Shell::class)
        ->assertSee('پنل مدیریت مشتریان')
        ->assertSee('حالت فقط مشاهده')
        ->assertSee('دسترسی امن');
});

it('denies members, resellers and delegated administrators', function (): void {
    $member = User::factory()->create();
    $this->actingAs($member, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertForbidden();

    $reseller = User::factory()->create();
    $reseller->syncRoles(['reseller']);
    $this->actingAs($reseller, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertForbidden();

    $admin = User::factory()->admin()->create();
    $admin->givePermissionTo(Permission::findOrCreate('users.manage', 'web'));
    $this->actingAs($admin, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertForbidden();
});

it('fails closed for inactive roots and incomplete trusted-root allowlists', function (): void {
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->syncRoles(['super-admin']);
    TelegramAccount::query()->create([
        'user_id' => $inactive->id,
        'telegram_user_id' => 997001,
    ]);

    $this->actingAs($inactive, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertForbidden();

    config()->set('super_admin.telegram_ids', ['997001']);
    $root = crm01aRootUser();

    $this->actingAs($root, 'web')
        ->get('https://crm.denagate.ir/admin/crm')
        ->assertForbidden();
});

it('forces HTTPS form assets and host-only secure session cookies on the CRM host', function (): void {
    config()->set('session.secure', false);
    config()->set('session.domain', '.denagate.ir');
    $originalSecure = config('session.secure');
    $originalDomain = config('session.domain');

    $response = $this->withHeader('X-Forwarded-Proto', 'https')
        ->get('http://crm.denagate.ir/login');

    $response->assertOk()
        ->assertSee('action="https://crm.denagate.ir/login"', false)
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control', 'no-store, private');

    $sessionCookie = collect($response->headers->getCookies())->first(
        fn ($cookie): bool => $cookie->getName() === config('session.cookie'),
    );

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie?->isSecure())->toBeTrue()
        ->and($sessionCookie?->getDomain())->toBeNull()
        ->and(config('session.secure'))->toBe($originalSecure)
        ->and(config('session.domain'))->toBe($originalDomain);
});

it('redirects explicit HTTP proxy requests to HTTPS without global proxy trust', function (): void {
    $this->withHeader('X-Forwarded-Proto', 'http')
        ->get('http://crm.denagate.ir/login')
        ->assertStatus(301)
        ->assertHeader('Location', 'https://crm.denagate.ir/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('keeps HTTPS policy isolated from the default web host and DenaGate Live', function (): void {
    $this->withHeader('X-Forwarded-Proto', 'http')
        ->get('http://example.test/login')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertSee('action="http://example.test/login"', false);

    $this->get('http://example.test/')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security');

    config()->set('denagate_live.enabled', true);
    $this->get('http://live.denagate.ir/')
        ->assertOk()
        ->assertHeaderMissing('Strict-Transport-Security')
        ->assertSee('telegram-web-app.js');
});

it('uses only CRM frontend assets and does not reuse Live recovery behavior', function (): void {
    $layout = file_get_contents(resource_path('views/layouts/crm.blade.php'));
    $runtime = file_get_contents(resource_path('js/crm.js'));

    expect($layout)->toContain('resources/css/crm.css')
        ->toContain('resources/js/crm.js')
        ->not->toContain('resources/css/app.css')
        ->not->toContain('resources/js/live.js');

    expect($runtime)->toContain('Livewire.start()')
        ->not->toContain('interceptRequest');
});
