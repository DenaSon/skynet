<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Jobs\InstallRemnawaveNode;
use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\NodeSetup\NodeSshInstaller;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * NW-02: reserve a verified VPS, enqueue only an opaque ID and expose safe status.
 * Existing Remnawave Node/Host writes are strictly out of scope.
 */
final readonly class ManageNodeInstallation
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
        private NodeSshInstaller $installer,
    ) {}

    /** @param array<string, mixed> $ready */
    public function enqueue(int $actorId, array $ready): string
    {
        $this->access->handle($actorId);
        $this->guard();
        if (config('queue.default') === 'sync') {
            throw new RuntimeException('Asynchronous queue is required.');
        }

        $ip = $ready['ip'] ?? null;
        if (! is_string($ip) || ($ready['username'] ?? null) !== 'root'
            || ! is_string($ready['secret'] ?? null)
            || ! is_string($ready['host']['knownHost'] ?? null)) {
            throw new RuntimeException('Invalid verified root SSH session.');
        }
        $identifier = bin2hex(random_bytes(16));
        $reservation = 'nw02:host:'.$ip;
        if (! Cache::add($reservation, $identifier, now()->addHours(3))) {
            throw new RuntimeException('VPS installation already reserved.');
        }
        try {
            $access = [
                'owner' => $actorId,
                'ip' => $ip,
                'port' => $ready['port'],
                'username' => $ready['username'],
                'secret' => $ready['secret'],
                'knownHost' => $ready['host']['knownHost'],
            ];
            Cache::put('nw02:access:'.$identifier, Crypt::encryptString(json_encode($access, JSON_THROW_ON_ERROR)), now()->addHours(2));
            Cache::put('nw02:status:'.$identifier, [
                'owner' => $actorId,
                'ip' => $ip,
                'name' => $ready['name'],
                'profileUuid' => $ready['profile']['uuid'],
                'inboundUuid' => $ready['inbound']['uuid'],
                'state' => 'queued',
                ...((isset($ready['dnsName'], $ready['dnsZone'], $ready['dnsZoneId']))
                    ? [
                        'dnsName' => $ready['dnsName'],
                        'dnsZone' => $ready['dnsZone'],
                        'dnsZoneId' => $ready['dnsZoneId'],
                    ]
                    : []),
            ], now()->addHours(3));
            InstallRemnawaveNode::dispatch($identifier);
            // Non-secret pointer for returning to the latest setup from Node management.
            Cache::put('nw02:last:'.$actorId, $identifier, now()->addHours(3));
        } catch (Throwable) {
            Cache::forget('nw02:access:'.$identifier);
            Cache::forget('nw02:status:'.$identifier);
            Cache::forget($reservation);
            throw new RuntimeException('Could not enqueue Node setup.');
        }

        return $identifier;
    }

    /** Called once by a single-attempt queue job. Never retry uncertain remote starts. */
    public function launch(string $identifier): void
    {
        $state = $this->meta($identifier);
        if (($state['state'] ?? null) !== 'queued') {
            return;
        }
        try {
            $this->guard();
            $access = $this->credentials($identifier);
            $this->access->handle($state['owner']);
            $state['state'] = 'launching';
            $this->persist($identifier, $state);
            $response = $this->factory->make()->call('KeygenController_generateKey');
            $secret = is_array($response) ? ($response['response']['secretKey'] ?? null) : null;
            if (! is_string($secret)) {
                throw new RuntimeException('Invalid keygen API response.');
            }
            $this->installer->start(
                $access['ip'], $access['port'], $access['username'],
                Crypt::decryptString($access['secret']), $access['knownHost'], $secret,
            );
            $state['state'] = 'started';
            $this->persist($identifier, $state);
        } catch (Throwable) {
            // SSH might have started the detached task despite a lost response.
            // Never enqueue the same operation automatically a second time.
            $state['state'] = 'uncertain';
            $this->persist($identifier, $state);
        }
    }

    /** @return array<string,mixed> */
    public function status(int $actorId, string $identifier): array
    {
        $this->access->handle($actorId);
        $state = $this->meta($identifier);
        if ($state['owner'] !== $actorId) {
            throw new RuntimeException('Installation belongs to another operator.');
        }
        if (in_array($state['state'], ['launching', 'started', 'installing', 'starting', 'pending', 'uncertain'], true)) {
            try {
                $access = $this->credentials($identifier);
                $remote = $this->installer->status(
                    $access['ip'], $access['port'], $access['username'],
                    Crypt::decryptString($access['secret']), $access['knownHost'],
                );
                if ($remote !== 'unknown') {
                    $state['state'] = $remote;
                    $this->persist($identifier, $state);
                    if ($remote === 'running') {
                        Cache::forget('nw02:access:'.$identifier);
                    }
                }
            } catch (Throwable) {
                // Keep the previous status. A failed poll is not an install failure.
            }
        }

        return [
            'id' => $identifier,
            'name' => $state['name'],
            'state' => $state['state'],
        ];
    }

    /**
     * NW-04: one recoverable recent installation link, without secret material,
     * remote calls or extending the three-hour NW-02 checkpoint lifetime.
     */
    public function recent(int $actorId): ?string
    {
        $this->access->handle($actorId);
        $identifier = Cache::get('nw02:last:'.$actorId);
        if (! is_string($identifier)) {
            return null;
        }

        try {
            $state = $this->meta($identifier);
            if ($state['owner'] === $actorId) {
                // A ready Node may have been registered by the separate manual wizard.
                if ($this->matchesConnectedReadyNode($state)) {
                    $this->closeRecent($actorId, $identifier);

                    return null;
                }

                return $identifier;
            }
        } catch (Throwable) {
            // An expired or malformed bookmark is not a resumable setup.
        }

        Cache::forget('nw02:last:'.$actorId);

        return null;
    }

    /**
     * Read-only reconciliation for a Node registered through the independent
     * ready-Node wizard. Never assume completion from an installation state.
     */
    public function matchingConnectedNode(int $actorId, string $identifier): bool
    {
        $this->access->handle($actorId);
        $state = $this->meta($identifier);
        if ($state['owner'] !== $actorId) {
            throw new RuntimeException('Installation belongs to another operator.');
        }

        return $this->matchesConnectedReadyNode($state);
    }

    /**
     * Close only the owner's exact recent bookmark. Preserve the installation
     * checkpoint, history and any in-flight job; never mutate VPS or Provider.
     */
    public function closeRecent(int $actorId, string $identifier): bool
    {
        $this->access->handle($actorId);
        $state = $this->meta($identifier);
        if ($state['owner'] !== $actorId
            || Cache::get('nw02:last:'.$actorId) !== $identifier) {
            return false;
        }

        Cache::forget('nw02:last:'.$actorId);

        return true;
    }

    /** @param array<string,mixed> $state */
    private function matchesConnectedReadyNode(array $state): bool
    {
        if (($state['state'] ?? null) !== 'running'
            || isset($state['nodeStage'])
            || ! is_string($state['name'] ?? null)
            || ! is_string($state['ip'] ?? null)) {
            return false;
        }

        try {
            foreach ((new RemnawaveNodesApi($this->factory->make()))->all() as $node) {
                if (($node['name'] ?? null) === $state['name']
                    && ($node['address'] ?? null) === $state['ip']
                    && ($node['port'] ?? null) === 2222
                    && ($node['isConnected'] ?? null) === true
                    && ($node['isDisabled'] ?? null) === false) {
                    return true;
                }
            }
        } catch (Throwable) {
            // An unavailable or incomplete Provider response must not hide setup.
        }

        return false;
    }

    /**
     * NW-03: non-secret installation snapshot for the existing Node/Host actions.
     * Only a currently trusted operator can access their own completed setup.
     *
     * @return array<string,mixed>
     */
    public function context(int $actorId, string $identifier): array
    {
        $this->access->handle($actorId);
        $state = $this->meta($identifier);
        if ($state['owner'] !== $actorId || $state['state'] !== 'running') {
            throw new RuntimeException('Installation is not verified as running or is not owned by actor.');
        }

        return $state;
    }

    /**
     * NW-03: update only the non-secret setup checkpoint under a separate
     * per-installation lock used by the registration coordinator.
     *
     * @param  array<string,mixed>  $changes
     */
    public function checkpoint(int $actorId, string $identifier, array $changes): void
    {
        $state = $this->context($actorId, $identifier);
        foreach ($changes as $key => $value) {
            if (! in_array($key, ['nodeStage', 'nodeUuid', 'hostStage', 'hostUuid', 'dnsStage'], true)
                || ($key === 'dnsStage' && ! in_array($value, ['uncertain', 'verified'], true))) {
                throw new RuntimeException('Unsupported setup checkpoint field.');
            }
            $state[$key] = $value;
        }
        $this->persist($identifier, $state);
    }

    /** @return array<string,mixed> */
    private function meta(string $id): array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
            throw new RuntimeException('Invalid installation ID.');
        }
        $state = Cache::get('nw02:status:'.$id);
        if (! is_array($state) || ! is_int($state['owner'] ?? null)) {
            throw new RuntimeException('Installation status expired.');
        }

        return $state;
    }

    /** @return array<string,mixed> */
    private function credentials(string $id): array
    {
        $ciphertext = Cache::get('nw02:access:'.$id);
        if (! is_string($ciphertext)) {
            throw new RuntimeException('Installation credential expired.');
        }
        $credentials = json_decode(Crypt::decryptString($ciphertext), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($credentials)) {
            throw new RuntimeException('Invalid installation credential.');
        }

        return $credentials;
    }

    /** @param array<string,mixed> $state */
    private function persist(string $id, array $state): void
    {
        Cache::put('nw02:status:'.$id, $state, now()->addHours(3));
    }

    private function guard(): void
    {
        if (config('services.remnawave.node_ssh_setup_enabled') !== true
            || config('services.remnawave.node_ssh_install_enabled') !== true) {
            throw new RuntimeException('Automated Node installation is disabled.');
        }
    }
}
