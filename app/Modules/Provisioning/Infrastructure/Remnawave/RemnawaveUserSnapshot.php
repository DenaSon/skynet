<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use UnexpectedValueException;

/**
 * Minimal provider user projection; subscriptionUrl is a credential.
 * Never log or serialize this object into untrusted/public responses.
 */
final readonly class RemnawaveUserSnapshot
{
    /** @param list<string> $activeInternalSquadUuids */
    public function __construct(
        public int $id,
        public string $username,
        public string $shortUuid,
        public string $status,
        public int $trafficLimitBytes,
        public string $trafficLimitStrategy,
        public string $expireAt,
        public string $subscriptionUrl,
        public int $usedTrafficBytes,
        public ?string $description,
        public array $activeInternalSquadUuids,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromResponse(array $data): self
    {
        $id = $data['id'] ?? null;
        $username = $data['username'] ?? null;
        $shortUuid = $data['shortUuid'] ?? null;
        $status = $data['status'] ?? null;
        $limit = $data['trafficLimitBytes'] ?? null;
        $strategy = $data['trafficLimitStrategy'] ?? null;
        $expire = $data['expireAt'] ?? null;
        $url = $data['subscriptionUrl'] ?? null;
        $usage = $data['userTraffic']['usedTrafficBytes'] ?? null;
        $description = $data['description'] ?? null;
        $squads = $data['activeInternalSquads'] ?? null;

        if (! is_int($id) || $id < 1
            || ! is_string($username) || $username === ''
            || ! is_string($shortUuid) || $shortUuid === ''
            || ! is_string($status) || ! in_array($status, ['ACTIVE', 'DISABLED', 'LIMITED', 'EXPIRED'], true)
            || ! is_int($limit) || $limit < 0
            || ! is_string($strategy) || ! in_array($strategy, ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'], true)
            || ! is_string($expire) || $expire === ''
            || ! is_string($url) || ! str_starts_with($url, 'https://')
            || ! is_int($usage) || $usage < 0
            || ($description !== null && ! is_string($description))
            || ! is_array($squads) || ! array_is_list($squads)) {
            throw new UnexpectedValueException('Invalid Remnawave user response contract.');
        }

        $squadUuids = [];

        foreach ($squads as $squad) {
            if (! is_array($squad)
                || ! is_string($squad['uuid'] ?? null)
                || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $squad['uuid'])) {
                throw new UnexpectedValueException('Invalid Remnawave user squad response.');
            }

            $squadUuids[] = strtolower($squad['uuid']);
        }

        if (count(array_unique($squadUuids)) !== count($squadUuids)) {
            throw new UnexpectedValueException('Duplicate Remnawave squad in user response.');
        }

        return new self($id, $username, $shortUuid, $status, $limit, $strategy, $expire, $url, $usage, $description, $squadUuids);
    }
}
