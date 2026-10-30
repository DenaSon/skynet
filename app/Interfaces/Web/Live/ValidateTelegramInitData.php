<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use JsonException;

/** Verify only raw Telegram Mini App initData signed by the SALES bot. */
final class ValidateTelegramInitData
{
    public function userId(string $raw, string $botToken): ?int
    {
        if ($raw === '' || strlen($raw) > 8192 || $botToken === '') {
            return null;
        }

        $values = [];
        foreach (explode('&', $raw) as $field) {
            if (! str_contains($field, '=')) {
                return null;
            }

            [$encodedKey, $encodedValue] = explode('=', $field, 2);
            $key = urldecode($encodedKey);
            if (preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/D', $key) !== 1 || array_key_exists($key, $values)) {
                return null;
            }

            $values[$key] = urldecode($encodedValue);
        }

        $hash = $values['hash'] ?? null;
        $authDate = $values['auth_date'] ?? null;
        if (! is_string($hash) || preg_match('/\A[a-f0-9]{64}\z/D', $hash) !== 1
            || ! is_string($authDate) || preg_match('/\A[0-9]{1,12}\z/D', $authDate) !== 1) {
            return null;
        }

        $issuedAt = (int) $authDate;
        $age = time() - $issuedAt;
        if ($age < -30 || $age > (int) config('denagate_live.init_data_max_age', 300)) {
            return null;
        }

        unset($values['hash'], $values['signature']);
        ksort($values, SORT_STRING);
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $expected = hash_hmac('sha256', implode("\n", $pairs), $secret);
        if (! hash_equals($expected, $hash)) {
            return null;
        }

        try {
            $user = json_decode($values['user'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $id = is_array($user) ? ($user['id'] ?? null) : null;

        return is_int($id) && $id > 0 ? $id : null;
    }
}
