<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Arianet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * ARI-01B: transport-only Cloud writes. NOT wired to any Application Action,
 * Telegram route, command or scheduler. Default-OFF independent kill switch.
 *
 * Future callers must independently check trusted Super Admin authorization,
 * fresh price/IP snapshot, one-time confirmation and durable idempotency before
 * invoking either method. There is deliberately no retry or reconciliation write.
 */
final class ArianetCloudWriteClient
{
    private const ORIGIN = 'https://api.ariaservice.net/cloud/v1';

    private const MAX_RESPONSE_BYTES = 524288;

    /**
     * The transport supports exactly one server with an existing public SSH key.
     * Never accepts cloud-init, private keys, multiple purchases or generic JSON.
     *
     * Server creation is not provider-idempotent: future Application code must
     * reserve a durable intent BEFORE invoking this method. Failure or timeout
     * must be reconciled against GET inventory/actions, never replayed blindly.
     *
     * @return array{server_id:int,action_id:int}
     */
    public function createServer(
        string $name,
        string $datacenter,
        int $planId,
        string $image,
        int $sshKeyId,
    ): array {
        if (! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/D', $name)
            || ! preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $datacenter)
            || ! preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $image)
            || $planId < 1 || $sshKeyId < 1) {
            throw new InvalidArgumentException('Invalid Arianet Cloud create parameters.');
        }

        $json = $this->post('/servers', [
            'name' => $name,
            'datacenter' => $datacenter,
            'plan_id' => $planId,
            'image' => $image,
            'ssh_key_ids' => [$sshKeyId],
            'count' => 1,
        ], 201);

        $server = $json['data'] ?? null;
        $created = $json['servers'] ?? null;
        $id = is_array($server) ? ($server['id'] ?? null) : null;
        $action = $json['action_id'] ?? null;

        if (! is_int($id) || $id < 1 || ! is_int($action) || $action < 1
            || ! is_array($created) || ! array_is_list($created) || count($created) !== 1
            || ! is_array($created[0]['data'] ?? null)
            || ($created[0]['data']['id'] ?? null) !== $id
            || ($created[0]['action_id'] ?? null) !== $action) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud create response could not be verified.');
        }

        // Deliberately discard provider's one-time root_password and raw response.
        return ['server_id' => $id, 'action_id' => $action];
    }

    /**
     * Executes only the named IP-change action. The caller, not this transport,
     * must attest a fresh server/fee snapshot and explicit root approval.
     * True is sent only after paid fee confirmation; free changes use no body.
     *
     * @return array{action_id:int}
     */
    public function changeIpv4(int $serverId, bool $confirmPaid = false): array
    {
        if ($serverId < 1) {
            throw new InvalidArgumentException('Invalid Arianet Cloud server ID.');
        }

        $json = $this->post(
            '/servers/'.$serverId.'/actions/change_ipv4',
            $confirmPaid ? ['confirm_paid' => true] : null,
            202,
        );

        $action = $json['action_id'] ?? null;
        if (! is_int($action) || $action < 1) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud IP-change response could not be verified.');
        }

        return ['action_id' => $action];
    }

    /**
     * @param array<string, bool|int|string>|null $body
     * @return array<string, mixed>
     */
    private function post(string $path, ?array $body, int $expectedStatus): array
    {
        // This is an infrastructure-level safety gate, not the future RBAC gate.
        if (config('services.arianet_cloud.writes_enabled') !== true) {
            throw new RuntimeException('Arianet Cloud writes are disabled.');
        }

        $token = config('services.arianet_cloud.write_token');
        $readToken = config('services.arianet_cloud.read_token');
        $timeout = config('services.arianet_cloud.timeout', 10);

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Arianet Cloud write token is not configured.');
        }

        if (is_string($readToken) && trim($readToken) !== ''
            && hash_equals(trim($token), trim($readToken))) {
            throw new RuntimeException('Arianet Cloud read and write tokens must be independent.');
        }

        if (! is_int($timeout) || $timeout < 1 || $timeout > 30) {
            throw new RuntimeException('Invalid Arianet Cloud timeout.');
        }

        try {
            $request = Http::withToken(trim($token))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(3, $timeout))
                ->timeout($timeout)
                ->withOptions(['allow_redirects' => false]);

            $response = $request->send('POST', self::ORIGIN.$path, $body === null ? [] : ['json' => $body]);
        } catch (ConnectionException) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud write outcome is unknown (connection failure).');
        }

        // 5xx, 429, redirects and unexpected statuses can all be ambiguous.
        // Never include raw response bodies, headers or credential-bearing URLs.
        if ($response->redirect() || $response->status() >= 500 || $response->status() === 429) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud write outcome is unknown.');
        }

        if (in_array($response->status(), [400, 401, 402, 403, 404, 409, 422, 423], true)) {
            throw new RuntimeException('Arianet Cloud write was rejected (HTTP '.$response->status().').');
        }

        if ($response->status() !== $expectedStatus || strlen($response->body()) > self::MAX_RESPONSE_BYTES) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud write response could not be verified.');
        }

        $json = $response->json();
        if (! is_array($json) || array_is_list($json)) {
            throw new ArianetCloudWriteUncertain('Arianet Cloud write response could not be verified.');
        }

        return $json;
    }
}
