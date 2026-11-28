<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Telegram;

use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramOidcClient
{
    public const ISSUER = 'https://oauth.telegram.org';

    public const JWKS_URI = self::ISSUER.'/.well-known/jwks.json';

    private const AUTHORIZATION_ENDPOINT = self::ISSUER.'/auth';

    private const TOKEN_ENDPOINT = self::ISSUER.'/token';

    public function isConfigured(): bool
    {
        $clientId = config('services.telegram_oidc.client_id');
        $clientSecret = config('services.telegram_oidc.client_secret');

        return is_string($clientId)
            && trim($clientId) !== ''
            && is_string($clientSecret)
            && trim($clientSecret) !== '';
    }

    public function clientId(): string
    {
        $clientId = config('services.telegram_oidc.client_id');

        if (! is_string($clientId) || trim($clientId) === '') {
            throw new RuntimeException('Telegram OIDC client is not configured.');
        }

        return trim($clientId);
    }

    public function authorizationUrl(
        string $redirectUri,
        string $state,
        string $nonce,
        string $codeChallenge,
    ): string {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Telegram OIDC client is not configured.');
        }

        return self::AUTHORIZATION_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri, string $codeVerifier): string
    {
        $clientId = $this->clientId();
        $clientSecret = config('services.telegram_oidc.client_secret');

        if (! is_string($clientSecret) || trim($clientSecret) === '') {
            throw new RuntimeException('Telegram OIDC client is not configured.');
        }

        $response = Http::acceptJson()
            ->asForm()
            ->withBasicAuth($clientId, trim($clientSecret))
            ->timeout(10)
            ->connectTimeout(3)
            ->withoutRedirecting()
            ->post(self::TOKEN_ENDPOINT, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $clientId,
                'code_verifier' => $codeVerifier,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Telegram authorization code exchange failed.');
        }

        $payload = $response->json();

        if (! is_array($payload)
            || isset($payload['error'])
            || ! is_string($payload['id_token'] ?? null)
            || $payload['id_token'] === '') {
            throw new RuntimeException('Telegram did not return a valid ID token.');
        }

        return $payload['id_token'];
    }
}
