<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

final class RecordIdentityAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function handle(?User $actor, string $event, string $subjectType, int $subjectId, array $details = []): void
    {
        DB::table('identity_audit_events')->insert([
            'actor_user_id' => $actor?->getKey(),
            'event' => $event,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
