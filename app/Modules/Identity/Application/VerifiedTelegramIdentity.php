<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

final readonly class VerifiedTelegramIdentity
{
    public function __construct(
        public string $telegramUserId,
        public string $oidcSubject,
        public ?string $name = null,
        public ?string $username = null,
        public ?string $picture = null,
    ) {}
}
