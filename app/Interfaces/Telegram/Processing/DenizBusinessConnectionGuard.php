<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

use App\Settings\DenizBusinessSupportSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recheck the currently linked account and reply right with Telegram itself.
 * Cached business_connection updates are hints, never authority to send.
 */
final class DenizBusinessConnectionGuard
{
    /** @phpstan-impure */
    public function enabled(): bool
    {
        if (config('telegram.denix.enabled') !== true
            || config('denagate_ai.enabled') !== true
            || config('denagate_ai.business_replies_enabled') !== true
            || $this->ownerId() === null
            || $this->token() === null) {
            return false;
        }

        try {
            return DenizBusinessSupportSettings::isEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /** @phpstan-impure */
    public function allowed(string $connectionId): bool
    {
        if (! $this->enabled() || $connectionId === '' || strlen($connectionId) > 256) {
            return false;
        }

        try {
            $response = Http::timeout(4)->asForm()->post(
                'https://api.telegram.org/bot'.$this->token().'/getBusinessConnection',
                ['business_connection_id' => $connectionId],
            );

            $details = $response->json('result');

            if (! $response->successful() || $response->json('ok') !== true || ! is_array($details)) {
                Log::warning('Deniz Business connection check rejected.', [
                    'http_status' => $response->status(),
                ]);

                return false;
            }

            return ($details['id'] ?? null) === $connectionId
                && data_get($details, 'user.id') === $this->ownerId()
                && data_get($details, 'user.is_bot') === false
                && ($details['is_enabled'] ?? null) === true
                && data_get($details, 'rights.can_reply') === true;
        } catch (Throwable $error) {
            // Never record token-bearing URLs, raw Telegram bodies or user data.
            Log::warning('Deniz Business connection check failed.', [
                'exception_type' => $error::class,
            ]);

            return false;
        }
    }

    public function ownerId(): ?int
    {
        $id = filter_var(config('telegram.denix.support_business_user_id'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return is_int($id) ? $id : null;
    }

    private function token(): ?string
    {
        $token = config('telegram.denix.token');

        return is_string($token) && $token !== ''
            && $token !== config('nutgram.token')
            && $token !== config('telegram.sales.token')
            ? $token : null;
    }
}
