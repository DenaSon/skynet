<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Telegram\TelegramIdTokenVerifier;
use App\Modules\Identity\Infrastructure\Telegram\TelegramOidcClient;

final readonly class TelegramOidcAuthentication
{
    public function __construct(
        private TelegramOidcClient $client,
        private TelegramIdTokenVerifier $verifier,
    ) {}

    public function begin(string $redirectUri): TelegramOidcAuthorization
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = $this->base64UrlEncode(random_bytes(32));
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

        return new TelegramOidcAuthorization(
            authorizationUrl: $this->client->authorizationUrl($redirectUri, $state, $nonce, $codeChallenge),
            state: $state,
            nonce: $nonce,
            codeVerifier: $codeVerifier,
        );
    }

    public function complete(
        string $code,
        string $redirectUri,
        string $codeVerifier,
        string $nonce,
    ): VerifiedTelegramIdentity {
        $idToken = $this->client->exchangeCode($code, $redirectUri, $codeVerifier);

        return $this->verifier->verify($idToken, $nonce);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
