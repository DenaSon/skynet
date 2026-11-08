<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Infrastructure;

use App\Support\Health\NodeTcpProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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

    private const MAX_IR_NODES = 4;

    private const MAX_RESPONSE_BYTES = 160000;

    private const POLL_TIMEOUT_NS = 12_000_000_000;

    private const POLL_INTERVAL_MS = 1500;

    private const MAX_POLL_ATTEMPTS = 9;

    private const MIN_RESULT_HTTP_TIMEOUT_NS = 100_000_000;

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

        // With exactly four probes, every result must cover a different ASN
        // and at least two cities. Prefer a deterministic cross-city/ASN pair,
        // then fill with unused ASNs. Insufficient diversity means no probe.
        $selected = [];
        foreach ($list as $first) {
            foreach ($list as $second) {
                if ($first['asn'] !== $second['asn'] && $first['city'] !== $second['city']) {
                    $selected = [$first, $second];
                    break 2;
                }
            }
        }
        if ($selected === []) {
            return [];
        }

        $usedAsns = array_fill_keys(array_column($selected, 'asn'), true);
        foreach ($list as $node) {
            if (count($selected) >= self::MAX_IR_NODES) {
                break;
            }
            if (isset($usedAsns[$node['asn']])) {
                continue;
            }
            $selected[] = $node;
            $usedAsns[$node['asn']] = true;
        }

        return count($selected) === self::MAX_IR_NODES ? $selected : [];
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
        $startHttpAt = hrtime(true);
        $started = $this->get('/check-tcp?'.$query);
        $startHttpMs = intdiv(hrtime(true) - $startHttpAt, 1_000_000);
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

        // Follow NVLBot's bounded deadline, not a fixed three-result snapshot window.
        // Also cap attempts so fake clocks and unusually fast responses cannot spin.
        $startedAt = hrtime(true);
        $deadline = $startedAt + self::POLL_TIMEOUT_NS;
        $latest = [];
        $progress = [];
        $resultHttpTotalMs = 0;
        $resultHttpMaxMs = 0;
        for ($attempt = 0; $attempt < self::MAX_POLL_ATTEMPTS; $attempt++) {
            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                break;
            }
            if ($attempt > 0) {
                Sleep::for((int) min(self::POLL_INTERVAL_MS, max(1, intdiv($remaining, 1_000_000))))
                    ->milliseconds();
                $remaining = $deadline - hrtime(true);
                if ($remaining <= 0) {
                    break;
                }
            }

            // A nearly exhausted deadline must not create an invalid sub-millisecond
            // Guzzle timeout. Keep the latest valid snapshot and finish as pending.
            $timeout = self::resultPollTimeout($remaining);
            if ($timeout === null) {
                break;
            }

            $resultHttpAt = hrtime(true);
            $latest = $this->get('/check-result/'.rawurlencode($id), $timeout);
            $resultHttpMs = intdiv(hrtime(true) - $resultHttpAt, 1_000_000);
            $resultHttpTotalMs += $resultHttpMs;
            $resultHttpMaxMs = max($resultHttpMaxMs, $resultHttpMs);
            if (isset($latest['error'])) {
                throw new RuntimeException('Check-Host result unavailable.');
            }

            $completedNow = 0;
            $invalidNow = 0;
            foreach ($nodes as $node) {
                $raw = $latest[$node['name']] ?? null;
                if (self::result($raw, $ip) !== null) {
                    $completedNow++;
                } elseif ($raw !== null) {
                    $invalidNow++;
                }
            }
            $progress[] = [
                'completed' => $completedNow,
                'pending' => count($nodes) - $completedNow - $invalidNow,
                'invalid' => $invalidNow,
            ];
            if ($completedNow === count($nodes)) {
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

        $diagnostics = [
            'selected' => count($nodes),
            'completed' => $completed,
            'failed' => $failed,
            'pending' => count($nodes) - $completed,
            'polls' => count($progress),
            'start_http_ms' => $startHttpMs,
            'result_http_total_ms' => $resultHttpTotalMs,
            'result_http_max_ms' => $resultHttpMaxMs,
            'elapsed_ms' => intdiv(hrtime(true) - $startedAt, 1_000_000),
        ];
        if ($completed < count($nodes)) {
            Log::notice('Filtering Check-Host TCP results incomplete', [
                ...$diagnostics,
                'progress' => $progress,
            ]);
        } else {
            Log::info('Filtering Check-Host TCP results complete', $diagnostics);
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
            // Only unambiguous TCP connection errors in alternate provider message fields.
            $message = $entry['message'] ?? null;
            if (is_string($message) && preg_match('/timeout|timed? out|refused|unreachable|no route to host|connection reset|reset by peer|could not connect/i', $message) === 1) {
                $failure = true;
            }
        }

        return $failure ? false : null;
    }

    private static function resultPollTimeout(int $remainingNs): ?float
    {
        if ($remainingNs < self::MIN_RESULT_HTTP_TIMEOUT_NS) {
            return null;
        }

        return min(6.0, $remainingNs / 1_000_000_000);
    }

    /** @return array<string,mixed> */
    private function get(string $path, float $timeoutSeconds = 6.0): array
    {
        $status = null;
        $requestStartedAt = hrtime(true);
        try {
            // Keep the existing provider-wide 429 cooldown across Node targets.
            if (Cache::get('filtering:regional:checkhost:cooldown') === true) {
                throw new RuntimeException('Check-Host provider is cooling down.');
            }
            $response = Http::acceptJson()->timeout($timeoutSeconds)
                ->connectTimeout(min(2.0, $timeoutSeconds))
                ->withHeaders(['User-Agent' => 'DenaGate-Regional-Monitor/FLT03'])
                ->withOptions(['allow_redirects' => false])
                ->get(self::ORIGIN.$path);
            $status = $response->status();
            if ($status === 429) {
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
            $cause = $error;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }
            $curlCode = null;
            if (preg_match('/cURL error ([0-9]+)/i', $cause->getMessage(), $matches) === 1) {
                $curlCode = (int) $matches[1];
            }
            // Never log the URL/path, remote request ID, target, response body or exception message.
            Log::warning('Filtering Check-Host request unavailable', [
                'phase' => str_starts_with($path, '/nodes/hosts') ? 'discovery'
                    : (str_starts_with($path, '/check-tcp?') ? 'start' : 'result'),
                'http_status' => $status,
                'exception_type' => class_basename($cause),
                'curl_code' => $curlCode,
                'elapsed_ms' => intdiv(hrtime(true) - $requestStartedAt, 1_000_000),
            ]);

            throw new RuntimeException('Check-Host request unavailable.', 0, $error);
        }
    }

    private static function validNodeName(string $name): bool
    {
        return strlen($name) <= 100
            && preg_match('/\\A[a-z0-9][a-z0-9.-]*\\.node\\.check-host\\.net\\z/D', $name) === 1;
    }
}
