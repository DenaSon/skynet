<?php

namespace App\Interfaces\Telegram\Processing;

use InvalidArgumentException;

final readonly class TelegramBotContext
{
    public const ADMIN = 'admin';

    public const SALES = 'sales';

    public function __construct(public string $key)
    {
        if (! in_array($key, [self::ADMIN, self::SALES], true)) {
            throw new InvalidArgumentException('Unknown Telegram bot context.');
        }
    }

    public static function current(): self
    {
        // This value comes from a server-owned route default, never from update JSON.
        $key = request()->route('bot_key');

        return new self($key === self::SALES ? self::SALES : self::ADMIN);
    }

    public function cacheKey(string $legacyKey): string
    {
        // Preserve in-flight keys on the original bot until their normal TTL expires.
        return $this->key === self::ADMIN ? $legacyKey : 'telegram:sales:'.$legacyKey;
    }
}
