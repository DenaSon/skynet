<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Telegram\TelegramIdTokenVerifier;
use App\Modules\Identity\Infrastructure\Telegram\TelegramOidcClient;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Cache;

final readonly class TelegramOidcAuthentication
{
    private const TRANSACTION_KEY = 'identity.telegram_oidc_transaction';

    private const CONSUMED_STATE_PREFIX = 'identity:telegram-oidc:consumed:';

    public function __construct(
        private TelegramOidcClient $client,
        private TelegramIdTokenVerifier $verifier,
    ) {}

    public function begin(Session $session, string $redirectUri, ?int $userId = null): TelegramOidcAuthorization
    {
        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(32));
        $codeVerifier = $this->base64UrlEncode(random_bytes(32));
        $codeChallenge = $this->base64UrlEncode(hash('sha256', $codeVerifier, true));

        $authorizationUrl = $this->client->authorizationUrl($redirectUri, $state, $nonce, $codeChallenge);

        $session->put(self::TRANSACTION_KEY, [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $codeVerifier,
            'redirect_uri' => $redirectUri,
            'user_id' => $userId,
            'created_at' => now()->timestamp,
        ]);

        return new TelegramOidcAuthorization($authorizationUrl);
    }

    public function complete(
        Session $session,
        ?int $userId,
        ?string $state,
        ?string $code,
        string $redirectUri,
    ): ?VerifiedTelegramIdentity {
        $transaction = $session->get(self::TRANSACTION_KEY);

        if (! is_array($transaction)
            || ! is_string($state)
            || ! is_string($transaction['state'] ?? null)
            || ! hash_equals($transaction['state'], $state)
            || ! array_key_exists('user_id', $transaction)
            || ($transaction['user_id'] ?? null) !== $userId
            || ($transaction['redirect_uri'] ?? null) !== $redirectUri
            || ! is_int($transaction['created_at'] ?? null)
            || $transaction['created_at'] > now()->timestamp
            || $transaction['created_at'] < now()->subMinutes(10)->timestamp
            || ! is_string($code)
            || $code === ''
            || strlen($code) > 4096
            || ! is_string($transaction['code_verifier'] ?? null)
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $transaction['code_verifier']) !== 1
            || ! is_string($transaction['nonce'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $transaction['nonce']) !== 1) {
            return null;
        }

        $consumed = Cache::add(
            self::CONSUMED_STATE_PREFIX.hash('sha256', $state),
            true,
            now()->addMinutes(10),
        );

        if (! $consumed) {
            return null;
        }

        $session->forget(self::TRANSACTION_KEY);

        $idToken = $this->client->exchangeCode(
            $code,
            $redirectUri,
            $transaction['code_verifier'],
        );

        return $this->verifier->verify($idToken, $transaction['nonce']);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
