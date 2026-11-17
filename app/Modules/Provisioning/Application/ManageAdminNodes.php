<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * NODE-01: small trusted Admin boundary over existing NodesController.
 * No raw provider response or credentials reach Telegram.
 */
final readonly class ManageAdminNodes
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
    ) {}

    /** @return list<array{uuid:string,name:string,connected:bool,disabled:bool}> */
    public function all(int $actorId): array
    {
        $this->access->handle($actorId);
        $nodes = (new RemnawaveNodesApi($this->factory->make()))->all();
        if (count($nodes) > 500) {
            throw new UnexpectedValueException('Node list exceeds the bounded Admin view.');
        }

        return array_map(fn (array $node): array => $this->safeNode($node), $nodes);
    }

    /** @return array{uuid:string,name:string,connected:bool,disabled:bool} */
    public function one(int $actorId, string $uuid): array
    {
        $this->access->handle($actorId);
        self::uuid($uuid);
        $node = (new RemnawaveNodesApi($this->factory->make()))->one($uuid);
        $safe = $this->safeNode($node);
        if (strtolower($safe['uuid']) !== strtolower($uuid)) {
            throw new UnexpectedValueException('Node identity mismatch.');
        }

        return $safe;
    }


    /**
     * Read-only replacement snapshot. The complete active profile and inbound
     * set are captured so a replacement never silently narrows configuration.
     *
     * @return array{uuid:string,name:string,address:string,port:int,connected:bool,disabled:bool,profileUuid:string,inboundUuids:list<string>}
     */
    public function replacementSnapshot(int $actorId, string $uuid): array
    {
        $this->access->handle($actorId);
        self::uuid($uuid);
        $node = (new RemnawaveNodesApi($this->factory->make()))->one($uuid);
        $safe = $this->safeNode($node);
        $address = $node['address'] ?? null;
        $port = $node['port'] ?? null;
        $profile = $node['configProfile'] ?? null;
        $profileUuid = is_array($profile) ? ($profile['activeConfigProfileUuid'] ?? null) : null;
        $inbounds = is_array($profile) ? ($profile['activeInbounds'] ?? null) : null;

        if ($safe['uuid'] !== strtolower($uuid)
            || ! is_string($address) || trim($address) !== $address || strlen($address) > 253
            || (filter_var($address, FILTER_VALIDATE_IP) === false
                && filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
            || ! is_int($port) || $port < 1 || $port > 65535
            || ! self::uuidValid($profileUuid)
            || ! is_array($inbounds) || ! array_is_list($inbounds) || $inbounds === [] || count($inbounds) > 30) {
            throw new UnexpectedValueException('Node replacement snapshot is incomplete.');
        }

        $inboundUuids = [];
        foreach ($inbounds as $inbound) {
            $inboundUuid = is_array($inbound) ? ($inbound['uuid'] ?? null) : null;
            if (! self::uuidValid($inboundUuid)) {
                throw new UnexpectedValueException('Node replacement inbound snapshot is invalid.');
            }
            $inboundUuids[] = strtolower($inboundUuid);
        }
        if (count(array_unique($inboundUuids)) !== count($inboundUuids)) {
            throw new UnexpectedValueException('Node replacement inbound snapshot is duplicated.');
        }

        return [
            ...$safe,
            'address' => $address,
            'port' => $port,
            'profileUuid' => strtolower($profileUuid),
            'inboundUuids' => $inboundUuids,
        ];
    }

    /**
     * Update only a direct-IP Node address. Domain addresses are intentionally
     * preserved and moved through their Cloudflare A records instead.
     *
     * @param array{uuid:string,name:string,address:string,port:int,connected:bool,disabled:bool,profileUuid:string,inboundUuids:list<string>} $expected
     * @return array{uuid:string,name:string,address:string,port:int,connected:bool,disabled:bool,profileUuid:string,inboundUuids:list<string>}
     */
    public function updateReplacementAddress(int $actorId, array $expected, string $address): array
    {
        $this->access->handle($actorId);
        $this->authorizeWrite($actorId);
        if (filter_var($expected['address'], FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw ValidationException::withMessages(['node' => 'Only a direct-IP Node can change its address in this operation.']);
        }
        $this->validateIdentity($actorId, $expected['name'], $address);

        return Cache::lock('nodes:mutation', 60)->block(1, function () use ($actorId, $expected, $address): array {
            $current = $this->replacementSnapshot($actorId, self::uuid($expected['uuid']));
            if (self::replacementIdentity($current) !== self::replacementIdentity($expected)) {
                throw ValidationException::withMessages(['node' => 'Node changed since replacement preview.']);
            }
            foreach ((new RemnawaveNodesApi($this->factory->make()))->all() as $node) {
                if (($node['uuid'] ?? null) !== $expected['uuid'] && ($node['address'] ?? null) === $address) {
                    throw ValidationException::withMessages(['node' => 'The new IP is already assigned to another Node.']);
                }
            }

            $this->authorizeWrite($actorId);
            $response = (new RemnawaveNodesApi($this->factory->make()))->update(self::uuid($expected['uuid']), ['address' => $address]);
            if (! self::uuidValid($response['uuid'] ?? null)
                || strtolower($response['uuid']) !== strtolower($expected['uuid'])) {
                throw new UnexpectedValueException('Node address update identity could not be verified.');
            }

            $updated = $this->replacementSnapshot($actorId, $expected['uuid']);
            if ($updated['address'] !== $address
                || self::replacementIdentity($updated, includeAddress: false)
                    !== self::replacementIdentity($expected, includeAddress: false)) {
                throw new UnexpectedValueException('Node address update changed protected configuration.');
            }

            return $updated;
        });
    }

    /** @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private static function replacementIdentity(array $node, bool $includeAddress = true): array
    {
        $identity = [
            'uuid' => $node['uuid'] ?? null,
            'name' => $node['name'] ?? null,
            'port' => $node['port'] ?? null,
            'disabled' => $node['disabled'] ?? null,
            'profileUuid' => $node['profileUuid'] ?? null,
            'inboundUuids' => $node['inboundUuids'] ?? null,
        ];
        if ($includeAddress) {
            $identity['address'] = $node['address'] ?? null;
        }

        return $identity;
    }

    /** @return list<string> */
    public function tags(int $actorId): array
    {
        $this->access->handle($actorId);
        $result = (new RemnawaveNodesApi($this->factory->make()))->tags();
        $tags = $result['tags'] ?? null;
        if (! is_array($tags) || ! array_is_list($tags) || count($tags) > 100) {
            throw new UnexpectedValueException('Invalid node tags.');
        }

        $safe = [];
        foreach ($tags as $tag) {
            if (! is_string($tag) || ! self::label($tag, 40)) {
                throw new UnexpectedValueException('Invalid node tag.');
            }
            $safe[] = $tag;
        }

        return $safe;
    }

    /** @return list<array{uuid:string,name:string}> */
    public function profiles(int $actorId): array
    {
        $this->access->handle($actorId);
        $response = $this->factory->make()->call('ConfigProfileController_getConfigProfiles');
        $profiles = is_array($response) ? ($response['response']['configProfiles'] ?? null) : null;
        if (! is_array($profiles) || ! array_is_list($profiles) || count($profiles) > 30) {
            throw new UnexpectedValueException('Invalid config profile list.');
        }

        $result = [];
        foreach ($profiles as $profile) {
            if (! is_array($profile) || ! self::uuidValid($profile['uuid'] ?? null)
                || ! self::label($profile['name'] ?? null, 80)) {
                throw new UnexpectedValueException('Invalid config profile.');
            }
            $result[] = ['uuid' => strtolower($profile['uuid']), 'name' => $profile['name']];
        }

        return $result;
    }

    /** @return list<array{uuid:string,name:string}> */
    public function inbounds(int $actorId, string $profileUuid): array
    {
        $this->access->handle($actorId);
        self::uuid($profileUuid);
        $response = $this->factory->make()->call('ConfigProfileController_getInboundsByProfileUuid', ['uuid' => $profileUuid]);
        $inbounds = is_array($response) ? ($response['response']['inbounds'] ?? null) : null;
        if (! is_array($inbounds) || ! array_is_list($inbounds) || count($inbounds) > 30) {
            throw new UnexpectedValueException('Invalid profile inbound list.');
        }

        $result = [];
        foreach ($inbounds as $inbound) {
            if (! is_array($inbound) || ! self::uuidValid($inbound['uuid'] ?? null)
                || ! self::label($inbound['tag'] ?? null, 80)
                || (isset($inbound['profileUuid']) && strtolower((string) $inbound['profileUuid']) !== strtolower($profileUuid))) {
                throw new UnexpectedValueException('Invalid profile inbound.');
            }
            $result[] = ['uuid' => strtolower($inbound['uuid']), 'name' => $inbound['tag']];
        }

        return $result;
    }

    public function validateIdentity(int $actorId, string $name, string $address): void
    {
        $this->access->handle($actorId);
        if (! self::label($name, 64) || strlen($address) > 253
            || (filter_var($address, FILTER_VALIDATE_IP) === false
                && filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
            throw ValidationException::withMessages(['node' => 'Invalid name or server address.']);
        }
    }

    /**
     * Explicit one-shot operator action; caller consumes its one-use token before calling.
     * An uncertain POST is never retried automatically. Operator must recheck the list.
     *
     * @param  list<string>  $inboundUuids
     */
    public function create(int $actorId, string $name, string $address, string $profileUuid, array $inboundUuids, int $port): string
    {
        $this->validateIdentity($actorId, $name, $address);
        $this->authorizeWrite($actorId);
        if ($port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['node' => 'Invalid Node management port.']);
        }
        self::uuid($profileUuid);
        if ($inboundUuids === [] || count($inboundUuids) > 30) {
            throw ValidationException::withMessages(['node' => 'Choose at least one inbound.']);
        }

        $chosen = [];
        foreach ($inboundUuids as $uuid) {
            $chosen[] = self::uuid($uuid);
        }
        if (count(array_unique($chosen)) !== count($chosen)) {
            throw ValidationException::withMessages(['node' => 'Duplicate inbound selection.']);
        }

        // Serialize all Node mutations (create, single-node and batch) while
        // validating the latest profile/inbound state under the same lock.
        return Cache::lock('nodes:mutation', 60)->block(1, function () use ($actorId, $name, $address, $profileUuid, $chosen, $port): string {
            $profiles = $this->profiles($actorId);
            if (! in_array(strtolower($profileUuid), array_column($profiles, 'uuid'), true)) {
                throw ValidationException::withMessages(['node' => 'Profile changed.']);
            }
            $available = array_column($this->inbounds($actorId, $profileUuid), 'uuid');
            foreach ($chosen as $uuid) {
                if (! in_array(strtolower($uuid), $available, true)) {
                    throw ValidationException::withMessages(['node' => 'Inbound changed.']);
                }
            }
            $this->authorizeWrite($actorId);
            $api = new RemnawaveNodesApi($this->factory->make());
            foreach ($api->all() as $existing) {
                if ((is_string($existing['name'] ?? null)
                        && mb_strtolower($existing['name']) === mb_strtolower($name))
                    || (($existing['address'] ?? null) === $address
                        && ($existing['port'] ?? null) === $port)) {
                    throw ValidationException::withMessages(['node' => 'Node is already registered.']);
                }
            }

            $data = [
                'name' => $name,
                'address' => $address,
                'port' => $port,
                'configProfile' => [
                    'activeConfigProfileUuid' => strtolower($profileUuid),
                    'activeInbounds' => array_map('strtolower', $chosen),
                ],
            ];
            $created = $api->create($data);

            if (! self::uuidValid($created['uuid'] ?? null)) {
                throw new UnexpectedValueException('Node create outcome is not verifiable.');
            }

            return strtolower($created['uuid']);
        });
    }

    private function authorizeWrite(int $actorId): void
    {
        $this->access->handle($actorId);
        if (config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['node' => 'Node creation is disabled.']);
        }
    }

    /** @param array<string, mixed> $node
     * @return array{uuid:string,name:string,connected:bool,disabled:bool}
     */
    private function safeNode(array $node): array
    {
        if (! self::uuidValid($node['uuid'] ?? null) || ! self::label($node['name'] ?? null, 80)
            || ! is_bool($node['isConnected'] ?? null) || ! is_bool($node['isDisabled'] ?? null)) {
            throw new UnexpectedValueException('Invalid node identity/status.');
        }

        return [
            'uuid' => strtolower($node['uuid']),
            'name' => $node['name'],
            'connected' => $node['isConnected'],
            'disabled' => $node['isDisabled'],
        ];
    }

    private static function uuid(string $value): string
    {
        if (! self::uuidValid($value)) {
            throw ValidationException::withMessages(['node' => 'Invalid node identifier.']);
        }

        return strtolower($value);
    }

    private static function uuidValid(mixed $value): bool
    {
        return is_string($value) && preg_match(
            '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D',
            $value,
        ) === 1;
    }

    private static function label(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== ''
            && mb_strlen($value) <= $max
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }
}
