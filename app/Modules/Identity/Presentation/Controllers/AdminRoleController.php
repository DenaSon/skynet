<?php

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Identity\Application\CreateRole;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

final class AdminRoleController
{
    public function index(): JsonResponse
    {
        Gate::authorize('identity.manage-roles');

        return response()->json(['data' => Role::query()->where('guard_name', 'web')
            ->orderBy('name')->get()->map(fn (Role $role): array => [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ])->all()]);
    }

    public function store(Request $request, CreateRole $create): JsonResponse
    {
        Gate::authorize('identity.manage-roles');

        $values = $request->validate([
            'name' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{2,49}$/D', Rule::unique('roles', 'name')->where('guard_name', 'web')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $role = $create->handle($actor, $values['name'], $values['permissions'] ?? []);

        return response()->json(['data' => [
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->all(),
        ]], 201);
    }
}
