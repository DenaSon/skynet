<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Arianet;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fixed-origin Cloud write transport. Application Actions own authorization,
 * one-use confirmations and durable reservation.
 *
 * The one configured Bearer token is shared with reads; a default-OFF write
 * gate remains independent of token scopes; Production purchases require
 * their own default-OFF, exclusively purchase-scoped authorization.
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
        // The old global write gate never authorizes a Production purchase.
        if (app()->environment('production') && config('services.arianet_cloud.purchase_enabled') !== true) {
            throw new RuntimeException('Cloud server creation is disabled in production.');
        }
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

    /** @return array{action_id:int} */
    public function maintenanceAction(int $serverId, string $operation, string $target = ''): array
    {
        if ($serverId < 1 || ! in_array($operation, ['rebuild', 'change_plan', 'delete'], true)
            || config('services.arianet_cloud.operations_enabled') !== true
            || config('services.arianet_cloud.maintenance_enabled') !== true) {
            throw new RuntimeException('Cloud maintenance is disabled or invalid.');
        }
        if ($operation === 'rebuild') {
            if (preg_match('/\A(?:ubuntu|debian)-[a-z0-9][a-z0-9._-]{0,54}\z/D', $target) !== 1) {
                throw new InvalidArgumentException('Invalid system image.');
            }
            $json = $this->post('/servers/'.$serverId.'/actions/rebuild', ['image' => $target], 202);
        } elseif ($operation === 'change_plan') {
            $plan = filter_var($target, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($plan === false || (string) $plan !== $target) {
                throw new InvalidArgumentException('Invalid Cloud plan.');
            }
            $json = $this->post('/servers/'.$serverId.'/actions/change_plan', ['plan_id' => $plan], 202);
        } else {
            if ($target !== '') {
                throw new InvalidArgumentException('Invalid delete target.');
            }
            // Keep snapshots; no cascade deletion of independent resources.
            $json = $this->post('/servers/'.$serverId.'?keep_backups=false&delete_snapshots=false', null, 202, 'DELETE');
        }
        $action = $json['action_id'] ?? null;
        if (! is_int($action) || $action < 1) {
            throw new ArianetCloudWriteUncertain('Cloud maintenance action receipt could not be verified.');
        }

        return ['action_id' => $action];
    }

    /** @return array{action_id:int} */
    public function powerAction(int $serverId, string $operation): array
    {
        if ($serverId < 1 || ! in_array($operation, ['poweron', 'shutdown', 'reboot'], true)) {
            throw new InvalidArgumentException('Invalid Cloud power action.');
        }

        $json = $this->post('/servers/'.$serverId.'/actions/'.$operation, null, 202);
        $action = $json['action_id'] ?? null;
        if (! is_int($action) || $action < 1) {
            throw new ArianetCloudWriteUncertain('Cloud power action receipt could not be verified.');
        }

        return ['action_id' => $action];
    }

    /**
     * @param  array<string, bool|int|string|list<int>>|null  $body
     * @return array<string, mixed>
     */
    private function post(string $path, ?array $body, int $expectedStatus, string $method = 'POST'): array
    {
        // This is an infrastructure-level safety gate, not the future RBAC gate.
        if ($path === '/servers') {
            // In Production, ONLY the independent purchase flag permits create.
            // This flag must not enable changeIpv4 or other provider writes.
            if ((app()->environment('production') && config('services.arianet_cloud.purchase_enabled') !== true)
                || (! app()->environment('production')
                    && config('services.arianet_cloud.purchase_enabled') !== true
                    && config('services.arianet_cloud.writes_enabled') !== true)) {
                throw new RuntimeException('Arianet Cloud purchase is disabled.');
            }
        } elseif (preg_match('~^/servers/[1-9][0-9]*/actions/(rebuild|change_plan)$~D', $path) === 1
            || ($method === 'DELETE' && preg_match('~^/servers/[1-9][0-9]*\?keep_backups=false&delete_snapshots=false$~D', $path) === 1)) {
            if (config('services.arianet_cloud.operations_enabled') !== true
                || config('services.arianet_cloud.maintenance_enabled') !== true) {
                throw new RuntimeException('Cloud maintenance is disabled.');
            }
        } elseif (preg_match('~^/servers/[1-9][0-9]*/actions/(poweron|shutdown|reboot)$~D', $path) === 1) {
            if (config('services.arianet_cloud.operations_enabled') !== true) {
                throw new RuntimeException('Cloud server operations are disabled.');
            }
        } elseif (config('services.arianet_cloud.writes_enabled') !== true) {
            throw new RuntimeException('Arianet Cloud writes are disabled.');
        }

        // One provider Bearer token may carry both read and write scopes.
        // Never infer authorization from its presence; require the write gate.
        // The old write gate remains independent of the purchase-only gate;
        // activating paid create does not enable IPv4 or another endpoint.

        $token = config('services.arianet_cloud.read_token');
        $timeout = config('services.arianet_cloud.timeout', 10);

        if (! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Arianet Cloud API token is not configured.');
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

            $response = $request->send($method, self::ORIGIN.$path, $body === null ? [] : ['json' => $body]);
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
