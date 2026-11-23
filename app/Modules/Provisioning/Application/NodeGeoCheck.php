<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnexpectedValueException;

/**
 * Root-only, on-demand GeoCheck for one existing Remnawave Node.
 * No cron, filtering incident, Provider mutation or persisted raw/SVG payload.
 */
final readonly class NodeGeoCheck
{
    private const TTL_MINUTES = 10;

    public function __construct(
        private ManageAdminNodes $nodes,
        private RemnawaveApiClientFactory $clients,
    ) {}

    /** @return list<array{uuid:string,name:string,connected:bool,disabled:bool}> */
    public function nodes(int $actorId): array
    {
        return $this->nodes->all($actorId);
    }

    /** @return array{uuid:string,name:string,connected:bool,disabled:bool} */
    public function node(int $actorId, string $uuid): array
    {
        return $this->nodes->one($actorId, $uuid);
    }

    /** @return 'pending'|'existing'|'disabled'|'ineligible'|'unavailable' */
    public function start(int $actorId, string $uuid): string
    {
        // Re-read the authoritative Node and root privileges before a remote POST.
        $node = $this->node($actorId, $uuid);
        if (config('services.remnawave.node_geocheck_enabled') !== true) {
            return 'disabled';
        }
        if (! $node['connected'] || $node['disabled']) {
            return 'ineligible';
        }

        $key = self::key($node['uuid']);
        // Atomic reservation precedes POST. An ambiguous outcome MUST NOT be replayed.
        if (! Cache::add($key, ['state' => 'starting'], now()->addMinutes(self::TTL_MINUTES))) {
            return 'existing';
        }

        try {
            $response = $this->clients->make()->call(
                'ConnectionsController_geocheckByNode',
                ['nodeUuid' => $node['uuid']], [], [],
            );
            $payload = self::payload($response);
            $id = $payload['jobId'] ?? null;
            if ((! is_int($id) || $id < 1) &&
                (! is_string($id) || preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $id) !== 1)) {
                throw new UnexpectedValueException('Invalid GeoCheck job id.');
            }
            Cache::put($key, [
                'state' => 'pending', 'jobId' => $id, 'nodeUuid' => $node['uuid'],
            ], now()->addMinutes(self::TTL_MINUTES));

            return 'pending';
        } catch (Throwable) {
            Cache::put($key, ['state' => 'uncertain'], now()->addMinutes(self::TTL_MINUTES));

            return 'unavailable';
        }
    }

    /**
     * Only explicit refresh invokes GET. All detail/page navigation reads Cache.
     *
     * @return array<string,mixed>
     */
    public function result(int $actorId, string $uuid, bool $refresh = false): array
    {
        $node = $this->node($actorId, $uuid);
        if (config('services.remnawave.node_geocheck_enabled') !== true) {
            return ['state' => 'disabled'];
        }
        $key = self::key($node['uuid']);
        $cached = Cache::get($key);
        if (! is_array($cached)) {
            return ['state' => 'missing'];
        }
        $state = $cached['state'] ?? null;
        if ($state === 'ready' && is_array($cached['data'] ?? null)) {
            return ['state' => 'ready', ...$cached['data']];
        }
        if ($state !== 'pending' || ! $refresh) {
            return ['state' => match ($state) {
                'pending', 'starting' => 'pending',
                'failed' => 'failed',
                default => 'unavailable',
            }];
        }
        $id = $cached['jobId'] ?? null;
        if (($cached['nodeUuid'] ?? null) !== $node['uuid']
            || (! is_int($id) && ! is_string($id))
            || (is_int($id) && $id < 1)
            || (is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $id) !== 1)) {
            return ['state' => 'unavailable'];
        }

        try {
            $job = self::payload($this->clients->make()->call(
                'ConnectionsController_geocheckByNodeResult',
                ['jobId' => $id],
            ));
            if (! is_bool($job['isCompleted'] ?? null) || ! is_bool($job['isFailed'] ?? null)) {
                throw new UnexpectedValueException('Invalid GeoCheck status.');
            }
            if ($job['isFailed'] === true) {
                Cache::put($key, ['state' => 'failed'], now()->addMinutes(self::TTL_MINUTES));

                return ['state' => 'failed'];
            }
            if ($job['isCompleted'] === false) {
                return ['state' => 'pending'];
            }

            $result = $job['result'] ?? null;
            if (! is_array($result) || ($result['success'] ?? null) !== true
                || strtolower((string) ($result['nodeUuid'] ?? '')) !== $node['uuid']) {
                throw new UnexpectedValueException('GeoCheck identity or success mismatch.');
            }
            $data = self::normalize($result['rawReport'] ?? null);
            // Store a bounded, sanitized projection only. Discard the SVG/base64 and raw report.
            Cache::put($key, ['state' => 'ready', 'data' => $data],
                now()->addMinutes(self::TTL_MINUTES));

            return ['state' => 'ready', ...$data];
        } catch (Throwable) {
            // GET may be retried explicitly; it cannot start a second remote job.
            return ['state' => 'unavailable'];
        }
    }

    private static function key(string $uuid): string
    {
        return 'geocheck:node:'.strtolower($uuid);
    }

    /** @return array<string,mixed> */
    private static function payload(mixed $response): array
    {
        if (! is_array($response)) {
            throw new UnexpectedValueException('Invalid GeoCheck response.');
        }
        $payload = $response['response'] ?? $response;
        if (! is_array($payload) || array_is_list($payload)) {
            throw new UnexpectedValueException('Invalid GeoCheck envelope.');
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private static function normalize(mixed $raw): array
    {
        if (! is_array($raw) || ($raw['schema'] ?? null) !== 1) {
            throw new UnexpectedValueException('Unsupported GeoCheck report.');
        }
        $identity = self::object($raw['identity'] ?? null);
        $reputation = self::object($raw['reputation'] ?? null);
        $conn = self::object($raw['connectivity'] ?? null);
        $breakdown = self::object($conn['breakdown'] ?? null);
        $checks = self::object($raw['connectivity_checks'] ?? null);
        $geo = self::object($raw['geo'] ?? null);
        $consensus = self::object($raw['consensus'] ?? null);
        $vote = self::items($consensus['ipv4'] ?? [], 8)[0] ?? [];
        $vote = is_array($vote) ? $vote : [];

        $summary = [
            'at' => self::label($raw['timestamp'] ?? null, 32),
            'duration' => self::number($raw['duration_ms'] ?? null),
            'ip' => self::ip($identity['ipv4'] ?? null),
            'asn' => self::integer($identity['asn'] ?? null),
            'as_name' => self::label($identity['as_name'] ?? null, 90),
            'as_country' => self::label($identity['as_country'] ?? null, 8),
            'country' => self::label($reputation['country'] ?? null, 50),
            'hosting' => self::boolean($reputation['hosting'] ?? null),
            'vpn' => self::boolean($reputation['vpn'] ?? null),
            'proxy' => self::boolean($reputation['proxy'] ?? null),
            'tor' => self::boolean($reputation['tor'] ?? null),
            'risk' => self::percent($reputation['risk'] ?? null),
            'confidence' => self::percent($reputation['confidence'] ?? null),
            'type' => self::label($reputation['type'] ?? null, 30),
            'score' => self::percent($conn['score'] ?? null),
            'latency_floor_ms' => self::number($conn['latency_floor_ms'] ?? null),
            'icmp_available' => self::boolean($conn['icmp_available'] ?? null),
            'breakdown' => array_map(static fn (string $name): ?int => self::integer($breakdown[$name] ?? null),
                ['direct', 'peered', 'transit', 'detour', 'intercepted', 'failed']),
            'http_ok' => self::integer($checks['ok'] ?? null),
            'http_altered' => self::integer($checks['altered'] ?? null),
            'http_unreachable' => self::integer($checks['unreachable'] ?? null),
            'consensus_country' => self::label($vote['country'] ?? null, 50),
            'consensus_count' => self::integer($vote['count'] ?? null),
            'consensus_total' => self::integer($vote['total'] ?? null),
            'consensus_percent' => self::percent($vote['percent'] ?? null),
            'resolver' => self::label(
                is_array($raw['transport'] ?? null) ? ($raw['transport']['resolver'] ?? null) : null, 70),
            'findings_count' => count(self::items($raw['findings'] ?? [], 50)),
        ];

        $routes = [];
        foreach (self::items($conn['targets'] ?? null, 60) as $item) {
            $item = self::object($item);
            $hops = [];
            foreach (self::items($item['hops'] ?? [], 64) as $hop) {
                $hop = self::object($hop);
                $hops[] = [
                    'ttl' => self::integer($hop['ttl'] ?? null),
                    'ip' => self::ip($hop['addr'] ?? null),
                    'asn' => self::integer($hop['asn'] ?? null),
                    'avg_ms' => self::number($hop['avg_ms'] ?? null),
                    'loss' => self::number($hop['loss'] ?? null),
                ];
            }
            $routes[] = [
                'name' => self::label($item['name'] ?? null, 70),
                'verdict' => self::label($item['verdict'] ?? null, 30),
                'method' => self::label($item['method'] ?? null, 20),
                'score' => self::percent($item['score'] ?? null),
                'rtt_ms' => self::number($item['rtt_ms'] ?? null),
                'jitter_ms' => self::number($item['jitter_ms'] ?? null),
                'loss' => self::number($item['loss'] ?? null),
                'hops' => $hops,
            ];
        }

        $web = [];
        foreach (self::items($checks['endpoints'] ?? null, 60) as $item) {
            $item = self::object($item);
            $web[] = [
                'name' => self::label($item['name'] ?? null, 70),
                'verdict' => self::label($item['verdict'] ?? null, 30),
                'status' => self::integer($item['status'] ?? null),
                'rtt_ms' => self::number($item['rtt_ms'] ?? null),
            ];
        }

        $stash = [];
        foreach (self::items($raw['stash_checks'] ?? null, 60) as $item) {
            $item = self::object($item);
            $stash[] = [
                'name' => self::label($item['name'] ?? null, 70),
                'state' => self::label($item['state'] ?? null, 30),
                'rtt_ms' => self::number($item['rtt_ms'] ?? null),
            ];
        }

        $ai = [];
        foreach (self::items($raw['ai_endpoints'] ?? null, 60) as $item) {
            $item = self::object($item);
            $ai[] = [
                'name' => self::label($item['name'] ?? null, 70),
                'state' => self::label($item['state'] ?? null, 30),
                'status' => self::integer($item['http_status'] ?? null),
                'rtt_ms' => self::number($item['rtt_ms'] ?? null),
            ];
        }

        $geoItems = [];
        foreach (['cdn' => 'CDN', 'geoip' => 'GeoIP', 'services' => 'سرویس'] as $group => $title) {
            foreach (self::items($geo[$group] ?? null, 60) as $item) {
                $item = self::object($item);
                $geoItems[] = [
                    'group' => $title,
                    'name' => self::label($item['name'] ?? null, 70),
                    'kind' => self::label($item['kind'] ?? null, 30),
                ];
            }
        }

        return [
            'summary' => $summary, 'routes' => $routes, 'web' => $web,
            'stash' => $stash, 'ai' => $ai, 'geo' => $geoItems,
        ];
    }

    /** @return array<string,mixed> */
    private static function object(mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new UnexpectedValueException('Malformed GeoCheck section.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function items(mixed $value, int $max): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $max) {
            throw new UnexpectedValueException('Oversized or malformed GeoCheck list.');
        }

        return $value;
    }

    private static function label(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > $max
            || preg_match('/[\x00-\x1F\x7F\p{Cf}]/u', $value) !== 0) {
            return null;
        }

        return $value;
    }

    private static function ip(mixed $value): ?string
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= 100000000 ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value)
            && $value >= 0 && $value <= 100000000 ? (float) $value : null;
    }

    private static function percent(mixed $value): ?float
    {
        $number = self::number($value);

        return $number !== null && $number <= 100 ? $number : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }
}
