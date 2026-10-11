<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChangeUserStatus
{
    public function handle(int $actorId, int $userId, bool $expectedActive, bool $desiredActive): UserDirectoryEntry
    {
        return DB::transaction(function () use ($actorId, $userId, $expectedActive, $desiredActive): UserDirectoryEntry {
            $actor = User::query()->findOrFail($actorId);
            $target = User::query()->lockForUpdate()->findOrFail($userId);

            // Reject stale/replayed operations even when an inline button is still visible.
            if ($expectedActive === $desiredActive || $target->is_active !== $expectedActive) {
                throw ValidationException::withMessages(['status' => 'Account status has changed. Refresh the user profile.']);
            }

            app(UpdateUserAccess::class)->handle($actor, $target, null, $desiredActive);

            $entry = app(BrowseUsers::class)->find($actorId, $userId);

            if ($entry === null) {
                throw ValidationException::withMessages(['user' => 'User does not exist.']);
            }

            return $entry;
        });
    }
}
