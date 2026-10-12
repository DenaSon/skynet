<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Explicit v3.4.4 user creation contract for subscription provisioning.
 * API accepts extra fields; use an intentionally narrow safe subset here.
 */
final readonly class CreateRemnawaveUser
{
    /** @param list<string> $internalSquadUuids */
    public function __construct(
        public string $username,
        public string $expireAt,
        public int $trafficLimitBytes,
        public array $internalSquadUuids,
        public string $trafficLimitStrategy = 'NO_RESET',
        public string $status = 'ACTIVE',
        public ?string $description = null,
    ) {
        self::validateUsername($username);
        self::validateExpiry($expireAt);
        self::validateLimit($trafficLimitBytes);
        self::validateSquads($internalSquadUuids);

        if (! in_array($trafficLimitStrategy, ['NO_RESET', 'DAY', 'WEEK', 'MONTH', 'MONTH_ROLLING'], true)
            || ! in_array($status, ['ACTIVE', 'DISABLED'], true)) {
            throw new InvalidArgumentException('Invalid Remnawave user status or reset strategy.');
        }

        if ($description !== null && mb_strlen($description) > 500) {
            throw new InvalidArgumentException('Remnawave user description is too long.');
        }
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return array_filter([
            'username' => $this->username,
            'expireAt' => $this->expireAt,
            'trafficLimitBytes' => $this->trafficLimitBytes,
            'trafficLimitStrategy' => $this->trafficLimitStrategy,
            'status' => $this->status,
            'activeInternalSquads' => $this->internalSquadUuids,
            'description' => $this->description,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public static function validateUsername(string $username): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,36}$/D', $username)) {
            throw new InvalidArgumentException('Remnawave username must be 1–36 ASCII letters, digits, _ or -.');
        }
    }

    public static function validateExpiry(string $expireAt): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D', $expireAt)) {
            throw new InvalidArgumentException('Remnawave expiration must be UTC ISO-8601.');
        }

        try {
            $parsed = new DateTimeImmutable($expireAt);
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid Remnawave expiration date.');
        }

        if ($parsed->format('Y-m-d\TH:i:s') !== substr($expireAt, 0, 19)) {
            throw new InvalidArgumentException('Invalid Remnawave expiration date.');
        }
    }

    public static function validateLimit(int $bytes): void
    {
        if ($bytes < 0 || $bytes > 9007199254740991) {
            throw new InvalidArgumentException('Remnawave traffic limit is out of bounds.');
        }
    }

    /** @param array<mixed> $squads */
    public static function validateSquads(array $squads): void
    {
        if ($squads === [] || ! array_is_list($squads)) {
            throw new InvalidArgumentException('At least one Remnawave internal squad is required.');
        }

        foreach ($squads as $uuid) {
            if (! is_string($uuid) || ! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/D', $uuid)) {
                throw new InvalidArgumentException('Invalid Remnawave internal squad UUID.');
            }
        }

        if (count(array_unique($squads)) !== count($squads)) {
            throw new InvalidArgumentException('Duplicate Remnawave internal squad UUID.');
        }
    }
}
