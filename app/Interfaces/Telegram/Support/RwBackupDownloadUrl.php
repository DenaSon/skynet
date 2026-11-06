<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use RuntimeException;

/**
 * Generate the one-time download URL from the configured public HTTPS origin.
 *
 * The Telegram webhook can arrive over HTTP at PHP-FPM behind Runflare's TLS
 * terminator. Do not infer the externally visible URL from that request.
 */
final class RwBackupDownloadUrl
{
    public static function forToken(string $token): string
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new RuntimeException('Invalid backup download token.');
        }

        $origin = rtrim((string) config('app.url'), '/');
        $parts = parse_url($origin);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Backup download requires a canonical HTTPS APP_URL.');
        }

        return $origin.route('rw-backup.download', ['token' => $token], false);
    }
}
