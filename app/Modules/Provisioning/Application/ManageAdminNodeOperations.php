<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * NODE-02: one explicitly confirmed mutation of one existing panel Node.
 *
 * Neither the Telegram ID nor the requested UUID grants access. Authorization,
 * the global write gate and the remote Node snapshot are checked before writes.
 * No mutation is retried after an ambiguous provider response.
 */
final readonly class ManageAdminNodeOperations
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
        private ManageAdminNodes $nodes,
    ) {}

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>  $changes
     */
    public function perform(int $actorId, string $mode, array $expected, array $changes = []): void
    {
        if (! in_array($mode, ['edit', 'enable', 'disable', 'restart', 'reset', 'delete'], true)
            || ! is_string($expected['uuid'] ?? null)
            || ! is_string($expected['name'] ?? null)
            || ! is_bool($expected['connected'] ?? null)
            || ! is_bool($expected['disabled'] ?? null)) {
            throw ValidationException::withMessages(['node' => 'Invalid node operation.']);
        }

        $this->authorizeWrite($actorId);
        if ($mode === 'edit') {
            if (array_keys($changes) !== ['name', 'address']
                || ! is_string($changes['name'] ?? null)
                || ! is_string($changes['address'] ?? null)) {
                throw ValidationException::withMessages(['node' => 'Invalid node changes.']);
            }
            $this->nodes->validateIdentity($actorId, $changes['name'], $changes['address']);
        } elseif ($changes !== []) {
            throw ValidationException::withMessages(['node' => 'Unexpected node changes.']);
        }

        // One in-flight operation per Node, even if two Super Admins press
        // different confirmation buttons. Lock expiry covers the HTTP timeout.
        Cache::lock('nodes:mutation', 60)->block(1, function () use ($actorId, $mode, $expected, $changes): void {
            $actual = $this->nodes->one($actorId, $expected['uuid']);
            if ($actual !== $expected) {
                throw ValidationException::withMessages(['node' => 'Node changed since confirmation.']);
            }
            if ($mode === 'enable' && ! $actual['disabled']
                || $mode === 'disable' && $actual['disabled']) {
                throw ValidationException::withMessages(['node' => 'Node already has this status.']);
            }
            if ($mode === 'delete' && ! $actual['disabled']) {
                throw ValidationException::withMessages(['node' => 'Disable the node before deleting it.']);
            }

            $this->authorizeWrite($actorId);
            $api = new RemnawaveNodesApi($this->factory->make());

            // Send at most one request. Any timeout, malformed response or
            // transport failure is uncertain and must never trigger a retry.
            if ($mode === 'edit') {
                $response = $api->update($actual['uuid'], $changes);
                $this->assertIdentity($response, $actual['uuid']);
            } elseif ($mode === 'enable') {
                $response = $api->enable($actual['uuid']);
                $this->assertIdentity($response, $actual['uuid']);
            } elseif ($mode === 'disable') {
                $response = $api->disable($actual['uuid']);
                $this->assertIdentity($response, $actual['uuid']);
            } elseif ($mode === 'restart') {
                $api->restart($actual['uuid']);
            } elseif ($mode === 'reset') {
                $api->resetTraffic($actual['uuid']);
            } else {
                $api->delete($actual['uuid']);
            }

            // A 202 or 204 only acknowledges the request; live execution must
            // be confirmed by subsequent panel reads, not this audit entry.
            Log::info('node02.operator_request_accepted', ['actor_id' => $actorId, 'mode' => $mode]);
        });
    }

    /**
     * Change only the address of an existing Node. UUID, management port,
     * Config Profile and every active inbound must remain unchanged.
     *
     * @param array{uuid:string,name:string,address:string,port:int,connected:bool,disabled:bool,profileUuid:string,inboundUuids:list<string>} $expected
     */
    public function replaceAddress(int $actorId, array $expected, string $address): void
    {
        if (! is_string($expected['uuid'] ?? null)
            || ! is_string($expected['name'] ?? null)
            || ! is_string($expected['address'] ?? null)
            || ($expected['port'] ?? null) !== 2222
            || ($expected['connected'] ?? null) !== true
            || ($expected['disabled'] ?? null) !== false
            || ! is_string($expected['profileUuid'] ?? null)
            || ! is_array($expected['inboundUuids'] ?? null)
            || $expected['inboundUuids'] === []) {
            throw ValidationException::withMessages(['node' => 'The current Node is not eligible for replacement.']);
        }

        $this->authorizeWrite($actorId);
        $this->nodes->validateReplacementAddress($actorId, $expected['uuid'], $address, $expected['port']);

        Cache::lock('nodes:mutation', 60)->block(1, function () use ($actorId, $expected, $address): void {
            $actual = $this->nodes->replacementSnapshot($actorId, $expected['uuid']);
            if ($actual !== $expected || ! $actual['connected'] || $actual['disabled']) {
                throw ValidationException::withMessages(['node' => 'Node changed since replacement confirmation.']);
            }

            $this->nodes->validateReplacementAddress($actorId, $actual['uuid'], $address, $actual['port']);
            $this->authorizeWrite($actorId);
            $api = new RemnawaveNodesApi($this->factory->make());
            $response = $api->update($actual['uuid'], ['address' => $address]);
            $this->assertIdentity($response, $actual['uuid']);

            $updated = $this->nodes->replacementSnapshot($actorId, $actual['uuid']);
            if ($updated['uuid'] !== $actual['uuid']
                || $updated['name'] !== $actual['name']
                || $updated['address'] !== $address
                || $updated['port'] !== $actual['port']
                || $updated['profileUuid'] !== $actual['profileUuid']
                || $updated['inboundUuids'] !== $actual['inboundUuids']) {
                throw new UnexpectedValueException('Node address update could not be verified without configuration drift.');
            }

            Log::info('node06.replacement_address_updated', ['actor_id' => $actorId]);
        });
    }

    private function authorizeWrite(int $actorId): void
    {
        $this->access->handle($actorId);
        if (config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['node' => 'Node operations are disabled.']);
        }
    }

    /** @param array<string, mixed> $response */
    private function assertIdentity(array $response, string $expectedUuid): void
    {
        if (! is_string($response['uuid'] ?? null)
            || strtolower($response['uuid']) !== strtolower($expectedUuid)) {
            throw new UnexpectedValueException('Node operation response identity mismatch.');
        }
    }
}
