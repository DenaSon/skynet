<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use RuntimeException;

final class NotificationUnavailable extends RuntimeException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct('Notification destination unavailable.');
    }
}
