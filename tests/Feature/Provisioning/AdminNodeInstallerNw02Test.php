<?php

declare(strict_types=1);

use App\Jobs\InstallRemnawaveNode;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Application\ManageNodeInstallation;
use App\Modules\Provisioning\Infrastructure\NodeSetup\NodeSshInstaller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

/** @return array{actor:User,other:User,ready:array<string,mixed>} */
function nw02Fixture(): array
{
    config()->set('super_admin.telegram_ids', ['981101', '981102']);
    config()->set('services.remnawave.url', 'https://rw.nw02.test');
    config()->set('services.remnawave.token', 'fake-token');
    config()->set('services.remnawave.writes_enabled', false);
    config()->set('services.remnawave.node_ssh_setup_enabled', true);
    config()->set('services.remnawave.node_ssh_install_enabled', true);
    config()->set('queue.default', 'database');

    $actor = User::factory()->create();
    $actor->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 981101]);
    $other = User::factory()->create();
    $other->syncRoles(['member']);

    return [
        'actor' => $actor,
        'other' => $other,
        'ready' => [
            'name' => 'Node-Test-02',
            'ip' => '194.5.192.133',
            'port' => 22,
            'username' => 'root',
            'secret' => Crypt::encryptString('not-a-real-password'),
            'host' => ['knownHost' => '194.5.192.133 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGFAKE'],
            'profile' => ['uuid' => '550e8400-e29b-41d4-a716-446655440001', 'name' => 'Main'],
            'inbound' => ['uuid' => '550e8400-e29b-41d4-a716-446655440002', 'name' => 'Shadowsocks'],
        ],
    ];
}

it('NW-02 defaults off, queues opaque identifier only, and reserves a VPS once', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    Http::preventStrayRequests();
    $manager = app(ManageNodeInstallation::class);
    config()->set('services.remnawave.node_ssh_install_enabled', false);

    expect(fn () => $manager->enqueue($fixture['actor']->id, $fixture['ready']))->toThrow(RuntimeException::class);
    Queue::assertNothingPushed();
    Http::assertNothingSent();

    config()->set('services.remnawave.node_ssh_install_enabled', true);
    $identifier = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    expect($identifier)->toMatch('/\A[a-f0-9]{32}\z/D');
    Queue::assertPushed(InstallRemnawaveNode::class, 1);
    Queue::assertPushed(InstallRemnawaveNode::class, fn (InstallRemnawaveNode $job): bool => $job->identifier === $identifier);
    $stored = Cache::get('nw02:access:'.$identifier);
    expect($stored)->toBeString()
        ->not->toContain('not-a-real-password')
        ->not->toContain('194.5.192.133');

    expect(fn () => $manager->enqueue($fixture['actor']->id, $fixture['ready']))->toThrow(RuntimeException::class);
    expect(fn () => $manager->status($fixture['other']->id, $identifier))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

it('NW-02 launches once using GET keygen and never performs Provider POST', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    $manager = app(ManageNodeInstallation::class);
    $identifier = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    Http::fake([
        'https://rw.nw02.test/api/keygen' => Http::response(['response' => ['secretKey' => str_repeat('A', 64)]], 200),
    ]);

    $fake = Mockery::mock(NodeSshInstaller::class);
    $fake->shouldReceive('start')->once()->withArgs(static fn (...$args): bool => $args[0] === '194.5.192.133'
        && $args[3] === 'not-a-real-password'
        && $args[5] === str_repeat('A', 64));
    app()->instance(NodeSshInstaller::class, $fake);
    $manager = app(ManageNodeInstallation::class);

    (new InstallRemnawaveNode($identifier))->handle($manager);
    (new InstallRemnawaveNode($identifier))->handle($manager);
    expect(Cache::get('nw02:status:'.$identifier)['state'])->toBe('started');
    Http::assertSentCount(1);
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://rw.nw02.test/api/keygen');
});

it('NW-02 reports an uncertain operation on malformed keygen and never retries SSH', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    $manager = app(ManageNodeInstallation::class);
    $identifier = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    Http::fake(['https://rw.nw02.test/api/keygen' => Http::response(['response' => []], 200)]);
    $fake = Mockery::mock(NodeSshInstaller::class);
    $fake->shouldNotReceive('start');
    app()->instance(NodeSshInstaller::class, $fake);
    $manager = app(ManageNodeInstallation::class);
    $manager->launch($identifier);
    $manager->launch($identifier);
    expect(Cache::get('nw02:status:'.$identifier)['state'])->toBe('uncertain');
    Http::assertSentCount(1);
});

it('NW-02 status rechecks root identity and destroys credentials when Node runs', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    $manager = app(ManageNodeInstallation::class);
    $identifier = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    $state = Cache::get('nw02:status:'.$identifier);
    $state['state'] = 'started';
    Cache::put('nw02:status:'.$identifier, $state, now()->addHour());

    $fake = Mockery::mock(NodeSshInstaller::class);
    $fake->shouldReceive('status')->once()->andReturn('running');
    app()->instance(NodeSshInstaller::class, $fake);
    $manager = app(ManageNodeInstallation::class);
    expect($manager->status($fixture['actor']->id, $identifier)['state'])->toBe('running')
        ->and(Cache::get('nw02:access:'.$identifier))->toBeNull();

    $fixture['actor']->syncRoles(['member']);
    expect(fn () => $manager->status($fixture['actor']->id, $identifier))->toThrow(AuthorizationException::class);
});

it('NW-02 fixed remote installer parses as POSIX shell and has no dynamic command template', function (): void {
    $installer = base_path('resources/scripts/nw02-install-remote.sh');
    $process = new Process(['sh', '-n', $installer]);
    $process->mustRun();

    $script = file_get_contents($installer);
    expect($script)->toContain('docker compose up -d --no-recreate')
        ->toContain('trap')
        ->not->toContain('eval ');
});

it('NW-04 resumes an actor-owned recent setup without extending an expired checkpoint', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    Http::preventStrayRequests();
    $manager = app(ManageNodeInstallation::class);

    $id = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    expect($manager->recent($fixture['actor']->id))->toBe($id);
    expect(fn () => $manager->recent($fixture['other']->id))->toThrow(AuthorizationException::class);

    Cache::forget('nw02:status:'.$id);
    expect($manager->recent($fixture['actor']->id))->toBeNull()
        ->and(Cache::get('nw02:last:'.$fixture['actor']->id))->toBeNull();
    Http::assertNothingSent();
});

it('NW-04 recovers launching state with read-only SSH status and never replays an installer', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    Http::preventStrayRequests();
    $manager = app(ManageNodeInstallation::class);
    $id = $manager->enqueue($fixture['actor']->id, $fixture['ready']);
    $state = Cache::get('nw02:status:'.$id);
    $state['state'] = 'launching';
    Cache::put('nw02:status:'.$id, $state, now()->addHour());

    $fake = Mockery::mock(NodeSshInstaller::class);
    $fake->shouldReceive('status')->once()->andReturn('running');
    $fake->shouldNotReceive('start');
    app()->instance(NodeSshInstaller::class, $fake);

    $result = app(ManageNodeInstallation::class)->status($fixture['actor']->id, $id);
    expect($result['state'])->toBe('running')
        ->and(Cache::get('nw02:access:'.$id))->toBeNull()
        ->and(app(ManageNodeInstallation::class)->recent($fixture['actor']->id))->toBe($id);
    Queue::assertPushed(InstallRemnawaveNode::class, 1);
    Http::assertNothingSent();
});

it('NW-04 retains the uncertain state when a recovery poll cannot reach the VPS', function (): void {
    $fixture = nw02Fixture();
    Queue::fake();
    Http::preventStrayRequests();
    $id = app(ManageNodeInstallation::class)->enqueue($fixture['actor']->id, $fixture['ready']);
    $state = Cache::get('nw02:status:'.$id);
    $state['state'] = 'uncertain';
    Cache::put('nw02:status:'.$id, $state, now()->addHour());

    $fake = Mockery::mock(NodeSshInstaller::class);
    $fake->shouldReceive('status')->once()->andThrow(new RuntimeException('SSH unavailable'));
    $fake->shouldNotReceive('start');
    app()->instance(NodeSshInstaller::class, $fake);

    expect(app(ManageNodeInstallation::class)->status($fixture['actor']->id, $id)['state'])->toBe('uncertain');
    Http::assertNothingSent();
});
