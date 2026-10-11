<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Internal, version-pinned Remnawave NodesController adapter (15 operations).
 *
 * CAUTION: Mutations are deliberately NOT exposed through Telegram/HTTP.
 * Application-layer authorization, confirmation, audit and reconciliation
 * must precede any operator-facing write action. No implicit retries.
 */
final readonly class RemnawaveNodesApi
{
    public function __construct(private RemnawaveApiClient $client) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->items('NodesController_getNodes');
    }

    /**
     * @return array<string, mixed>
     */
    public function one(string $uuid): array
    {
        return $this->object('NodesController_getNode', ['uuid' => self::uuid($uuid)]);
    }

    /**
     * @return array<mixed>
     */
    public function tags(): array
    {
        return $this->response('NodesController_getNodesTags');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        self::assertFields($data, ['name', 'address', 'configProfile'], [
            'name', 'address', 'port', 'proxyUrl', 'isTrafficTrackingActive',
            'trafficLimitBytes', 'notifyPercent', 'trafficResetDay', 'countryCode',
            'consumptionMultiplier', 'nodeConsumptionMultiplier', 'configProfile',
            'providerUuid', 'tags', 'activePluginUuid', 'integrationUuids', 'note', 'ips',
        ]);
        self::assertNodeBasics($data);

        return $this->object('NodesController_createNode', body: $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data): array
    {
        if (array_key_exists('uuid', $data)) {
            throw new InvalidArgumentException('Node UUID must be supplied as a separate argument.');
        }

        self::assertFields($data, [], [
            'name', 'address', 'port', 'proxyUrl', 'isTrafficTrackingActive',
            'trafficLimitBytes', 'notifyPercent', 'trafficResetDay', 'countryCode',
            'consumptionMultiplier', 'nodeConsumptionMultiplier', 'configProfile',
            'providerUuid', 'tags', 'activePluginUuid', 'integrationUuids', 'note', 'ips',
        ]);

        if ($data === []) {
            throw new InvalidArgumentException('Node update requires at least one changed field.');
        }

        self::assertNodeBasics($data);

        return $this->object('NodesController_updateNode', body: ['uuid' => self::uuid($uuid), ...$data]);
    }

    /**
     * @return array<string, mixed>
     */
    public function enable(string $uuid): array
    {
        return $this->object('NodesController_enableNode', ['uuid' => self::uuid($uuid)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $uuid): array
    {
        return $this->object('NodesController_disableNode', ['uuid' => self::uuid($uuid)]);
    }

    /** 202 Accepted is acknowledgement, not proof of a successful restart. */
    public function restart(string $uuid, bool $forceRestart = false): void
    {
        $this->client->call(
            'NodesController_restartNode',
            pathParameters: ['uuid' => self::uuid($uuid)],
            body: ['forceRestart' => $forceRestart],
        );
    }

    public function restartAll(bool $forceRestart = false): void
    {
        $this->client->call('NodesController_restartAllNodes', body: ['forceRestart' => $forceRestart]);
    }

    public function resetTraffic(string $uuid): void
    {
        $this->client->call('NodesController_resetNodeTraffic', ['uuid' => self::uuid($uuid)]);
    }

    public function delete(string $uuid): void
    {
        $this->client->call('NodesController_deleteNode', ['uuid' => self::uuid($uuid)]);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<mixed>
     */
    public function reorder(array $nodes): array
    {
        if ($nodes === []) {
            throw new InvalidArgumentException('Node reorder list cannot be empty.');
        }

        foreach ($nodes as $node) {
            if (! is_int($node['viewPosition'] ?? null)) {
                throw new InvalidArgumentException('Node reorder requires a numeric viewPosition.');
            }

            self::uuid($node['uuid'] ?? null);
        }

        return $this->response('NodesController_reorderNodes', body: ['nodes' => $nodes]);
    }

    /**
     * @param  list<string>  $uuids
     * @param  array<string, mixed>  $configProfile
     */
    public function changeProfiles(array $uuids, array $configProfile): void
    {
        self::assertConfigProfile($configProfile);

        $this->client->call(
            'NodesController_profileModification',
            body: ['uuids' => self::uuids($uuids), 'configProfile' => $configProfile],
        );
    }

    /**
     * @param  list<string>  $uuids
     */
    public function bulkAction(array $uuids, string $action): void
    {
        if (! in_array($action, ['ENABLE', 'DISABLE', 'RESTART', 'RESET_TRAFFIC'], true)) {
            throw new InvalidArgumentException('Unknown bulk node action.');
        }

        $this->client->call(
            'NodesController_bulkNodesActions',
            body: ['uuids' => self::uuids($uuids), 'action' => $action],
        );
    }

    /**
     * @param  list<string>  $uuids
     * @param  array<string, mixed>  $fields
     */
    public function bulkUpdate(array $uuids, array $fields): void
    {
        self::assertFields($fields, [], [
            'countryCode', 'consumptionMultiplier', 'nodeConsumptionMultiplier',
            'providerUuid', 'tags', 'activePluginUuid', 'integrationUuids', 'note',
        ]);

        if ($fields === []) {
            throw new InvalidArgumentException('Bulk update requires fields.');
        }

        $this->client->call(
            'NodesController_bulkNodesUpdate',
            body: ['uuids' => self::uuids($uuids), 'fields' => $fields],
        );
    }

    /**
     * @param  array<string, int|string>  $path
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>
     */
    private function response(string $operation, array $path = [], ?array $body = null): array
    {
        $result = $this->client->call($operation, pathParameters: $path, body: $body);

        if (! is_array($result)
            || ! array_key_exists('response', $result)
            || ! is_array($result['response'])) {
            throw new UnexpectedValueException('Invalid Remnawave node response envelope.');
        }

        return $result['response'];
    }

    /**
     * @param  array<string, int|string>  $path
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    private function object(string $operation, array $path = [], ?array $body = null): array
    {
        $result = $this->response($operation, $path, $body);

        if (array_is_list($result)) {
            throw new UnexpectedValueException('Expected Remnawave node object.');
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(string $operation): array
    {
        $result = $this->response($operation);

        if (! array_is_list($result)) {
            throw new UnexpectedValueException('Expected Remnawave nodes list.');
        }

        foreach ($result as $node) {
            if (! is_array($node)) {
                throw new UnexpectedValueException('Malformed Remnawave node item.');
            }
        }

        return $result;
    }

    private static function uuid(mixed $uuid): string
    {
        if (! is_string($uuid)
            || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
            throw new InvalidArgumentException('A valid Remnawave node UUID is required.');
        }

        return $uuid;
    }

    /**
     * @param  array<mixed>  $uuids
     * @return list<string>
     */
    private static function uuids(array $uuids): array
    {
        if ($uuids === [] || ! array_is_list($uuids)) {
            throw new InvalidArgumentException('At least one node UUID is required.');
        }

        $validated = [];

        foreach ($uuids as $uuid) {
            $validated[] = self::uuid($uuid);
        }

        if (count(array_unique($validated)) !== count($validated)) {
            throw new InvalidArgumentException('Duplicate node UUID.');
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $required
     * @param  list<string>  $allowed
     */
    private static function assertFields(array $data, array $required, array $allowed): void
    {
        foreach ($required as $name) {
            if (! array_key_exists($name, $data) || $data[$name] === null) {
                throw new InvalidArgumentException("Missing required node field: {$name}");
            }
        }

        foreach (array_keys($data) as $name) {
            if (! in_array($name, $allowed, true)) {
                throw new InvalidArgumentException("Unsupported node field: {$name}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertNodeBasics(array $data): void
    {
        foreach (['name', 'address'] as $field) {
            if (array_key_exists($field, $data) && (! is_string($data[$field]) || trim($data[$field]) === '')) {
                throw new InvalidArgumentException("Node {$field} must be a non-empty string.");
            }
        }

        if (array_key_exists('port', $data)
            && (! is_int($data['port']) || $data['port'] < 1 || $data['port'] > 65535)) {
            throw new InvalidArgumentException('Invalid node port.');
        }

        if (array_key_exists('configProfile', $data)) {
            if (! is_array($data['configProfile'])) {
                throw new InvalidArgumentException('Invalid node config profile.');
            }

            self::assertConfigProfile($data['configProfile']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function assertConfigProfile(array $data): void
    {
        self::assertFields(
            $data,
            ['activeConfigProfileUuid', 'activeInbounds'],
            ['activeConfigProfileUuid', 'activeInbounds'],
        );
        self::uuid($data['activeConfigProfileUuid']);

        if (! is_array($data['activeInbounds']) || ! array_is_list($data['activeInbounds'])) {
            throw new InvalidArgumentException('Invalid active inbounds list.');
        }

        foreach ($data['activeInbounds'] as $uuid) {
            self::uuid($uuid);
        }
    }
}
