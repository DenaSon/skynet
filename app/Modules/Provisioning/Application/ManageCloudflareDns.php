<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Provisioning\Infrastructure\Cloudflare\CloudflareDnsService;
use Closure;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Trusted root-only Cloudflare DNS boundary. The Node wizard and later DNS UI
 * share this Action and transport; no caller can select an arbitrary zone.
 */
final readonly class ManageCloudflareDns
{
    public function __construct(
        private AssertCatalogManagementAccess $access,
        private CloudflareDnsService $api,
    ) {}

    public function wizardEnabled(): bool
    {
        return config('services.cloudflare.dns_writes_enabled') === true
            && is_string(config('services.cloudflare.token'))
            && trim(config('services.cloudflare.token')) !== ''
            && $this->allowedZones() !== [];
    }

    /** @return list<array{id:string,name:string}> */
    public function zones(int $actorId): array
    {
        $this->access->handle($actorId);
        $zones = [];
        foreach ($this->allowedZones() as $name) {
            foreach ($this->api->zonesByName($name) as $remote) {
                if (($remote['name'] ?? null) === $name && self::zoneId($remote['id'] ?? null)) {
                    $zones[] = ['id' => $remote['id'], 'name' => $name];
                }
            }
        }

        return $zones;
    }

    public function hostname(int $actorId, string $zone, string $input): string
    {
        $this->access->handle($actorId);
        if (! in_array($zone, $this->allowedZones(), true)) {
            throw new RuntimeException('DNS zone is not allowed.');
        }

        $input = strtolower(trim($input));
        $name = str_contains($input, '.') ? $input : $input.'.'.$zone;
        if ($name === $zone || ! str_ends_with($name, '.'.$zone)
            || strlen($name) > 253
            || filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
            || str_contains($name, '*')) {
            throw new RuntimeException('Invalid subdomain.');
        }

        return $name;
    }

    /** Examine the exact current record without modifying it.
     * @return 'missing'|'existing'
     */
    public function inspectA(int $actorId, string $zoneId, string $zone, string $hostname, string $ip): string
    {
        $this->access->handle($actorId);
        $this->validate($zoneId, $zone, $hostname, $ip);
        $this->verifyZone($actorId, $zoneId, $zone);

        return $this->recordState($zoneId, $hostname, $ip);
    }

    /**
     * Confirmed attempt. The callback durably marks an uncertain outcome BEFORE
     * any external POST; a matching existing record is instead safely reused.
     *
     * @return 'created'|'existing'
     */
    public function ensureA(
        int $actorId,
        string $zoneId,
        string $zone,
        string $hostname,
        string $ip,
        Closure $beforeCreate,
    ): string {
        $this->access->handle($actorId);
        if (config('services.cloudflare.dns_writes_enabled') !== true) {
            throw new RuntimeException('Cloudflare DNS writes are disabled.');
        }
        $this->validate($zoneId, $zone, $hostname, $ip);

        return Cache::lock('cloudflare:dns:'.hash('sha256', $zoneId.':'.$hostname), 90)
            ->block(2, function () use ($actorId, $zoneId, $zone, $hostname, $ip, $beforeCreate): string {
                $this->verifyZone($actorId, $zoneId, $zone);
                if ($this->recordState($zoneId, $hostname, $ip) === 'existing') {
                    return 'existing';
                }

                $beforeCreate();
                $this->api->createA($zoneId, $hostname, $ip);
                if ($this->recordState($zoneId, $hostname, $ip) !== 'existing') {
                    throw new RuntimeException('Cloudflare DNS creation could not be verified.');
                }

                return 'created';
            });
    }

    /** @return array{items:list<array<string,mixed>>,page:int,pages:int} */
    public function page(int $actorId, string $zoneId, string $zone, int $page): array
    {
        $this->verifyZone($actorId, $zoneId, $zone);
        $response = $this->api->pageRecords($zoneId, $page);
        $items = [];
        foreach ($response['items'] as $item) {
            $items[] = $this->safeRecord($zone, $item);
        }

        return ['items' => $items, 'page' => $response['page'], 'pages' => $response['pages']];
    }

    /** @return array<string,mixed> */
    public function one(int $actorId, string $zoneId, string $zone, string $recordId): array
    {
        $this->verifyZone($actorId, $zoneId, $zone);
        if (! self::zoneId($recordId)) {
            throw new RuntimeException('Invalid record identifier.');
        }

        return $this->safeRecord($zone, $this->api->record($zoneId, $recordId));
    }

    /** The same CF-01 create path is used by the DNS manager (DNS Only). */
    public function createManagedA(int $actorId, string $zoneId, string $zone, string $hostname, string $ip): string
    {
        return $this->ensureA($actorId, $zoneId, $zone, $hostname, $ip, static function (): void {});
    }

    /**
     * Revalidates every write with a fresh remote snapshot under the same
     * zone+hostname lock used by CF-01; a lost PATCH response is never retried.
     *
     * @param array<string,mixed> $expected
     */
    public function changeA(
        int $actorId,
        string $zoneId,
        string $zone,
        array $expected,
        string $ip,
        bool $proxied,
    ): void {
        $this->access->handle($actorId);
        if (config('services.cloudflare.dns_writes_enabled') !== true) {
            throw new RuntimeException('Cloudflare DNS writes are disabled.');
        }
        $recordId = $expected['id'] ?? null;
        $hostname = $expected['name'] ?? null;
        if (! self::zoneId($recordId) || ! is_string($hostname)) {
            throw new RuntimeException('Invalid record identity.');
        }
        $this->validate($zoneId, $zone, $hostname, $ip);

        Cache::lock('cloudflare:dns:'.hash('sha256', $zoneId.':'.$hostname), 90)
            ->block(2, function () use ($actorId, $zoneId, $zone, $recordId, $expected, $ip, $proxied): void {
                $current = $this->one($actorId, $zoneId, $zone, $recordId);
                if ($current !== $expected || $current['editable'] !== true) {
                    throw new RuntimeException('DNS record changed since preview.');
                }
                if ($current['content'] === $ip && $current['proxied'] === $proxied) {
                    throw new RuntimeException('DNS record is unchanged.');
                }
                if ($proxied && $current['proxiable'] !== true) {
                    throw new RuntimeException('This record cannot be proxied.');
                }
                $this->api->patchA($zoneId, $recordId, $ip, $proxied);
                $updated = $this->one($actorId, $zoneId, $zone, $recordId);
                if ($updated['name'] !== $current['name'] || $updated['type'] !== 'A'
                    || $updated['content'] !== $ip || $updated['proxied'] !== $proxied) {
                    throw new RuntimeException('DNS update outcome could not be verified.');
                }
            });
    }

    public function validatePublicIp(int $actorId, string $ip): string
    {
        $this->access->handle($actorId);
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('A public IPv4 address is required.');
        }

        return $ip;
    }

    /** Only IP of permitted A subdomains is editable. Other records are read-only.
     * Never return TXT/CNAME/etc content to a Telegram presenter.
     *
     * @param array<string,mixed> $remote
     * @return array<string,mixed>
     */
    private function safeRecord(string $zone, array $remote): array
    {
        $id = $remote['id'] ?? null;
        $name = $remote['name'] ?? null;
        $type = $remote['type'] ?? null;
        if (! self::zoneId($id) || ! is_string($name) || strlen($name) > 253
            || ! is_string($type) || preg_match('/\A[A-Z0-9]{1,12}\z/D', $type) !== 1
            || preg_match('/\A(?:\*\.)?[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/D', $name) !== 1
            || ($name !== $zone && ! str_ends_with($name, '.'.$zone))) {
            throw new RuntimeException('Malformed or out-of-zone DNS record.');
        }
        $isA = $type === 'A';
        $content = $isA ? ($remote['content'] ?? null) : null;
        $proxy = $isA ? ($remote['proxied'] ?? null) : null;
        $proxiable = $isA ? ($remote['proxiable'] ?? true) : false;
        if ($isA && (! is_string($content) || filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! is_bool($proxy) || ! is_bool($proxiable))) {
            throw new RuntimeException('Invalid DNS A record.');
        }

        return [
            'id' => $id,
            'name' => $name,
            'type' => $type,
            'content' => $content,
            'proxied' => $proxy,
            'proxiable' => $proxiable,
            'editable' => $isA && $this->hostnameShape($zone, $name)
                && filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false,
            'modified' => is_string($remote['modified_on'] ?? null) ? $remote['modified_on'] : null,
        ];
    }

    /** @return list<string> */
    private function allowedZones(): array
    {
        $raw = config('services.cloudflare.allowed_zones');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $names = array_values(array_unique(array_map(
            static fn (string $name): string => strtolower(trim($name)),
            explode(',', $raw),
        )));
        if (count($names) > 10) {
            return [];
        }

        foreach ($names as $name) {
            if ($name === '' || strlen($name) > 253
                || ! str_contains($name, '.')
                || filter_var($name, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                return [];
            }
        }

        return $names;
    }

    private function verifyZone(int $actorId, string $zoneId, string $zone): void
    {
        $this->access->handle($actorId);
        if (! in_array($zone, $this->allowedZones(), true)) {
            throw new RuntimeException('DNS zone is not allowed.');
        }
        foreach ($this->api->zonesByName($zone) as $remote) {
            if (($remote['id'] ?? null) === $zoneId && ($remote['name'] ?? null) === $zone) {
                return;
            }
        }
        throw new RuntimeException('Cloudflare zone changed or is unavailable.');
    }

    private function validate(string $zoneId, string $zone, string $hostname, string $ip): void
    {
        if (! self::zoneId($zoneId)
            || ! in_array($zone, $this->allowedZones(), true)
            || $this->hostnameShape($zone, $hostname) === false
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new RuntimeException('Invalid Cloudflare record configuration.');
        }
    }

    private function hostnameShape(string $zone, string $hostname): bool
    {
        return $hostname !== $zone
            && $hostname === strtolower($hostname)
            && str_ends_with($hostname, '.'.$zone)
            && strlen($hostname) <= 253
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private static function zoneId(mixed $id): bool
    {
        return is_string($id) && preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
    }

    /** @return 'missing'|'existing'
     *  @phpstan-impure
     */
    private function recordState(string $zoneId, string $hostname, string $ip): string
    {
        $records = $this->api->records($zoneId, $hostname);
        if ($records === []) {
            return 'missing';
        }
        if (count($records) !== 1 || ($records[0]['name'] ?? null) !== $hostname
            || ($records[0]['type'] ?? null) !== 'A'
            || ($records[0]['content'] ?? null) !== $ip
            || ($records[0]['proxied'] ?? null) !== false) {
            throw new RuntimeException('Existing DNS record differs; manual review required.');
        }

        return 'existing';
    }
}
