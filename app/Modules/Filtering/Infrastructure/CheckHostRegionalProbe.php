<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Infrastructure;

use App\Support\Health\NodeTcpProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Fixed-origin, GET-only Check-Host TCP adapter. Never accepts a URL from
 * Telegram, a request or untrusted configuration. Target IPv4 is revalidated.
 */
final class CheckHostRegionalProbe
{
    private const ORIGIN = 'https://check-host.net';

    private const MAX_IR_NODES = 8;

    private const MAX_RESPONSE_BYTES = 160000;

    /** @return list<array{name:string,asn:string,city:string}> */
    public function iranNodes(): array
    {
        $response = $this->get('/nodes/hosts');
        $nodes = $response['nodes'] ?? null;
        if (! is_array($nodes) || count($nodes) > 600) {
            throw new RuntimeException('Invalid Check-Host discovery.');
        }
        $list = [];
        foreach ($nodes as $name => $row) {
            if (! is_string($name) || ! self::validNodeName($name) || ! is_array($row)) {
                continue;
            }
            $location = $row['location'] ?? null;
            $asn = $row['asn'] ?? null;
            if (! is_array($location) || ($location[0] ?? null) !== 'ir'
                || ! is_string($location[2] ?? null) || trim($location[2]) === ''
                || mb_strlen($location[2]) > 70
                || ! is_string($asn) || ! preg_match('/\AAS[1-9][0-9]{0,9}\z/D', $asn)) {
                continue;
            }
            $list[] = ['name' => $name, 'asn' => $asn, 'city' => $location[2]];
        }
        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        // First diversify ASNs/cities, then fill spare positions. Never
        // pretend that the provider always supplies eight Iranian nodes.
        $selected = [];
        $usedAsns = [];
        $usedCities = [];
        foreach ([true, false] as $diverse) {
            foreach ($list as $node) {
                if (count($selected) >= self::MAX_IR_NODES) {
                    break 2;
                }
                if (in_array($node['name'], array_column($selected, 'name'), true)) {
                    continue;
                }
                if ($diverse && isset($usedAsns[$node['asn']]) && isset($usedCities[$node['city']])) {
                    continue;
                }
                $selected[] = $node;
                $usedAsns[$node['asn']] = true;
                $usedCities[$node['city']] = true;
            }
        }

        return $selected;
    }

    /**
     * @param  list<array{name:string,asn:string,city:string}>  $nodes
     * @return array{completed_probes:int,failed_probes:int,pending_probes:int,asn_count:int,city_count:int,failed_asn_count:int,failed_city_count:int,successful_probes:int}
     */
    public function tcp(string $ip, int $port, array $nodes): array
    {
        if (! NodeTcpProbe::approvedPublicIpv4($ip) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Only approved public IPv4 TCP endpoints are permitted.');
        }
        if (count($nodes) < 1 || count($nodes) > self::MAX_IR_NODES) {
            throw new InvalidArgumentException('Invalid regional checking budget.');
        }
        $seen = [];
        foreach ($nodes as $node) {
            if (! self::validNodeName($node['name'])
                || ! preg_match('/\AAS[1-9][0-9]{0,9}\z/D', $node['asn'])
                || trim($node['city']) === '' || isset($seen[$node['name']])) {
                throw new InvalidArgumentException('Invalid Check-Host node metadata.');
            }
            $seen[$node['name']] = true;
        }

        $query = 'host='.rawurlencode($ip.':'.$port);
        foreach ($nodes as $node) {
            $query .= '&node='.rawurlencode($node['name']);
        }
        $started = $this->get('/check-tcp?'.$query);
        $id = $started['request_id'] ?? null;
        if (($started['ok'] ?? null) !== 1 || ! is_string($id)
            || ! preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', $id)) {
            throw new RuntimeException('Check-Host rejected the TCP check.');
        }
        $accepted = $started['nodes'] ?? null;
        if (! is_array($accepted) || count($accepted) !== count($nodes)
            || array_diff(array_column($nodes, 'name'), array_keys($accepted)) !== []) {
            throw new RuntimeException('Check-Host node selection changed.');
        }

        $latest = [];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if ($attempt > 0) {
                usleep(600000);
            }
            $latest = $this->get('/check-result/'.rawurlencode($id));
            if (array_keys($latest) === ['error'] || isset($latest['error'])) {
                throw new RuntimeException('Check-Host result unavailable.');
            }
            $done = true;
            foreach ($nodes as $node) {
                if (! isset($latest[$node['name']])) {
                    $done = false;
                }
            }
            if ($done) {
                break;
            }
        }

        $completed = 0;
        $failed = 0;
        $passed = 0;
        $asns = [];
        $cities = [];
        $failedAsns = [];
        $failedCities = [];
        foreach ($nodes as $node) {
            $result = self::result($latest[$node['name']] ?? null, $ip);
            if ($result === null) {
                continue; // Missing/malformed/pending is NEVER a failed TCP connection.
            }
            $completed++;
            $asns[$node['asn']] = true;
            $cities[$node['city']] = true;
            if ($result) {
                $passed++;
            } else {
                $failed++;
                $failedAsns[$node['asn']] = true;
                $failedCities[$node['city']] = true;
            }
        }

        return [
            'completed_probes' => $completed,
            'failed_probes' => $failed,
            'pending_probes' => count($nodes) - $completed,
            'asn_count' => count($asns),
            'city_count' => count($cities),
            'failed_asn_count' => count($failedAsns),
            'failed_city_count' => count($failedCities),
            'successful_probes' => $passed,
        ];
    }

    /**
     * @param  array<string,mixed>|list<mixed>|null  $raw
     * @return bool|null null is pending/invalid; never infer failure.
     */
    private static function result(mixed $raw, string $expectedIp): ?bool
    {
        if (! is_array($raw)) {
            return null;
        }
        $entries = array_is_list($raw) ? $raw : [$raw];
        $failure = false;
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (isset($entry['address'], $entry['time'])
                && is_string($entry['address']) && $entry['address'] === $expectedIp
                && (is_int($entry['time']) || is_float($entry['time']))
                && is_finite((float) $entry['time']) && $entry['time'] >= 0) {
                return true;
            }
            if (is_string($entry['error'] ?? null) && trim($entry['error']) !== '') {
                $failure = true;
            }
        }

        return $failure ? false : null;
    }

    /** @return array<string,mixed> */
    private function get(string $path): array
    {
        try {
            // Rate-limit backoff is provider-wide; a single 429 must not
            // trigger another four TCP submissions in the same scan.
            if (Cache::get('filtering:regional:checkhost:cooldown') === true) {
                throw new RuntimeException('Check-Host provider is cooling down.');
            }
            $response = Http::acceptJson()->timeout(5)->connectTimeout(2)
                ->withHeaders(['User-Agent' => 'DenaGate-Regional-Monitor/FLT03'])
                ->withOptions(['allow_redirects' => false])
                ->get(self::ORIGIN.$path);
            if ($response->status() === 429) {
                Cache::put('filtering:regional:checkhost:cooldown', true, now()->addMinutes(15));
                throw new RuntimeException('Check-Host provider rate limit.');
            }
            if (! $response->successful() || $response->redirect()
                || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
                throw new RuntimeException('Check-Host request failed or response too large.');
            }
            $json = $response->json();
            if (! is_array($json)) {
                throw new RuntimeException('Check-Host returned invalid JSON.');
            }

            return $json;
        } catch (Throwable $error) {
            throw new RuntimeException('Check-Host request unavailable.', 0, $error);
        }
    }

    private static function validNodeName(string $name): bool
    {
        return strlen($name) <= 100
            && preg_match('/\A[a-z0-9][a-z0-9.-]*\.node\.check-host\.net\z/D', $name) === 1;
    }
}
