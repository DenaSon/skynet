<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use RuntimeException;

/**
 * Constructs the server-side client from trusted Laravel configuration.
 * Missing configuration fails closed; never configure it from bot input.
 */
final class RemnawaveApiClientFactory
{
    public function make(): RemnawaveApiClient
    {
        $url = config('services.remnawave.url');
        $token = config('services.remnawave.token');
        $timeout = config('services.remnawave.timeout', 15);

        if (! is_string($url) || trim($url) === ''
            || ! is_string($token) || trim($token) === '') {
            throw new RuntimeException('Remnawave API is not configured.');
        }

        if (! is_int($timeout) || $timeout < 1 || $timeout > 120) {
            throw new RuntimeException('Invalid Remnawave API timeout.');
        }

        return new RemnawaveApiClient($url, $token, $timeout);
    }
}
