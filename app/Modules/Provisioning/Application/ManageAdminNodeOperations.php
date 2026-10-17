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
