<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * CFG-02: controlled profile lifecycle. Never accept raw Xray JSON from Telegram.
 */
final readonly class ManageAdminConfigProfiles
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
        private ManageAdminNodes $nodes,
        private ManageAdminHosts $hosts,
        private ReadAdminConfigProfiles $reader,
    ) {}

    public function validateName(int $actorId, string $name): void
    {
        $this->access->handle($actorId);
        if (trim($name) !== $name || trim($name) === '' || mb_strlen($name) > 64
            || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw ValidationException::withMessages(['profile' => 'Invalid profile name.']);
        }
    }

    /** @return array{uuid:string,name:string} */
    public function existing(int $actorId, string $uuid): array
    {
        $this->access->handle($actorId);
        if (! self::uuidValid($uuid)) {
            throw ValidationException::withMessages(['profile' => 'Invalid profile selection.']);
        }
        $matches = array_values(array_filter(
            $this->nodes->profiles($actorId),
            static fn (array $profile): bool => $profile['uuid'] === strtolower($uuid),
        ));
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['profile' => 'Profile no longer exists.']);
        }

        return $matches[0];
    }

    public function create(int $actorId, string $name): string
    {
        $this->validateName($actorId, $name);
        $this->authorizeWrite($actorId);

        return Cache::lock('profiles:mutation', 60)->block(1, function () use ($actorId, $name): string {
            $this->assertUniqueName($actorId, $name);
            $this->authorizeWrite($actorId);

            // Minimal inert Xray template. It has no listening inbound, secrets,
            // client credentials or Node attachment. Configure inbounds separately
            // in the trusted panel before attempting to assign it to Nodes.
            $config = [
                'log' => ['loglevel' => 'warning'],
                'inbounds' => [],
                'outbounds' => [['tag' => 'DIRECT', 'protocol' => 'freedom']],
            ];
            $response = $this->factory->make()->call('ConfigProfileController_createConfigProfile', body: [
                'name' => $name,
                'config' => $config,
            ]);
            $created = is_array($response) ? ($response['response'] ?? null) : null;
            if (! is_array($created) || ! self::uuidValid($created['uuid'] ?? null)) {
                throw new UnexpectedValueException('Profile creation outcome cannot be verified.');
            }

            return strtolower($created['uuid']);
        });
    }

    /** @param array{uuid:string,name:string} $expected */
    public function rename(int $actorId, array $expected, string $newName): void
    {
        $this->validateName($actorId, $newName);
        $this->authorizeWrite($actorId);
        Cache::lock('profiles:mutation', 60)->block(1, function () use ($actorId, $expected, $newName): void {
            $current = $this->existing($actorId, $expected['uuid']);
            if ($current !== $expected || $current['name'] === $newName) {
                throw ValidationException::withMessages(['profile' => 'Profile changed or name unchanged.']);
            }
            $this->assertUniqueName($actorId, $newName, $current['uuid']);
            $this->authorizeWrite($actorId);
            $this->factory->make()->call('ConfigProfileController_updateConfigProfile', body: [
                'uuid' => $current['uuid'],
                'name' => $newName,
            ]);
        });
    }

    /**
     * Deletion requires independently verified zero Node and Host references.
     * Unavailable or incomplete dependency data blocks deletion, never bypasses it.
     *
     * @param  array{uuid:string,name:string}  $expected
     */
    public function delete(int $actorId, array $expected): void
    {
        $this->authorizeWrite($actorId);

        // Compatible with existing Node/Host mutation locks: neither association
        // can be modified through our bot while these guards are held.
        Cache::lock('nodes:mutation', 60)->block(1, function () use ($actorId, $expected): void {
            Cache::lock('hosts:mutation', 60)->block(1, function () use ($actorId, $expected): void {
                Cache::lock('profiles:mutation', 60)->block(1, function () use ($actorId, $expected): void {
                    $current = $this->existing($actorId, $expected['uuid']);
                    if ($current !== $expected) {
                        throw ValidationException::withMessages(['profile' => 'Profile changed.']);
                    }
                    $detail = $this->reader->detail($actorId, $current['uuid']);
                    if ($detail === null || $detail['name'] !== $current['name']
                        || $detail['linkedNodes'] === null || $detail['linkedNodes'] !== []) {
                        throw ValidationException::withMessages(['profile' => 'Node dependencies cannot be ruled out.']);
                    }
                    // All Hosts must have explicit profile UUID bindings. If any
                    // record is unbound/unknown, absence of references is unproven.
                    foreach ($this->hosts->all($actorId) as $host) {
                        if ($host['profileUuid'] === null || $host['profileUuid'] === $current['uuid']) {
                            throw ValidationException::withMessages(['profile' => 'Host dependencies cannot be ruled out.']);
                        }
                    }
                    $this->authorizeWrite($actorId);
                    $this->factory->make()->call('ConfigProfileController_deleteConfigProfileByUuid',
                        pathParameters: ['uuid' => $current['uuid']]);
                });
            });
        });
    }

    private function assertUniqueName(int $actorId, string $name, ?string $exceptUuid = null): void
    {
        foreach ($this->nodes->profiles($actorId) as $profile) {
            if ($profile['uuid'] !== $exceptUuid && mb_strtolower($profile['name']) === mb_strtolower($name)) {
                throw ValidationException::withMessages(['profile' => 'Profile name already exists.']);
            }
        }
    }

    private function authorizeWrite(int $actorId): void
    {
        $this->access->handle($actorId);
        if (config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['profile' => 'Provider writes are disabled.']);
        }
    }

    private static function uuidValid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D',
            $value,
        ) === 1;
    }
}
