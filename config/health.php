<?php

declare(strict_types=1);

use Spatie\Health\Models\HealthCheckResultHistoryItem;
use Spatie\Health\ResultStores\EloquentHealthResultStore;

return [
    // Retained health history is read-only input for a FUTURE, root-only Telegram UI.
    'result_stores' => [
        EloquentHealthResultStore::class => [
            'model' => HealthCheckResultHistoryItem::class,
            'keep_history_for_days' => 7,
        ],
    ],

    // This phase must NOT introduce a second notification pipeline beside
    // DenaGate's existing durable Telegram notification outbox.
    'notifications' => [
        'enabled' => false,
    ],
];
