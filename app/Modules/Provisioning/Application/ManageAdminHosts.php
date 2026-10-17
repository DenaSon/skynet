<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * HOST-01: narrow existing Remnawave Hosts API boundary. A Host is a client
 * connection entry, NOT a VPS or proof of a live endpoint.
 */
final readonly class ManageAdminHosts
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private RemnawaveApiClientFactory $factory,
        private ManageAdminNodes $nodes,
    ) {}

    /**
     * @return list<array{uuid:string,remark:string,address:string,port:int,disabled:bool|null,profileUuid:string|null,inboundUuid:string|null}>
     */
    public function all(int $actorId): array
    {
        $this->access->handle($actorId);
        $result = $this->factory->make()->call('HostsController_getHosts');
        $hosts = is_array($result) ? ($result['response'] ?? null) : null;
        if (! is_array($hosts) || ! array_is_list($hosts) || count($hosts) > 300) {
            throw new UnexpectedValueException('Invalid Hosts list.');
        }

        $safe = [];
        $seen = [];
        foreach ($hosts as $host) {
            $row = self::safeHost($host);
            if (isset($seen[$row['uuid']])) {
                throw new UnexpectedValueException('Duplicate Host identifier.');
            }
            $seen[$row['uuid']] = true;
            $safe[] = $row;
        }

        return $safe;
    }

    /**
     * @return array{uuid:string,remark:string,address:string,port:int,disabled:bool|null,profileUuid:string|null,inboundUuid:string|null}
     */
    public function one(int $actorId, string $uuid): array
    {
        $this->access->handle($actorId);
        $uuid = self::uuid($uuid);
        $result = $this->factory->make()->call('HostsController_getOneHost', pathParameters: ['uuid' => $uuid]);
        $host = is_array($result) ? ($result['response'] ?? null) : null;
        $safe = self::safeHost($host);
        if ($safe['uuid'] !== $uuid) {
            throw new UnexpectedValueException('Host identifier changed.');
        }

        return $safe;
    }

    public function validateIdentity(int $actorId, string $remark, string $address, int $port): void
    {
        $this->access->handle($actorId);
        if (! self::label($remark, 40)
            || strlen($address) > 253 || trim($address) !== $address
            || (filter_var($address, FILTER_VALIDATE_IP) === false
                && filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)
            || $port < 1 || $port > 65535) {
            throw ValidationException::withMessages(['host' => 'Invalid Host name, address, or port.']);
        }
    }

    public function create(
        int $actorId,
        string $remark,
        string $address,
        int $port,
        string $profileUuid,
        string $inboundUuid,
    ): string {
        $this->validateIdentity($actorId, $remark, $address, $port);
        $profileUuid = self::uuid($profileUuid);
        $inboundUuid = self::uuid($inboundUuid);
        $this->authorizeWrite($actorId);

        // Current remote membership and write authorization must be checked
        // together with the single POST; no parallel Host creation under this lock.
        return Cache::lock('hosts:mutation', 60)->block(1, function () use (
            $actorId, $remark, $address, $port, $profileUuid, $inboundUuid
        ): string {
            $profiles = $this->nodes->profiles($actorId);
            if (! in_array($profileUuid, array_column($profiles, 'uuid'), true)) {
                throw ValidationException::withMessages(['host' => 'Profile no longer exists.']);
            }
            $inbounds = $this->nodes->inbounds($actorId, $profileUuid);
            if (! in_array($inboundUuid, array_column($inbounds, 'uuid'), true)) {
                throw ValidationException::withMessages(['host' => 'Inbound no longer belongs to this profile.']);
            }
            $this->authorizeWrite($actorId);

            // Created disabled so it cannot silently appear in live customer
            // subscriptions before the operator completes HOST-02 and checks it.
            $response = $this->factory->make()->call('HostsController_createHost', body: [
                'inbound' => [
                    'configProfileUuid' => $profileUuid,
                    'configProfileInboundUuid' => $inboundUuid,
                ],
                'remark' => $remark,
                'address' => $address,
                'port' => $port,
                'isDisabled' => true,
            ]);

            $created = is_array($response) ? ($response['response'] ?? null) : null;
            if (! is_array($created) || ! self::uuidValid($created['uuid'] ?? null)) {
                // Do not retry. An ambiguous POST response may mean it succeeded.
                throw new UnexpectedValueException('Host creation outcome could not be verified.');
            }

            return strtolower($created['uuid']);
        });
    }

    private function authorizeWrite(int $actorId): void
    {
        $this->access->handle($actorId);
        if (config('services.remnawave.writes_enabled') !== true) {
            throw ValidationException::withMessages(['host' => 'Host writes are disabled.']);
        }
    }

    /**
     * @return array{uuid:string,remark:string,address:string,port:int,disabled:bool|null,profileUuid:string|null,inboundUuid:string|null}
     */
    private static function safeHost(mixed $data): array
    {
        if (! is_array($data) || ! self::uuidValid($data['uuid'] ?? null)
            || ! self::label($data['remark'] ?? null, 80)
            || ! is_string($data['address'] ?? null) || ! self::label($data['address'], 253)
            || ! is_int($data['port'] ?? null) || $data['port'] < 1 || $data['port'] > 65535
            || (array_key_exists('isDisabled', $data) && ! is_bool($data['isDisabled']))) {
            throw new UnexpectedValueException('Malformed Host identity.');
        }

        $inbound = $data['inbound'] ?? null;
        $profileUuid = null;
        $inboundUuid = null;
        if ($inbound !== null) {
            if (! is_array($inbound)) {
                throw new UnexpectedValueException('Malformed Host inbound.');
            }
            $profile = $inbound['configProfileUuid'] ?? null;
            $selected = $inbound['configProfileInboundUuid'] ?? null;
            if ($profile !== null || $selected !== null) {
                if (! self::uuidValid($profile) || ! self::uuidValid($selected)) {
                    throw new UnexpectedValueException('Incomplete Host inbound identity.');
                }
                $profileUuid = strtolower($profile);
                $inboundUuid = strtolower($selected);
            }
        }

        return [
            'uuid' => strtolower($data['uuid']),
            'remark' => $data['remark'],
            'address' => $data['address'],
            'port' => $data['port'],
            'disabled' => $data['isDisabled'] ?? null,
            'profileUuid' => $profileUuid,
            'inboundUuid' => $inboundUuid,
        ];
    }

    private static function uuid(string $uuid): string
    {
        if (! self::uuidValid($uuid)) {
            throw ValidationException::withMessages(['host' => 'Invalid provider identifier.']);
        }

        return strtolower($uuid);
    }

    private static function uuidValid(mixed $uuid): bool
    {
        return is_string($uuid) && preg_match(
            '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D',
            $uuid,
        ) === 1;
    }

    private static function label(mixed $value, int $max): bool
    {
        return is_string($value) && trim($value) !== ''
            && mb_strlen($value) <= $max
            && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }
}
