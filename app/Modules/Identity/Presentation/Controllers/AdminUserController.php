<?php

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Identity\Application\CreateUser;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\UpdateUserAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Presentation\Requests\CreateUserRequest;
use App\Modules\Identity\Presentation\Requests\UpdateUserAccessRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

final class AdminUserController
{
    public function index(): JsonResponse
    {
        Gate::authorize('identity.manage-users');

        $users = User::query()->orderBy('id')->paginate(20);

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user): array => $this->present($user))->all(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function store(CreateUserRequest $request, CreateUser $createUser): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $actor */
        $actor = $request->user();
        $role = $validated['role'] ?? 'member';

        if ($role === 'super-admin' || ($role !== 'member' && ! app(SuperAdminTrust::class)->eligible($actor))) {
            abort(403, 'Only a trusted Super Admin can create privileged users.');
        }

        $user = $createUser->handle(
            $validated['name'],
            $validated['email'],
            $validated['password'],
            $validated['role'] ?? 'member',
        );

        return response()->json(['data' => $this->present($user)], 201);
    }

    public function update(UpdateUserAccessRequest $request, User $user, UpdateUserAccess $update): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $validated = $request->validated();

        $updated = $update->handle(
            $actor,
            $user,
            array_key_exists('roles', $validated) ? $validated['roles'] : (isset($validated['role']) ? [$validated['role']] : null),
            array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null,
        );

        return response()->json(['data' => $this->present($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->getRoleNames()->first(),
            'roles' => $user->getRoleNames()->all(),
            'is_active' => $user->is_active,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
