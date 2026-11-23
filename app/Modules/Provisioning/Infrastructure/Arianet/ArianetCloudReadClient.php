<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Arianet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * ARI-01A: authenticated, strictly GET-only transport for Arianet Cloud.
 *
 * Returns provider JSON envelopes unchanged. It has no write operations, public
 * routes, scheduled jobs or retry behavior. Consumers must authorize access and
 * validate business-specific fields before presenting or acting on the data.
 */
final class ArianetCloudReadClient
{
    private const ORIGIN = 'https://api.ariaservice.net/cloud/v1';

    private const MAX_RESPONSE_BYTES = 1048576;

    /** @return array<string, mixed> */
    public function datacenters(): array
    {
        return $this->read('/datacenters');
    }

    /** @return array<string, mixed> */
    public function plans(string $datacenter): array
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $datacenter)) {
            throw new InvalidArgumentException('Invalid Arianet Cloud datacenter code.');
        }

        return $this->read('/plans', ['datacenter' => $datacenter]);
    }

    /** @return array<string, mixed> */
    public function pricing(): array
    {
        return $this->read('/pricing');
    }

    /** @return array<string, mixed> */
    public function limits(): array
    {
        return $this->read('/limits');
    }

    /** @return array<string, mixed> */
    public function servers(int $page = 1, int $perPage = 25): array
    {
        return $this->read('/servers', $this->pagination($page, $perPage));
    }

    /** @return array<string, mixed> */
    public function server(int $serverId): array
    {
        return $this->read('/servers/'.$this->positiveId($serverId));
    }

    /** @return array<string, mixed> */
    public function networking(int $serverId): array
    {
        return $this->read('/servers/'.$this->positiveId($serverId).'/networking');
    }

    /** @return array<string, mixed> */
    public function costs(int $serverId): array
    {
        return $this->read('/servers/'.$this->positiveId($serverId).'/costs');
    }

    /** @return array<string, mixed> */
    public function ipChanges(int $serverId, int $page = 1, int $perPage = 25): array
    {
        return $this->read(
            '/servers/'.$this->positiveId($serverId).'/ip-changes',
            $this->pagination($page, $perPage),
        );
    }

    /** @return array<string, mixed> */
    public function actions(int $page = 1, int $perPage = 25): array
    {
        return $this->read('/actions', $this->pagination($page, $perPage));
    }

    /** @return array<string, mixed> */
    public function serverActions(int $serverId, int $page = 1, int $perPage = 25): array
    {
        return $this->read(
            '/servers/'.$this->positiveId($serverId).'/actions',
            $this->pagination($page, $perPage),
        );
    }

    /** @return array<string, mixed> */
    public function action(int $actionId): array
    {
        return $this->read('/actions/'.$this->positiveId($actionId));
    }

    private function positiveId(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Arianet Cloud resource ID must be positive.');
        }

        return $id;
    }

    /**
     * @return array{page:int,per_page:int}
     */
    private function pagination(int $page, int $perPage): array
    {
        if ($page < 1 || $page > 10000 || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('Invalid Arianet Cloud pagination.');
        }

        return ['page' => $page, 'per_page' => $perPage];
    }

    /**
     * @param  array<string, int|string>  $query
     * @return array<string, mixed>
     */
    private function read(string $path, array $query = []): array
    {
        $token = config('services.arianet_cloud.read_token');
        $timeout = config('services.arianet_cloud.timeout', 10);

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Arianet Cloud read API is not configured.');
        }

        if (! is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw new RuntimeException('Invalid Arianet Cloud timeout.');
        }

        try {
            $response = Http::withToken(trim($token))
                ->acceptJson()
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false])
                ->get(self::ORIGIN.$path, $query);
        } catch (ConnectionException) {
            // No sensitive upstream exception, response body or credential in diagnostics.
            throw new RuntimeException('Arianet Cloud connection failed.');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Arianet Cloud rate limit reached.');
        }

        if ($response->redirect() || ! $response->successful()) {
            throw new RuntimeException('Arianet Cloud request failed (HTTP '.$response->status().').');
        }

        if (strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new RuntimeException('Arianet Cloud response is too large.');
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)
            || ! array_key_exists('data', $json) || ! is_array($json['data'])) {
            throw new RuntimeException('Invalid Arianet Cloud response envelope.');
        }

        return $json;
    }
}
