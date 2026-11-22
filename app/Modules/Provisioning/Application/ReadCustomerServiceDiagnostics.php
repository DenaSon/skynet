<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use App\Modules\Filtering\Application\ReadFilteringCenter;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveApiClientFactory;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeTelemetry;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveUsersApi;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * SUP-DIAG-01: bounded diagnostics for one verified, locally owned subscription.
 * No raw provider response, credentials, IPs or remote IDs leave this use case.
 * Consumers (Admin Telegram now, Deniz later) must supply a real actor identity.
 */
final readonly class ReadCustomerServiceDiagnostics
{
    public function __construct(
        private ReadCustomerLiveServices $live,
        private RemnawaveApiClientFactory $factory,
        private ReadFilteringCenter $filtering,
        private SuperAdminTrust $trust,
    ) {}

    /**
     * @return array{state:string,days:int,start:string,end:string,totalBytes:?int,nodes:list<array{name:string,bytes:int}>}
     */
    public function usage(int $actorId, int $subjectId, int $orderId, int $days): array
    {
        if (! in_array($days, [1, 7, 30], true)) {
            throw new InvalidArgumentException('Unsupported diagnostic range.');
        }

        $today = now('UTC');
        $start = $today->copy()->subDays($days - 1)->toDateString();
        $end = $today->toDateString();
        $unknown = ['state' => 'unavailable', 'days' => $days, 'start' => $start,
            'end' => $end, 'totalBytes' => null, 'nodes' => []];

        $binding = $this->verified($actorId, $subjectId, $orderId);
        if ($binding === null) {
            return $unknown;
        }

        try {
            $body = $this->factory->make()->call('BandwidthStatsUsersController_getStatsNodesUsage',
                ['userId' => $binding['id']], ['start' => $start, 'end' => $end, 'topNodesLimit' => 5]);
            $data = self::payload($body);
            $categories = $data['categories'] ?? null;
            $trend = $data['sparklineData'] ?? null;
            $top = $data['topNodes'] ?? null;
            $series = $data['series'] ?? null;
            if (! is_array($categories) || ! array_is_list($categories)
                || ! is_array($trend) || ! array_is_list($trend)
                || ! is_array($top) || ! array_is_list($top)
                || ! is_array($series) || ! array_is_list($series)
                || count($categories) !== count($trend) || count($categories) > 31
                || count($top) > 5) {
                throw new UnexpectedValueException('Invalid user usage response.');
            }

            $total = 0;
            foreach ($trend as $index => $value) {
                if (! is_string($categories[$index]) || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/D', $categories[$index])) {
                    throw new UnexpectedValueException('Invalid usage category.');
                }
                $bytes = RemnawaveNodeTelemetry::nonNegativeBytes($value);
                if ($bytes === null || $bytes > PHP_INT_MAX - $total) {
                    throw new UnexpectedValueException('Invalid user usage total.');
                }
                $total += $bytes;
            }

            $nodes = [];
            $seen = [];
            foreach ($top as $item) {
                if (! is_array($item)
                    || ! is_string($item['uuid'] ?? null)
                    || preg_match('/\A[0-9a-fA-F-]{36}\z/D', $item['uuid']) !== 1
                    || ! self::label($item['name'] ?? null)
                    || isset($seen[$item['uuid']])
                    || ($bytes = RemnawaveNodeTelemetry::nonNegativeBytes($item['total'] ?? null)) === null) {
                    throw new UnexpectedValueException('Invalid user usage node.');
                }
                $seen[$item['uuid']] = true;
                $nodes[] = ['name' => $item['name'], 'bytes' => $bytes];
            }
            $result = ['state' => 'available', 'days' => $days, 'start' => $start,
                'end' => $end, 'totalBytes' => $trend === [] ? null : $total, 'nodes' => $nodes];
        } catch (Throwable) {
            $result = $unknown;
        }

        $this->stillOwned($actorId, $subjectId, $orderId, $binding);

        return $result;
    }

    /**
     * @return array{state:string,total:?int,records:list<array{at:string,type:string,ip:?string,agent:?string,rule:?string}>}
     */
    public function history(int $actorId, int $subjectId, int $orderId): array
    {
        $unknown = ['state' => 'unavailable', 'total' => null, 'records' => []];
        $binding = $this->verified($actorId, $subjectId, $orderId);
        if ($binding === null) {
            return $unknown;
        }

        try {
            $data = (new RemnawaveUsersApi($this->factory->make()))->requestHistory($binding['id']);
            $total = $data['total'] ?? null;
            $records = $data['records'] ?? null;
            if (! is_int($total) || $total < 0 || ! is_array($records)
                || ! array_is_list($records) || count($records) > 24) {
                throw new UnexpectedValueException('Invalid request history.');
            }

            $items = [];
            foreach ($records as $record) {
                if (! is_array($record) || ($record['userId'] ?? null) !== $binding['id']
                    || ! is_string($record['requestAt'] ?? null)
                    || ! self::responseType($record['srrResponseType'] ?? null)) {
                    throw new UnexpectedValueException('Invalid request history record.');
                }
                $at = (new DateTimeImmutable($record['requestAt']))->format('Y-m-d\TH:i:sP');
                if (DateTimeImmutable::getLastErrors() !== false) {
                    throw new UnexpectedValueException('Invalid request history timestamp.');
                }
                $ip = $record['requestIp'] ?? null;
                $items[] = [
                    'at' => $at,
                    'type' => $record['srrResponseType'],
                    'ip' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
                    'agent' => self::diagnosticText($record['userAgent'] ?? null, 120),
                    'rule' => self::diagnosticText($record['srrRuleName'] ?? null, 80),
                ];
            }
            $result = ['state' => 'available', 'total' => $total, 'records' => $items];
        } catch (Throwable) {
            $result = $unknown;
        }

        $this->stillOwned($actorId, $subjectId, $orderId, $binding);

        return $result;
    }

    /**
     * @return array{state:string,nodes:list<array{name:string,state:string,regional:string,checkedAt:?string}>}
     */
    public function nodes(int $actorId, int $subjectId, int $orderId): array
    {
        $unknown = ['state' => 'unavailable', 'nodes' => []];
        $binding = $this->verified($actorId, $subjectId, $orderId);
        if ($binding === null) {
            return $unknown;
        }

        try {
            $data = (new RemnawaveUsersApi($this->factory->make()))->accessibleNodes($binding['id']);
            if (($data['userId'] ?? null) !== $binding['id']
                || ! is_array($data['activeNodes'] ?? null)
                || ! array_is_list($data['activeNodes'])
                || count($data['activeNodes']) > 100) {
                throw new UnexpectedValueException('Invalid accessible nodes response.');
            }

            $items = [];
            $seen = [];
            foreach (array_slice($data['activeNodes'], 0, 8) as $node) {
                $uuid = $node['uuid'] ?? null;
                if (! is_array($node) || ! is_string($uuid)
                    || preg_match('/\A[0-9a-fA-F-]{36}\z/D', $uuid) !== 1
                    || ! self::label($node['nodeName'] ?? null) || isset($seen[$uuid])) {
                    throw new UnexpectedValueException('Invalid accessible node.');
                }
                $seen[$uuid] = true;
                $observed = $this->filtering->node($actorId, $uuid);
                $items[] = [
                    'name' => $node['nodeName'],
                    'state' => $observed !== null && ($observed['node_fresh'] ?? false)
                        ? (string) $observed['state'] : 'unknown',
                    'regional' => $observed !== null && ($observed['regional_fresh'] ?? false)
                        ? (string) $observed['regional_outcome'] : 'unknown',
                    'checkedAt' => $observed['regional_at'] ?? $observed['node_at'] ?? null,
                ];
            }
            $result = ['state' => 'available', 'nodes' => $items];
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (Throwable) {
            $result = $unknown;
        }

        $this->stillOwned($actorId, $subjectId, $orderId, $binding);

        return $result;
    }

    /**
     * A diagnostic POST starts a server-side observation job, not a user mutation.
     * Feature-gated, root-only, one explicit start per service/actor/cooldown.
     *
     * @return array{state:string,nodes:list<array{name:string,reports:int,lastSeenAt:?string,country:?string,ips:list<array{ip:string,lastSeenAt:string}>}>}
     */
    public function startConnections(int $actorId, int $subjectId, int $orderId): array
    {
        $this->root($actorId);
        if (config('services.remnawave.connections_diagnostics_enabled') !== true) {
            return self::connectionState('disabled');
        }

        $binding = $this->verified($actorId, $subjectId, $orderId);
        if ($binding === null) {
            return self::connectionState('unavailable');
        }

        $key = self::connectionKey($actorId, $orderId);
        // Cache::add is atomic on the project's Redis cache store. Reserve BEFORE POST;
        // an ambiguous timeout must not cause a duplicate diagnostic job.
        $reserved = Cache::add($key, [
            'id' => $binding['id'], 'reference' => $binding['reference'],
            'status' => 'requested',
        ], 120);
        if (! $reserved) {
            $this->stillOwned($actorId, $subjectId, $orderId, $binding);
            $existing = Cache::get($key);

            return self::connectionState(
                is_array($existing) && ($existing['status'] ?? null) === 'uncertain' ? 'unavailable' : 'pending',
            );
        }

        try {
            $data = self::payload($this->factory->make()->call('ConnectionsController_connectionsByUser',
                ['userId' => $binding['id']]));
            $jobId = $data['jobId'] ?? null;
            if (! is_string($jobId) || preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $jobId) !== 1) {
                throw new UnexpectedValueException('Invalid connection job.');
            }
            Cache::put($key, [
                'id' => $binding['id'], 'reference' => $binding['reference'],
                'status' => 'requested', 'jobId' => $jobId,
            ], 180);
            $state = 'pending';
        } catch (Throwable) {
            // Retain an uncertain checkpoint so neither a retry nor a misleading
            // "still pending" message is produced before the cooldown expires.
            Cache::put($key, [
                'id' => $binding['id'], 'reference' => $binding['reference'],
                'status' => 'uncertain',
            ], 120);
            $state = 'unavailable';
        }

        $this->stillOwned($actorId, $subjectId, $orderId, $binding);

        return self::connectionState($state);
    }

    /**
     * Explicit on-demand GET polling only. IP details are root-only, bounded and ephemeral.
     *
     * @return array{state:string,nodes:list<array{name:string,reports:int,lastSeenAt:?string,country:?string,ips:list<array{ip:string,lastSeenAt:string}>}>}
     */
    public function connectionResult(int $actorId, int $subjectId, int $orderId): array
    {
        $this->root($actorId);
        if (config('services.remnawave.connections_diagnostics_enabled') !== true) {
            return self::connectionState('disabled');
        }
        $binding = $this->verified($actorId, $subjectId, $orderId);
        if ($binding === null) {
            return self::connectionState('unavailable');
        }
        $key = self::connectionKey($actorId, $orderId);
        $cache = Cache::get($key);
        if (! is_array($cache) || ($cache['id'] ?? null) !== $binding['id']
            || ($cache['reference'] ?? null) !== $binding['reference']) {
            return self::connectionState('unavailable');
        }
        if (($cache['status'] ?? null) === 'completed'
            && is_array($cache['nodes'] ?? null)) {
            $this->stillOwned($actorId, $subjectId, $orderId, $binding);

            return ['state' => 'completed', 'nodes' => $cache['nodes']];
        }
        $jobId = $cache['jobId'] ?? null;
        if (! is_string($jobId) || preg_match('/\A[A-Za-z0-9_-]{1,100}\z/D', $jobId) !== 1) {
            return self::connectionState(($cache['status'] ?? null) === 'uncertain' ? 'unavailable' : 'pending');
        }

        try {
            $data = self::payload($this->factory->make()->call('ConnectionsController_connectionsByUserResult',
                ['jobId' => $jobId]));
            if (! is_bool($data['isCompleted'] ?? null) || ! is_bool($data['isFailed'] ?? null)) {
                throw new UnexpectedValueException('Invalid connection job status.');
            }
            if ($data['isFailed']) {
                $result = self::connectionState('unavailable');
            } elseif (! $data['isCompleted']) {
                $result = self::connectionState('pending');
            } else {
                $job = $data['result'] ?? null;
                if (! is_array($job) || ($job['success'] ?? null) !== true
                    || ($job['userId'] ?? null) !== $binding['id']
                    || ! is_array($job['nodes'] ?? null) || ! array_is_list($job['nodes'])
                    || count($job['nodes']) > 100) {
                    throw new UnexpectedValueException('Invalid completed connections job.');
                }
                $nodes = [];
                foreach (array_slice($job['nodes'], 0, 8) as $node) {
                    if (! is_array($node) || ! self::label($node['nodeName'] ?? null)
                        || ! is_array($node['ips'] ?? null) || ! array_is_list($node['ips'])
                        || count($node['ips']) > 10000) {
                        throw new UnexpectedValueException('Invalid connection node response.');
                    }
                    $latestTimestamp = null;
                    $observations = [];
                    foreach ($node['ips'] as $seen) {
                        if (! is_array($seen) || ! is_string($seen['ip'] ?? null)
                            || filter_var($seen['ip'], FILTER_VALIDATE_IP) === false
                            || ! is_string($seen['lastSeen'] ?? null)
                            || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,9})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/D', $seen['lastSeen']) !== 1) {
                            throw new UnexpectedValueException('Invalid observed connection.');
                        }
                        $observed = new DateTimeImmutable($seen['lastSeen']);
                        if (DateTimeImmutable::getLastErrors() !== false) {
                            throw new UnexpectedValueException('Invalid observed connection timestamp.');
                        }
                        $timestamp = $observed->getTimestamp();
                        $latestTimestamp = $latestTimestamp === null ? $timestamp : max($latestTimestamp, $timestamp);
                        $observations[] = [
                            'ip' => $seen['ip'],
                            'lastSeenAt' => gmdate('Y-m-d\TH:i:s\Z', $timestamp),
                        ];
                    }
                    usort($observations, static fn (array $a, array $b): int => strcmp($b['lastSeenAt'], $a['lastSeenAt']));
                    $country = $node['countryCode'] ?? null;
                    $nodes[] = [
                        'name' => $node['nodeName'],
                        'reports' => count($node['ips']),
                        'lastSeenAt' => $latestTimestamp === null ? null : gmdate('Y-m-d\TH:i:s\Z', $latestTimestamp),
                        'country' => is_string($country) && preg_match('/\A[A-Z]{2}\z/D', $country) === 1 ? $country : null,
                        'ips' => array_slice($observations, 0, 10),
                    ];
                }
                $result = ['state' => 'completed', 'nodes' => $nodes];
            }
        } catch (Throwable) {
            $result = self::connectionState('unavailable');
        }
        $this->stillOwned($actorId, $subjectId, $orderId, $binding);
        if ($result['state'] === 'completed') {
            Cache::put($key, [
                'id' => $binding['id'], 'reference' => $binding['reference'],
                'status' => 'completed', 'nodes' => $result['nodes'],
            ], 180);
        }

        return $result;
    }

    /** @return array{state:string,nodes:list<array{name:string,reports:int,lastSeenAt:?string,country:?string,ips:list<array{ip:string,lastSeenAt:string}>}>} */
    private static function connectionState(string $state): array
    {
        return ['state' => $state, 'nodes' => []];
    }

    private static function connectionKey(int $actorId, int $orderId): string
    {
        return 'sup-diag:connections:'.$actorId.':'.$orderId;
    }

    /** @return array{id:int,reference:string}|null */
    private function verified(int $actorId, int $subjectId, int $orderId): ?array
    {
        $this->root($actorId);
        $data = $this->live->detail($actorId, $subjectId, $orderId);
        if ($data['state'] !== 'available' || $data['identityMatched'] !== true
            || ! is_int($data['remoteUserId'])) {
            return null;
        }
        $binding = $this->localBinding($subjectId, $orderId);
        if ($binding === null || (int) $binding->remote_user_id !== $data['remoteUserId']) {
            throw new AuthorizationException;
        }

        return ['id' => $data['remoteUserId'], 'reference' => (string) $binding->reference];
    }

    /** @param array{id:int,reference:string} $expected */
    private function stillOwned(int $actorId, int $subjectId, int $orderId, array $expected): void
    {
        $this->root($actorId);
        $binding = $this->localBinding($subjectId, $orderId);
        if ($binding === null || (int) $binding->remote_user_id !== $expected['id']
            || (string) $binding->reference !== $expected['reference']) {
            throw new AuthorizationException;
        }
    }

    private function root(int $actorId): void
    {
        $actor = User::query()->with('telegramAccount')->find($actorId);
        if ($actor === null || ! $this->trust->eligible($actor)) {
            throw new AuthorizationException;
        }
    }

    private function localBinding(int $subjectId, int $orderId): ?object
    {
        return DB::table('sales_orders as o')
            ->join('provisioning_order_links as l', 'l.sales_order_id', '=', 'o.id')
            ->join('provisioning_subscriptions as s', 's.id', '=', 'l.provisioning_subscription_id')
            ->where('o.id', $orderId)->where('o.buyer_user_id', $subjectId)
            ->whereIn('o.status', ['paid', 'fulfilled'])->where('s.state', 'active')
            ->first(['s.reference', 's.remote_user_id']);
    }

    /** @return array<string,mixed> */
    private static function payload(mixed $body): array
    {
        if (! is_array($body) || ! is_array($body['response'] ?? null)) {
            throw new UnexpectedValueException('Invalid Remnawave response envelope.');
        }

        return $body['response'];
    }

    private static function label(mixed $value): bool
    {
        return is_string($value) && trim($value) !== ''
            && mb_strlen($value) <= 80 && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
    }

    private static function diagnosticText(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || preg_match('/[\x00-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', $value) !== 0) {
            return null;
        }

        return mb_strlen($value) > $maxLength
            ? mb_substr($value, 0, $maxLength - 1).'…'
            : $value;
    }

    private static function responseType(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/\A[A-Za-z0-9_.-]{1,48}\z/D', $value) === 1;
    }
}
