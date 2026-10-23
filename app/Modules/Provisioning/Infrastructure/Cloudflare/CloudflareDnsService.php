<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Cloudflare;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Narrow Cloudflare DNS transport, shared by server setup and future DNS management.
 * Never retries a write; responses contain no credentials.
 */
final readonly class CloudflareDnsService
{
    /** @return list<array<string, mixed>> */
    public function zonesByName(string $name): array
    {
        return $this->getList('/zones', ['name' => $name, 'per_page' => 5]);
    }

    /** @return list<array<string, mixed>> */
    public function records(string $zoneId, string $hostname): array
    {
        return $this->getList('/zones/'.$zoneId.'/dns_records', [
            'name' => $hostname,
            'per_page' => 100,
        ]);
    }

    public function createA(string $zoneId, string $hostname, string $ip): void
    {
        $this->request('post', '/zones/'.$zoneId.'/dns_records', [
            'type' => 'A',
            'name' => $hostname,
            'content' => $ip,
            'ttl' => 1,
            'proxied' => false,
        ]);
    }

    /** @param array<string, int|string> $query
     * @return list<array<string, mixed>>
     */
    private function getList(string $path, array $query): array
    {
        $body = $this->request('get', $path, $query);
        $result = $body['result'] ?? null;
        if (! is_array($result) || ! array_is_list($result)) {
            throw new RuntimeException('Invalid Cloudflare list response.');
        }

        $total = $body['result_info']['total_count'] ?? null;
        if ((is_int($total) && $total > count($result)) || count($result) >= ($query['per_page'] ?? 100)) {
            throw new RuntimeException('Cloudflare results exceed the bounded read.');
        }

        foreach ($result as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Malformed Cloudflare item.');
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $data): array
    {
        $token = config('services.cloudflare.token');
        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Cloudflare is not configured.');
        }

        $request = Http::withToken($token)->acceptJson()->timeout(12);
        $url = 'https://api.cloudflare.com/client/v4'.$path;
        $response = $method === 'get'
            ? $request->get($url, $data)
            : $request->post($url, $data);

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['success'] ?? null) !== true) {
            throw new RuntimeException('Cloudflare request failed or returned an invalid response.');
        }

        return $body;
    }
}
