<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Telegram;

use App\Modules\Identity\Application\VerifiedTelegramIdentity;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;

final readonly class TelegramIdTokenVerifier
{
    private const JWKS_CACHE_KEY = 'identity.telegram_oidc.jwks';

    public function __construct(
        private TelegramOidcClient $client,
    ) {}

    public function verify(string $idToken, string $expectedNonce): VerifiedTelegramIdentity
    {
        if (strlen($idToken) > 16384) {
            throw new RuntimeException('Telegram ID token is invalid.');
        }

        $header = $this->decodeHeader($idToken);
        $keyId = $header['kid'] ?? null;

        if (($header['alg'] ?? null) !== 'RS256'
            || ! is_string($keyId)
            || $keyId === ''
            || strlen($keyId) > 128) {
            throw new RuntimeException('Telegram ID token uses an unsupported signing key.');
        }

        $jwks = $this->loadJwks();

        if (! $this->containsSupportedKey($jwks, $keyId)) {
            // Refresh once when Telegram rotates its signing key.
            $jwks = $this->loadJwks(refresh: true);
        }

        $keySet = JWK::parseKeySet($this->supportedJwks($jwks), 'RS256');

        if (! isset($keySet[$keyId])) {
            throw new RuntimeException('Telegram signing key was not found.');
        }

        $claims = JWT::decode($idToken, $keySet);

        $this->assertClaims($claims, $expectedNonce);

        return new VerifiedTelegramIdentity(
            telegramUserId: $this->normalizeTelegramId($claims->id ?? null),
            subject: (string) $claims->sub,
            name: $this->optionalString($claims->name ?? null),
            username: $this->optionalString($claims->preferred_username ?? null),
            picture: $this->optionalString($claims->picture ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeHeader(string $idToken): array
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new RuntimeException('Telegram ID token is malformed.');
        }

        $encodedHeader = strtr($segments[0], '-_', '+/');
        $encodedHeader .= str_repeat('=', (4 - strlen($encodedHeader) % 4) % 4);
        $decodedHeader = base64_decode($encodedHeader, true);

        if (! is_string($decodedHeader)) {
            throw new RuntimeException('Telegram ID token header is malformed.');
        }

        $header = json_decode($decodedHeader, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($header)) {
            throw new RuntimeException('Telegram ID token header is malformed.');
        }

        return $header;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJwks(bool $refresh = false): array
    {
        if (! $refresh) {
            $cached = Cache::get(self::JWKS_CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(3)
            ->withoutRedirecting()
            ->get(TelegramOidcClient::JWKS_URI);

        if (! $response->successful()) {
            throw new RuntimeException('Unable to retrieve Telegram signing keys.');
        }

        $jwks = $response->json();

        if (! is_array($jwks) || ! is_array($jwks['keys'] ?? null)) {
            throw new RuntimeException('Telegram signing key set is invalid.');
        }

        Cache::put(self::JWKS_CACHE_KEY, $jwks, now()->addHour());

        return $jwks;
    }

    /**
     * @param  array<string, mixed>  $jwks
     */
    private function containsSupportedKey(array $jwks, string $keyId): bool
    {
        foreach ($jwks['keys'] ?? [] as $key) {
            if (! is_array($key)
                || ($key['kid'] ?? null) !== $keyId
                || ($key['kty'] ?? null) !== 'RSA'
                || (isset($key['alg']) && $key['alg'] !== 'RS256')
                || (isset($key['use']) && $key['use'] !== 'sig')
                || (isset($key['key_ops']) && (! is_array($key['key_ops']) || ! in_array('verify', $key['key_ops'], true)))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $jwks
     * @return array{keys: list<array<string, mixed>>}
     */
    private function supportedJwks(array $jwks): array
    {
        $keys = [];

        foreach ($jwks['keys'] ?? [] as $key) {
            if (! is_array($key)
                || ($key['kty'] ?? null) !== 'RSA'
                || ! is_string($key['kid'] ?? null)
                || $key['kid'] === ''
                || (isset($key['alg']) && $key['alg'] !== 'RS256')
                || (isset($key['use']) && $key['use'] !== 'sig')
                || (isset($key['key_ops']) && (! is_array($key['key_ops']) || ! in_array('verify', $key['key_ops'], true)))) {
                continue;
            }

            $keys[] = $key;
        }

        if ($keys === []) {
            throw new RuntimeException('Telegram has no supported RS256 signing keys.');
        }

        return ['keys' => $keys];
    }

    private function assertClaims(stdClass $claims, string $expectedNonce): void
    {
        $clientId = $this->client->clientId();

        if (($claims->iss ?? null) !== TelegramOidcClient::ISSUER
            || ! is_string($claims->sub ?? null)
            || trim($claims->sub) === ''
            || ! is_numeric($claims->iat ?? null)
            || ! is_numeric($claims->exp ?? null)
            || (int) $claims->exp <= (int) $claims->iat
            || ! is_string($claims->nonce ?? null)
            || ! hash_equals($expectedNonce, $claims->nonce)) {
            throw new RuntimeException('Telegram ID token claims are invalid.');
        }

        $audience = $claims->aud ?? null;

        if (is_string($audience)) {
            $audiences = [$audience];
        } elseif (is_array($audience)) {
            $audiences = $audience;
        } else {
            throw new RuntimeException('Telegram ID token audience is invalid.');
        }

        foreach ($audiences as $item) {
            if (! is_string($item)) {
                throw new RuntimeException('Telegram ID token audience is invalid.');
            }
        }

        if (! in_array($clientId, $audiences, true)
            || (count($audiences) > 1 && ($claims->azp ?? null) !== $clientId)
            || (isset($claims->azp) && $claims->azp !== $clientId)) {
            throw new RuntimeException('Telegram ID token was issued for another client.');
        }
    }

    private function normalizeTelegramId(mixed $telegramId): string
    {
        if (is_int($telegramId) && $telegramId > 0) {
            return (string) $telegramId;
        }

        if (! is_string($telegramId) || strlen($telegramId) > 20 || preg_match('/^[1-9][0-9]*$/D', $telegramId) !== 1) {
            throw new RuntimeException('Telegram account identifier is invalid.');
        }

        return $telegramId;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
