<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

final readonly class TelegramOidcAuthorization
{
    public function __construct(
        public string $authorizationUrl,
        public string $state,
        public string $nonce,
        public string $codeVerifier,
    ) {}
}
