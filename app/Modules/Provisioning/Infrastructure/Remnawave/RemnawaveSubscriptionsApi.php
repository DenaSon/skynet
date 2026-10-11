<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Protected subscription discovery. Responses contain bearer credentials:
 * never route raw values directly to logging or customer-visible handlers.
 */
final readonly class RemnawaveSubscriptionsApi
{
    public function __construct(private RemnawaveApiClient $client) {}

    /** @return array<string, mixed> */
    public function byUserId(int $userId): array
    {
        return $this->details('SubscriptionsController_getSubscriptionByUuid', [
            'userId' => self::userId($userId),
        ]);
    }

    /** @return array<string, mixed> */
    public function byUsername(string $username): array
    {
        CreateRemnawaveUser::validateUsername($username);

        return $this->details('SubscriptionsController_getSubscriptionByUsername', [
            'username' => $username,
        ]);
    }

    /** @return array<string, mixed> */
    public function byShortUuid(string $shortUuid): array
    {
        return $this->details('SubscriptionsController_getSubscriptionByShortUuidProtected', [
            'shortUuid' => self::shortUuid($shortUuid),
        ]);
    }

    /** @return array<string, mixed> */
    public function connectionKeys(int $userId): array
    {
        $data = $this->response('SubscriptionsController_getConnectionKeysByUserId', [
            'userId' => self::userId($userId),
        ]);

        foreach (['enabledKeys', 'hiddenKeys', 'disabledKeys'] as $key) {
            if (! is_array($data[$key] ?? null) || ! array_is_list($data[$key])) {
                throw new UnexpectedValueException('Invalid Remnawave connection keys response.');
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function details(string $operation, array $path): array
    {
        $data = $this->response($operation, $path);

        if (! is_bool($data['isFound'] ?? null)
            || ! is_array($data['links'] ?? null)
            || ! is_array($data['ssConfLinks'] ?? null)) {
            throw new UnexpectedValueException('Invalid Remnawave subscription response.');
        }

        if ($data['isFound'] === true && ! is_string($data['subscriptionUrl'] ?? null)) {
            throw new UnexpectedValueException('Missing Remnawave subscription URL.');
        }

        return $data;
    }

    /**
     * @param array<string, int|string> $path
     * @return array<string, mixed>
     */
    private function response(string $operation, array $path): array
    {
        $data = $this->client->call($operation, pathParameters: $path);

        if (! is_array($data) || ! isset($data['response']) || ! is_array($data['response'])
            || (array_is_list($data['response']) && $data['response'] !== [])) {
            throw new UnexpectedValueException('Invalid Remnawave subscription API envelope.');
        }

        return $data['response'];
    }

    private static function userId(int $id): int
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Remnawave user ID must be positive.');
        }

        return $id;
    }

    private static function shortUuid(string $value): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value)) {
            throw new InvalidArgumentException('Invalid Remnawave subscription short UUID.');
        }

        return $value;
    }
}
