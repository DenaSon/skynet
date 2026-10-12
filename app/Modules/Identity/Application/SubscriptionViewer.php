<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * Internal request context, NOT proof of authorization by itself.
 * Telegram ID must come from a validated webhook; a web request uses
 * the currently authenticated Laravel session, never a submitted user ID.
 */
final readonly class SubscriptionViewer
{
    private function __construct(
        public string $channel,
        public ?int $telegramUserId,
    ) {}

    public static function telegram(int $verifiedTelegramUserId): self
    {
        return new self('telegram', $verifiedTelegramUserId);
    }

    public static function web(): self
    {
        return new self('web', null);
    }
}
