<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

use Illuminate\Support\Facades\DB;

final class PruneFilteringHistory
{
    private const BATCH_SIZE = 200;

    private const MAX_BATCHES = 10;

    /** @return array{checks:int,incidents:int} */
    public function run(): array
    {
        $cutoff = now()->subDay();

        return [
            'checks' => $this->prune('filtering_checks', 'observed_at', $cutoff),
            'incidents' => $this->prune('filtering_incidents', 'resolved_at', $cutoff),
        ];
    }

    private function prune(string $table, string $column, \DateTimeInterface $cutoff): int
    {
        $deleted = 0;
        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = DB::table($table)->where($column, '<', $cutoff)
                ->orderBy($column)->orderBy('id')->limit(self::BATCH_SIZE)->pluck('id')->all();
            if ($ids === []) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)
                ->where($column, '<', $cutoff)->delete();

            if (count($ids) < self::BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }
}
