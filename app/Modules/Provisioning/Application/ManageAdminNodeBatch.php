<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * NODE-03: one confirmed, bounded batch over the existing Remnawave Nodes API.
 * Never trust client-provided Node IDs or selection snapshots without a fresh GET.
 */
final readonly class ManageAdminNodeBatch
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
        private ManageAdminNodes $nodes,
    ) {}

    /**
     * @param list<array<string, mixed>> $expected
     * @param list<string> $uuids Selected nodes; complete ordered set for reorder/restart-all
     * @param array<string,mixed> $data
     */
    public function perform(int $actorId, string $mode, array $expected, array $uuids, array $data = []): void
    {
        $operations = ['enable', 'disable', 'restart', 'reset', 'profile', 'update', 'reorder', 'restart-all'];
        if (! in_array($mode, $operations, true) || $uuids === [] || count($uuids) > 20
            || count(array_unique($uuids)) !== count($uuids) || count($expected) > 20) {
            throw ValidationException::withMessages(['nodes' => 'Invalid batch operation.']);
        }
        $this->authorizeWrite($actorId);

        $original = [];
        foreach ($expected as $node) {
            if (! $this->uuid($node['uuid'] ?? null) || ! is_string($node['name'] ?? null)
                || ! is_bool($node['connected'] ?? null) || ! is_bool($node['disabled'] ?? null)
                || isset($original[$node['uuid']])) {
                throw ValidationException::withMessages(['nodes' => 'Invalid node snapshot.']);
            }
            $original[$node['uuid']] = $node;
        }
        foreach ($uuids as $uuid) {
            if (! $this->uuid($uuid) || isset($original[$uuid]) === false) {
                throw ValidationException::withMessages(['nodes' => 'Invalid node selection.']);
            }
        }
        if ($mode === 'reorder' || $mode === 'restart-all') {
            if (count($original) !== count($uuids) || array_diff(array_keys($original), $uuids) !== []) {
                throw ValidationException::withMessages(['nodes' => 'Expected complete node list.']);
            }
        }

        $payload = $this->payload($mode, $data);
        Cache::lock('node03:batch:write', 60)->block(1, function () use ($actorId, $mode, $uuids, $original, $payload): void {
            $current = $this->nodes->all($actorId);
            $live = array_column($current, null, 'uuid');
            if (($mode === 'reorder' || $mode === 'restart-all') && count($current) !== count($original)) {
                throw ValidationException::withMessages(['nodes' => 'Node list changed.']);
            }
            foreach ($uuids as $uuid) {
                if (($live[$uuid] ?? null) !== $original[$uuid]) {
                    throw ValidationException::withMessages(['nodes' => 'Selected nodes changed.']);
                }
            }
            if ($mode === 'reorder' && array_column($current, 'uuid') !== array_keys($original)) {
                throw ValidationException::withMessages(['nodes' => 'Node order changed since selection.']);
            }
            if ($mode === 'reorder' && array_column($current, 'uuid') === $uuids) {
                throw ValidationException::withMessages(['nodes' => 'Node order has not changed.']);
            }

            if ($mode === 'profile') {
                $profileUuid = $payload['configProfile']['activeConfigProfileUuid'];
                if (! in_array($profileUuid, array_column($this->nodes->profiles($actorId), 'uuid'), true)) {
                    throw ValidationException::withMessages(['nodes' => 'Profile is unavailable.']);
                }
                $inbounds = array_column($this->nodes->inbounds($actorId, $profileUuid), 'uuid');
                foreach ($payload['configProfile']['activeInbounds'] as $inbound) {
                    if (! in_array($inbound, $inbounds, true)) {
                        throw ValidationException::withMessages(['nodes' => 'Inbound is unavailable.']);
                    }
                }
            }

            $this->authorizeWrite($actorId);
            $api = new RemnawaveNodesApi($this->factory->make());
            if ($mode === 'reorder') {
                $positions = [];
                foreach ($uuids as $index => $uuid) {
                    $positions[] = ['uuid' => $uuid, 'viewPosition' => $index];
                }
                $api->reorder($positions);
            } elseif ($mode === 'restart-all') {
                $api->restartAll();
            } elseif ($mode === 'profile') {
                $api->changeProfiles($uuids, $payload['configProfile']);
            } elseif ($mode === 'update') {
                $api->bulkUpdate($uuids, $payload);
            } else {
                $api->bulkAction($uuids, strtoupper($mode === 'reset' ? 'RESET_TRAFFIC' : $mode));
            }
            // A successful HTTP response acknowledges only submission, not changed node state.
            Log::info('node03.batch_request_accepted', ['actor_id' => $actorId, 'mode' => $mode, 'count' => count($uuids)]);
        });
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function payload(string $mode, array $data): array
    {
        if (! in_array($mode, ['profile', 'update'], true)) {
            if ($data !== []) {
                throw ValidationException::withMessages(['nodes' => 'Unexpected batch data.']);
            }

            return [];
        }
        if ($mode === 'profile') {
            $profile = $data['configProfile'] ?? null;
            if (array_keys($data) !== ['configProfile'] || ! is_array($profile)
                || ! $this->uuid($profile['activeConfigProfileUuid'] ?? null)
                || ! is_array($profile['activeInbounds'] ?? null)
                || $profile['activeInbounds'] === [] || count($profile['activeInbounds']) > 30
                || count(array_unique($profile['activeInbounds'])) !== count($profile['activeInbounds'])) {
                throw ValidationException::withMessages(['nodes' => 'Invalid profile selection.']);
            }
            foreach ($profile['activeInbounds'] as $uuid) {
                if (! $this->uuid($uuid)) {
                    throw ValidationException::withMessages(['nodes' => 'Invalid inbound identifier.']);
                }
            }

            return ['configProfile' => $profile];
        }

        if (count($data) !== 1) {
            throw ValidationException::withMessages(['nodes' => 'Choose one field to update.']);
        }
        $field = array_key_first($data);
        $value = $data[$field];
        $uuidFields = ['providerUuid', 'activePluginUuid'];
        if ($field === 'countryCode') {
            $valid = is_string($value) && preg_match('/\A[A-Z]{2}\z/D', $value) === 1;
        } elseif ($field === 'note') {
            $valid = is_string($value) && mb_strlen($value) <= 200 && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
        } elseif ($field === 'tags') {
            $valid = is_array($value) && array_is_list($value) && count($value) >= 1 && count($value) <= 10;
            foreach ($value as $tag) {
                $valid = $valid && is_string($tag) && preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $tag) === 1;
            }
            $valid = $valid && count(array_unique($value)) === count($value);
        } elseif (in_array($field, $uuidFields, true)) {
            $valid = $this->uuid($value);
        } elseif ($field === 'integrationUuids') {
            $valid = is_array($value) && array_is_list($value) && count($value) >= 1 && count($value) <= 20;
            foreach ($value as $uuid) {
                $valid = $valid && $this->uuid($uuid);
            }
            $valid = $valid && count(array_unique($value)) === count($value);
        } elseif (in_array($field, ['consumptionMultiplier', 'nodeConsumptionMultiplier'], true)) {
            $valid = (is_float($value) || is_int($value)) && is_finite((float) $value)
                && $value >= 0 && $value <= 100;
        } else {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['nodes' => 'Invalid batch update value.']);
        }

        return [$field => $value];
    }

    private function uuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $value) === 1;
    }

    private function authorizeWrite(int $actorId): void
    {
        $this->access->handle($actorId);
        if (config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['nodes' => 'Node batch operations disabled.']);
        }
    }
}
