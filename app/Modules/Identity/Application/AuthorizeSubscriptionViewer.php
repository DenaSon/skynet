<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;

/**
 * Fresh Identity resolution on EVERY subscription action, not merely
 * when a Telegram keyboard/session was first rendered.
 */
final readonly class AuthorizeSubscriptionViewer
{
    public function __construct(
        private ResolveTelegramActor $telegram,
        private ResolveSalesBuyer $buyers,
    ) {}

    public function userId(SubscriptionViewer $viewer): int
    {
        if ($viewer->channel === 'telegram' && $viewer->telegramUserId !== null
            && $viewer->telegramUserId > 0) {
            $actor = $this->telegram->handle($viewer->telegramUserId);
            $id = $actor?->userId;
        } elseif ($viewer->channel === 'web' && $viewer->telegramUserId === null) {
            $id = Auth::id();
        } else {
            throw new AuthorizationException;
        }

        if (! is_int($id) || $id < 1) {
            throw new AuthorizationException;
        }

        // This method checks active state, even when the account is not a reseller.
        // Role alone never grants access to another buyer's order.
        $this->buyers->isReseller($id);

        return $id;
    }
}
