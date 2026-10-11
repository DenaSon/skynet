<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Typed internal UsersController v3.4.4 lifecycle adapter.
 * Network operations are NOT authorization, financial settlement or idempotency.
 * NEVER expose this class directly to Telegram/web untrusted input.
 */
final readonly class RemnawaveUsersApi
{
    public function __construct(private RemnawaveApiClient $client) {}

    public function create(CreateRemnawaveUser $data): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_createUser', body: $data->body());
    }

    public function byId(int $id): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_getUserById', ['userId' => self::id($id)]);
    }

    public function byUsername(string $username): RemnawaveUserSnapshot
    {
        CreateRemnawaveUser::validateUsername($username);

        return $this->user('UsersController_getUserByUsername', ['username' => $username]);
    }

    public function byShortUuid(string $shortUuid): RemnawaveUserSnapshot
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $shortUuid)) {
            throw new InvalidArgumentException('Invalid short subscription UUID.');
        }

        return $this->user('UsersController_getUserByShortUuid', ['shortUuid' => $shortUuid]);
    }

    /**
     * @return array{users: list<RemnawaveUserSnapshot>, total: int}
     */
    public function list(int $start = 0, int $size = 50): array
    {
        if ($start < 0 || $size < 1 || $size > 1000) {
            throw new InvalidArgumentException('Invalid Remnawave users pagination.');
        }

        $data = $this->object('UsersController_getUsers', query: ['start' => $start, 'size' => $size]);
        $users = $data['users'] ?? null;
        $total = $data['total'] ?? null;

        if (! is_array($users) || ! array_is_list($users) || ! is_int($total) || $total < 0) {
            throw new UnexpectedValueException('Invalid Remnawave paginated user response.');
        }

        $result = [];

        foreach ($users as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException('Invalid Remnawave user list item.');
            }

            $result[] = RemnawaveUserSnapshot::fromResponse($item);
        }

        return ['users' => $result, 'total' => $total];
    }

    /**
     * Changes only explicitly permitted user fields; always identifies by numeric user ID.
     *
     * @param array<string, mixed> $fields
     */
    public function update(int $id, array $fields): RemnawaveUserSnapshot
    {
        $allowed = [
            'status', 'trafficLimitBytes', 'trafficLimitStrategy', 'expireAt',
            'description', 'tag', 'hwidDeviceLimit', 'activeInternalSquads',
        ];

        if ($fields === []) {
            throw new InvalidArgumentException('Empty Remnawave user update.');
        }

        foreach (array_keys($fields) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported Remnawave user update field.');
            }
        }

        if (array_key_exists('status', $fields)
            && ! in_array($fields['status'], ['ACTIVE', 'DISABLED'], true)) {
            throw new InvalidArgumentException('Invalid mutable Remnawave user status.');
        }

        if (array_key_exists('trafficLimitBytes', $fields)) {
            if (! is_int($fields['trafficLimitBytes'])) {
                throw new InvalidArgumentException('Traffic limit must be integer bytes.');
            }

            CreateRemnawaveUser::validateLimit($fields['trafficLimitBytes']);
        }

        if (array_key_exists('trafficLimitStrategy', $fields)
            && ! in_array($fields['trafficLimitStrategy'], ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'], true)) {
            throw new InvalidArgumentException('Invalid traffic reset strategy.');
        }

        if (array_key_exists('expireAt', $fields)) {
            if (! is_string($fields['expireAt'])) {
                throw new InvalidArgumentException('Invalid expiration value.');
            }

            CreateRemnawaveUser::validateExpiry($fields['expireAt']);
        }

        if (array_key_exists('activeInternalSquads', $fields)) {
            if (! is_array($fields['activeInternalSquads']) || ! array_is_list($fields['activeInternalSquads'])) {
                throw new InvalidArgumentException('Invalid internal squads.');
            }

            CreateRemnawaveUser::validateSquads($fields['activeInternalSquads']);
        }

        if (array_key_exists('hwidDeviceLimit', $fields)
            && (! is_int($fields['hwidDeviceLimit']) || $fields['hwidDeviceLimit'] < 0 || $fields['hwidDeviceLimit'] > 9007199254740991)) {
            throw new InvalidArgumentException('Invalid device limit.');
        }

        if (array_key_exists('tag', $fields)
            && $fields['tag'] !== null
            && (! is_string($fields['tag']) || ! preg_match('/^[A-Z0-9_]{1,16}$/D', $fields['tag']))) {
            throw new InvalidArgumentException('Invalid Remnawave tag.');
        }

        if (array_key_exists('description', $fields)
            && $fields['description'] !== null
            && (! is_string($fields['description']) || mb_strlen($fields['description']) > 500)) {
            throw new InvalidArgumentException('Invalid description.');
        }

        return $this->user('UsersController_updateUser', body: ['id' => self::id($id), ...$fields]);
    }

    public function enable(int $id): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_enableUser', ['userId' => self::id($id)]);
    }

    public function disable(int $id): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_disableUser', ['userId' => self::id($id)]);
    }

    /** Additive/non-idempotent: never retry without reconciliation. */
    public function extend(int $id, int $days): RemnawaveUserSnapshot
    {
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Expiration extension must be 1 to 3650 days.');
        }

        return $this->user('UsersController_extendUserExpirationDate',
            ['userId' => self::id($id)], body: ['days' => $days]);
    }

    public function resetTraffic(int $id): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_resetUserTraffic', ['userId' => self::id($id)]);
    }

    /** Revocation rotates credentials; never call implicitly in a customer renewal. */
    public function revoke(int $id, bool $passwordsOnly = false): RemnawaveUserSnapshot
    {
        return $this->user('UsersController_revokeUserSubscription',
            ['userId' => self::id($id)], body: ['revokeOnlyPasswords' => $passwordsOnly]);
    }

    /** Destructive; requires future audited/confirmed Application action. */
    public function delete(int $id): void
    {
        $this->client->call('UsersController_deleteUser', ['userId' => self::id($id)]);
    }

    /** @return array<string, mixed> */
    public function accessibleNodes(int $id): array
    {
        return $this->object('UsersController_getUserAccessibleNodes', ['userId' => self::id($id)]);
    }

    /** @return array<string, mixed> */
    public function requestHistory(int $id): array
    {
        return $this->object('UsersController_getUserSubscriptionRequestHistory', ['userId' => self::id($id)]);
    }

    /** @return array<string, mixed> */
    public function tags(): array
    {
        return $this->object('UsersController_getUsersTags');
    }

    /** @param array<string, int|string> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     */
    private function user(string $operation, array $path = [], array $query = [], ?array $body = null): RemnawaveUserSnapshot
    {
        return RemnawaveUserSnapshot::fromResponse($this->object($operation, $path, $query, $body));
    }

    /**
     * @param array<string, int|string> $path
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function object(string $operation, array $path = [], array $query = [], ?array $body = null): array
    {
        $data = $this->client->call($operation, pathParameters: $path, query: $query, body: $body);

        if (! is_array($data)
            || ! isset($data['response'])
            || ! is_array($data['response'])
            || (array_is_list($data['response']) && $data['response'] !== [])) {
            throw new UnexpectedValueException('Malformed Remnawave user response envelope.');
        }

        return $data['response'];
    }

    private static function id(int $id): int
    {
        if ($id < 1) {
            throw new InvalidArgumentException('Remnawave numeric user ID must be positive.');
        }

        return $id;
    }
}
