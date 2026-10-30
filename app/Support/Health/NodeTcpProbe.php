<?php

declare(strict_types=1);

namespace App\Support\Health;

use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodesApi;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Operator-approved public IPv4/port TCP checks for known Remnawave Nodes.
 * No Telegram input, DNS resolution, private IP, provider mutation or secrets.
 */
final readonly class NodeTcpProbe
{
    private const PREFIX = 'denagate:node-tcp:v1:';

    private const TIMEOUT_SECONDS = 2;

    private const MAX_TARGETS = 10;

    public function __construct(private RemnawaveApiClientFactory $factory) {}

    /**
     * Invalid configuration fails closed for the whole batch.
     *
     * @return array<string, array{ip:string,port:int}>
     */
    public function targets(): array
    {
        $raw = config('denagate_health.node_tcp_targets', '');
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $items = explode(',', $raw);
        if (count($items) > self::MAX_TARGETS) {
            throw new InvalidArgumentException('Too many Node TCP targets.');
        }

        $targets = [];
        foreach ($items as $entry) {
            if (preg_match(
                '/\A([0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12})@([0-9.]+):([0-9]{1,5})\z/D',
                trim($entry), $m,
            ) !== 1) {
                throw new InvalidArgumentException('Invalid Node TCP target syntax.');
            }
            $uuid = strtolower($m[1]);
            $ip = $m[2];
            $port = (int) $m[3];
            if (isset($targets[$uuid]) || $port < 1 || $port > 65535
                || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4
                    | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('Unsafe or duplicate Node TCP target.');
            }
            $targets[$uuid] = ['ip' => $ip, 'port' => $port];
        }

        return $targets;
    }

    /** Only called by the scheduled operator probe, never from Telegram. */
    public function run(): int
    {
        try {
            $targets = $this->targets();
            if ($targets === []) {
                return 0;
            }
            $nodes = (new RemnawaveNodesApi($this->factory->make()))->all();
        } catch (Throwable) {
            // Do not connect anywhere when configuration or inventory is uncertain.
            return 0;
        }

        $present = [];
        foreach ($nodes as $node) {
            $uuid = $node['uuid'] ?? null;
            if (! is_string($uuid) || ! is_bool($node['isDisabled'] ?? null)) {
                continue;
            }
            $uuid = strtolower($uuid);
            if (! isset($targets[$uuid]) || $node['isDisabled']) {
                continue;
            }
            $present[$uuid] = true;
        }

        $checked = 0;
        foreach ($targets as $uuid => $target) {
            if (! isset($present[$uuid])) {
                continue;
            }

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
                    'endpoint' => self::fingerprint($target),
                    'status' => $ok ? 'ok' : 'failed',
                    'ms' => $ms,
                    'checkedAt' => now()->timestamp,
                ], now()->addMinutes(3));
            } catch (Throwable) {
                // An optional observation must not affect any service operation.
            }
            $checked++;
        }

        return $checked;
    }

    /**
     * Read cached, endpoint-bound measurements without any network calls.
     *
     * @return array{status:string,ms:float|null,checkedAt:int|null}
     */
    public function latest(string $uuid): array
    {
        $unknown = ['status' => 'unknown', 'ms' => null, 'checkedAt' => null];
        try {
            $targets = $this->targets();
            $target = $targets[strtolower($uuid)] ?? null;
            if ($target === null) {
                return $unknown;
            }
            $record = Cache::get(self::PREFIX.strtolower($uuid));
            if (! is_array($record)
                || ($record['endpoint'] ?? null) !== self::fingerprint($target)
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

    /** @param array{ip:string,port:int} $target */
    private static function fingerprint(array $target): string
    {
        return hash('sha256', $target['ip'].':'.$target['port']);
    }
}
