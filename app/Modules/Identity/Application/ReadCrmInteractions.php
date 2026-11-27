<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Observed Sales Bot interactions, not complete lifetime activity or service use. */
final readonly class ReadCrmInteractions
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return array{lastSeenAt:?string,deliveryStatus:?string,firstObservedAt:?string,firstRecordedAt:?string,lastRecordedAt:?string,recordedCount:?int} */
    public function handle(int $actorId, int $subjectId): array
    {
        $this->access->handle($actorId);

        $contact = DB::table('telegram_bot_contacts')->where('bot_key', 'sales')
            ->where('user_id', $subjectId)->first(['last_seen_at', 'delivery_status']);
        $data = [
            'lastSeenAt' => $contact === null ? null : (string) $contact->last_seen_at,
            'deliveryStatus' => $contact === null ? null : (string) $contact->delivery_status,
            'firstObservedAt' => null,
            'firstRecordedAt' => null,
            'lastRecordedAt' => null,
            'recordedCount' => null,
        ];

        // Code-first rolling deploy: historical counts remain unknown.
        if (! Schema::hasTable('sales_bot_interactions')) {
            return $data;
        }

        $first = DB::table('sales_bot_interactions')->min('recorded_at');
        if ($first === null) {
            return $data;
        }

        $row = DB::table('sales_bot_interactions')->where('user_id', $subjectId)
            ->selectRaw('COUNT(*) as recorded_count, MIN(recorded_at) as first_recorded_at, MAX(recorded_at) as last_recorded_at')
            ->first();

        return [
            ...$data,
            'firstObservedAt' => (string) $first,
            'firstRecordedAt' => isset($row->first_recorded_at) ? (string) $row->first_recorded_at : null,
            'lastRecordedAt' => isset($row->last_recorded_at) ? (string) $row->last_recorded_at : null,
            'recordedCount' => (int) ($row->recorded_count ?? 0),
        ];
    }
}
