<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use Throwable;
use UnexpectedValueException;

/**
 * Discover direct public Node service endpoints from current RW Nodes and Hosts.
 * No static IP/UUID map, arbitrary bot input, CDN probe or internal Node API port.
 */
final readonly class NodeTcpProbe
{
    private const PREFIX = 'denagate:node-tcp:v1:';

    private const TIMEOUT_SECONDS = 2;

    private const MAX_TARGETS = 10;

    private const MAX_HOSTS = 100;

    public function __construct(private RemnawaveApiClientFactory $factory) {}

    /**
     * Resolves RW inventory every scheduled run. A Host is usable only if its
     * current address resolves to the same single public IPv4 as the Node.
     * Proxied CDN addresses and ambiguous DNS results are never targeted.
     *
     * @return array<string, array{ip:string,port:int}>
     */
    public function targets(): array
    {
        $client = $this->factory->make();
        $nodes = (new RemnawaveNodesApi($client))->all();
        $response = $client->call('HostsController_getHosts');
        $hosts = is_array($response) ? ($response['response'] ?? null) : null;

        if (count($nodes) > 500 || ! is_array($hosts) || ! array_is_list($hosts)
            || count($hosts) > self::MAX_HOSTS) {
            throw new UnexpectedValueException('Invalid Node TCP inventory.');
        }

        $directHosts = [];
        foreach ($hosts as $host) {
            if (! is_array($host) || ($host['isDisabled'] ?? null) !== false
                || ! is_int($host['port'] ?? null) || $host['port'] < 1 || $host['port'] > 65535
                || ! is_string($host['address'] ?? null)) {
                continue;
            }
            $ip = self::resolvePublicIp($host['address']);
            if ($ip === null) {
                continue;
            }
            $directHosts[$ip][] = $host;
        }

        $targets = [];
        foreach ($nodes as $node) {
            $uuid = $node['uuid'] ?? null;
            $address = $node['address'] ?? null;
            if (! is_string($uuid)
                || preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $uuid) !== 1
                || ! is_bool($node['isDisabled'] ?? null)
                || $node['isDisabled'] || ! is_string($address)) {
                continue;
            }

            $uuid = strtolower($uuid);
            $ip = self::resolvePublicIp($address);
            if ($ip === null || isset($targets[$uuid])) {
                continue;
            }

            $ports = [];
            foreach ($directHosts[$ip] ?? [] as $host) {
                if ($host['port'] !== ($node['port'] ?? null)
                    && self::matchesActiveInbound($node, $host)) {
                    $ports[] = $host['port'];
                }
            }
            if ($ports === []) {
                continue;
            }

            // Prefer common direct TLS/REALITY service port, otherwise lowest
            // current Host port. Both choices come from RW, never from config.
            $port = in_array(443, $ports, true) ? 443 : min($ports);
            $targets[$uuid] = ['ip' => $ip, 'port' => $port];

            if (count($targets) > self::MAX_TARGETS) {
                throw new UnexpectedValueException('Too many Node TCP targets.');
            }
        }

        return $targets;
    }

    /**
     * An inbound association is verified when the Node and Host both include
     * it. Older RW schema variants without association fields can still use
     * a direct, exact public IP match; they never match by display name.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $host
     */
    private static function matchesActiveInbound(array $node, array $host): bool
    {
        $profile = $node['configProfile'] ?? null;
        $active = is_array($profile) ? ($profile['activeInbounds'] ?? null) : null;
        $inbound = $host['inbound'] ?? null;

        if (! is_array($active) || ! array_is_list($active)) {
            return true;
        }
        if (! is_array($inbound)
            || ! is_string($inbound['configProfileInboundUuid'] ?? null)) {
            return false;
        }

        $selected = strtolower($inbound['configProfileInboundUuid']);
        $active = array_map(
            static fn (mixed $item): string => match (true) {
                is_string($item) => strtolower($item),
                is_array($item) && is_string($item['uuid'] ?? null) => strtolower($item['uuid']),
                default => '',
            },
            $active,
        );

        return in_array($selected, $active, true)
            && (! is_string($profile['activeConfigProfileUuid'] ?? null)
                || ! is_string($inbound['configProfileUuid'] ?? null)
                || strcasecmp($profile['activeConfigProfileUuid'], $inbound['configProfileUuid']) === 0);
    }

    /** Returns exactly one public IPv4 or fails closed; never connects to a resolved Host IP. */
    private static function resolvePublicIp(string $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::publicIpv4($address) ? $address : null;
        }
        if (strlen($address) > 253
            || filter_var($address, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $answers = @gethostbynamel($address);
        if (! is_array($answers) || count($answers) !== 1) {
            return null;
        }

        return self::publicIpv4($answers[0]) ? $answers[0] : null;
    }

    /** The Scheduler performs network calls; Telegram only reads cached results. */
    public function run(): int
    {
        try {
            $targets = $this->targets();
        } catch (Throwable) {
            // Unavailable or malformed RW inventory is never replaced with guesses.
            return 0;
        }

        $checked = 0;
        foreach ($targets as $uuid => $target) {
            $started = hrtime(true);
            $socket = @stream_socket_client(
                'tcp://'.$target['ip'].':'.$target['port'],
                $errorCode,
                $errorMessage,
                self::TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
            );
            $ok = is_resource($socket);
            if ($ok) {
                fclose($socket);
            }
            $ms = $ok ? round((hrtime(true) - $started) / 1_000_000, 1) : null;

            try {
                Cache::put(self::PREFIX.$uuid, [
                    'endpoint' => hash('sha256', $target['ip'].':'.$target['port']),
                    'status' => $ok ? 'ok' : 'failed',
                    'ms' => $ms,
                    'checkedAt' => now()->timestamp,
                ], now()->addMinutes(3));
            } catch (Throwable) {
                // Optional telemetry cannot interrupt sales, nodes or notifications.
            }
            $checked++;
        }

        return $checked;
    }

    /**
     * No provider or DNS calls here: refresh is safe even inside Telegram.
     *
     * @return array{status:string,ms:float|null,checkedAt:int|null}
     */
    public function latest(string $uuid): array
    {
        $unknown = ['status' => 'unknown', 'ms' => null, 'checkedAt' => null];
        if (preg_match('/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D', $uuid) !== 1) {
            return $unknown;
        }

        try {
            $record = Cache::get(self::PREFIX.strtolower($uuid));
            if (! is_array($record)
                || ! is_string($record['endpoint'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $record['endpoint']) !== 1
                || ! is_int($record['checkedAt'] ?? null)
                || $record['checkedAt'] > now()->timestamp + 60
                || $record['checkedAt'] < now()->timestamp - 180
                || ! in_array($record['status'] ?? null, ['ok', 'failed'], true)) {
                return $unknown;
            }
            if ($record['status'] === 'ok' && is_numeric($record['ms'] ?? null)
                && is_finite((float) $record['ms']) && (float) $record['ms'] >= 0) {
                return ['status' => 'ok', 'ms' => (float) $record['ms'], 'checkedAt' => $record['checkedAt']];
            }
            if ($record['status'] === 'failed' && ($record['ms'] ?? null) === null) {
                return ['status' => 'failed', 'ms' => null, 'checkedAt' => $record['checkedAt']];
            }
        } catch (Throwable) {
            return $unknown;
        }

        return $unknown;
    }

    /** Filter flags alone do not exclude multicast, CGNAT and documentation IPs. */
    private static function publicIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4
            | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $octets = array_map('intval', explode('.', $ip));
        [$a, $b, $c] = $octets;

        return $a < 224
            && ! ($a === 100 && $b >= 64 && $b <= 127)
            && ! ($a === 192 && $b === 0 && in_array($c, [0, 2], true))
            && ! ($a === 192 && $b === 88 && $c === 99)
            && ! ($a === 198 && in_array($b, [18, 19], true))
            && ! ($a === 198 && $b === 51 && $c === 100)
            && ! ($a === 203 && $b === 0 && $c === 113);
    }
}
