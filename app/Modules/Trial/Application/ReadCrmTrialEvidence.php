<?php

declare(strict_types=1);

namespace App\Modules\Trial\Application;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\DB;

final readonly class ReadCrmTrialEvidence
{
    public function __construct(private AssertCatalogManagementAccess $access) {}

    /** @return array{claimed:bool,activatedAt:?string,endedAt:?string} */
    public function handle(int $actorId, int $subjectId): array
    {
        $this->access->handle($actorId);
        $row = DB::table('trial_claims')->where('user_id', $subjectId)->first(['activated_at', 'ended_at']);

        return [
            'claimed' => $row !== null,
            'activatedAt' => isset($row->activated_at) ? (string) $row->activated_at : null,
            'endedAt' => isset($row->ended_at) ? (string) $row->ended_at : null,
        ];
    }
}
