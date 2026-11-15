<?php

declare(strict_types=1);

namespace App\Support\Network;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only Cloudflare Radar transport for Iran and the three mobile operators.
 *
 * Returns the provider's result (including its metadata/normalization), not an
 * invented "healthy" verdict. No public route, scheduled fetch or UI is wired.
 */
final class CloudflareRadarClient
{
    private const ORIGIN = 'https://api.cloudflare.com/client/v4';

    private const COOLDOWN_KEY = 'cloudflare:radar:rate-limited';

    private const MAX_RESPONSE_BYTES = 524288;

    private const MAX_EVENTS = 25;

    private const ASNS = [197207, 44244, 57218];

    private const WINDOWS = ['1d', '7d', '30d'];

    private const IQI_METRICS = ['BANDWIDTH', 'LATENCY', 'DNS'];

    /**
     * Normalized traffic time series; meta.normalization describes the numbers.
     *
     * @return array<string, mixed>
     */
    public function trafficSeries(?int $asn = null, string $window = '1d'): array
    {
        return $this->read('/radar/netflows/timeseries', [
            ...$this->scope($asn),
            'dateRange' => $this->window($window),
        ], 'series');
    }

    /**
     * Published Cloudflare outage annotations; absence is not proof of health.
     *
     * @return array<string, mixed>
     */
    public function outages(?int $asn = null, string $window = '7d'): array
    {
        return $this->read('/radar/annotations/outages', [
            ...$this->scope($asn),
            'dateRange' => $this->window($window),
            'limit' => self::MAX_EVENTS,
        ], 'annotations');
    }

    /**
     * Detected traffic anomalies, including unverified entries.
     *
     * @return array<string, mixed>
     */
    public function anomalies(?int $asn = null, string $window = '7d'): array
    {
        return $this->read('/radar/traffic_anomalies', [
            ...$this->scope($asn),
            'dateRange' => $this->window($window),
            'limit' => self::MAX_EVENTS,
        ], 'trafficAnomalies');
    }

    /**
     * IQI percentiles for BANDWIDTH, LATENCY or DNS; not an uptime percentage.
     *
     * @return array<string, mixed>
     */
    public function qualitySummary(string $metric = 'LATENCY', ?int $asn = null, string $window = '7d'): array
    {
        if (! in_array($metric, self::IQI_METRICS, true)) {
            throw new InvalidArgumentException('Unsupported Radar IQI metric.');
        }

        return $this->read('/radar/quality/iqi/summary', [
            ...$this->scope($asn),
            'metric' => $metric,
            'dateRange' => $this->window($window),
        ], 'summary');
    }

    /** @return array{location:string}|array{asn:int} */
    private function scope(?int $asn): array
    {
        if ($asn === null) {
            return ['location' => 'IR'];
        }

        if (! in_array($asn, self::ASNS, true)) {
            throw new InvalidArgumentException('Unsupported Radar ASN.');
        }

        return ['asn' => $asn];
    }

    private function window(string $window): string
    {
        if (! in_array($window, self::WINDOWS, true)) {
            throw new InvalidArgumentException('Unsupported Radar date range.');
        }

        return $window;
    }

    /**
     * Cache only validated successful reads across callers/workers. Rate-limit
     * cooldown is shared via the application's existing cache store.
     *
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function read(string $path, array $query, string $shape): array
    {
        ksort($query);
        $key = 'cloudflare:radar:v1:'.hash('sha256', $path.'?'.http_build_query($query));
        $seconds = max(60, min(3600, (int) config('services.cloudflare_radar.cache_seconds', 600)));

        /** @var mixed $result */
        $result = Cache::remember($key, $seconds, fn (): array => $this->fetch($path, $query, $shape));
        if (! is_array($result) || array_is_list($result)) {
            throw new RuntimeException('Invalid cached Radar response.');
        }

        return $result;
    }

    /**
     * Fixed HTTPS origin, GET only, no redirects, no raw response/token logging.
     *
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function fetch(string $path, array $query, string $shape): array
    {
        $token = config('services.cloudflare_radar.token');
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Cloudflare Radar token is not configured.');
        }

        if (Cache::get(self::COOLDOWN_KEY) === true) {
            throw new RuntimeException('Cloudflare Radar is rate-limited.');
        }

        try {
            $response = Http::withToken(trim($token))
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->get(self::ORIGIN.$path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException('Cloudflare Radar connection failed.');
        }

        if ($response->status() === 429) {
            Cache::put(self::COOLDOWN_KEY, true, now()->addMinute());

            throw new RuntimeException('Cloudflare Radar is rate-limited.');
        }

        if (! $response->successful() || $response->redirect()) {
            throw new RuntimeException('Cloudflare Radar request failed.');
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Cloudflare Radar response is too large.');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['success'] ?? null) !== true) {
            throw new RuntimeException('Cloudflare Radar returned an invalid envelope.');
        }

        $result = $body['result'] ?? null;
        if (! is_array($result) || array_is_list($result) || ! $this->validShape($result, $shape)) {
            throw new RuntimeException('Cloudflare Radar returned an invalid result.');
        }

        return $result;
    }

    /** @param array<string, mixed> $result */
    private function validShape(array $result, string $shape): bool
    {
        if ($shape === 'series') {
            $series = $result['serie_0'] ?? null;
            $timestamps = is_array($series) ? ($series['timestamps'] ?? null) : null;
            $values = is_array($series) ? ($series['values'] ?? null) : null;

            return is_array($result['meta'] ?? null)
                && is_array($timestamps) && array_is_list($timestamps)
                && is_array($values) && array_is_list($values)
                && count($timestamps) === count($values) && count($values) <= 3000;
        }

        if ($shape === 'summary') {
            return is_array($result['meta'] ?? null)
                && is_array($result['summary_0'] ?? null)
                && ! array_is_list($result['summary_0']);
        }

        if (! in_array($shape, ['annotations', 'trafficAnomalies'], true)) {
            return false;
        }

        $items = $result[$shape] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > self::MAX_EVENTS) {
            return false;
        }

        foreach ($items as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return false;
            }
        }

        return true;
    }
}
