<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Jobs;

use RuntimeException;

/** Provider writes must run on a worker, never inline or on a dropping queue. */
final class ProviderQueueConnection
{
    public static function name(): string
    {
        $connection = config('queue.default');

        if (is_string($connection)
            && in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis'], true)) {
            return $connection;
        }

        // Preserve the existing asynchronous database behavior for sync, null,
        // deferred/background, failover or unsupported defaults. No inline POST.
        if (config('queue.connections.database.driver') === 'database') {
            return 'database';
        }

        throw new RuntimeException('Provider jobs require an asynchronous database or Redis queue.');
    }
}
