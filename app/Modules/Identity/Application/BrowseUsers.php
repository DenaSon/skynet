<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class BrowseUsers
{
    public function page(int $actorId, ?string $search = null, int $page = 1): UserDirectoryPage
    {
        $actor = $this->authorize($actorId);
        $search = trim((string) $search);

        if (mb_strlen($search) > 64) {
            throw ValidationException::withMessages(['search' => 'Search is limited to 64 characters.']);
        }

        $query = User::query()->with(['roles', 'telegramAccount'])->orderBy('id');

        if ($search !== '') {
            if (ctype_digit($search)) {
                $query->where('id', (int) $search);
            } else {
                $query->where(function ($q) use ($search): void {
                    $q->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            }
        }

        $paginator = $query->paginate(perPage: 6, page: max(1, min($page, 100000)));

        return new UserDirectoryPage(
            users: $paginator->getCollection()->map(fn (User $user): UserDirectoryEntry => $this->entry($actor, $user))->all(),
            page: $paginator->currentPage(),
            lastPage: $paginator->lastPage(),
            total: $paginator->total(),
        );
    }

    public function find(int $actorId, int $userId): ?UserDirectoryEntry
    {
        $actor = $this->authorize($actorId);
        $user = User::query()->with(['roles', 'telegramAccount'])->find($userId);

        return $user === null ? null : $this->entry($actor, $user);
    }

    private function authorize(int $actorId): User
    {
        $actor = User::query()->find($actorId);

        if ($actor === null || ! app(SuperAdminTrust::class)->canManageUsers($actor)) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    private function entry(User $actor, User $user): UserDirectoryEntry
    {
        $trust = app(SuperAdminTrust::class);
        $canChange = ! $user->hasRole('super-admin')
            && ! $actor->is($user)
            && ($trust->eligible($actor) || ! $user->hasRole('admin'));

        return new UserDirectoryEntry(
            id: (int) $user->getKey(),
            name: $user->name,
            email: $user->email,
            roles: $user->getRoleNames()->all(),
            active: $user->is_active,
            telegramLinked: $user->telegramAccount !== null,
            canChangeStatus: $canChange,
        );
    }
}
