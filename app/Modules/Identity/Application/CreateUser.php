<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateUser
{
    public function handle(string $name, string $email, string $password, string $role = 'member', ?User $actor = null): User
    {
        if ($role === 'super-admin' || ($role !== 'member' && ($actor === null || ! app(SuperAdminTrust::class)->eligible($actor)))) {
            throw ValidationException::withMessages(['role' => 'A trusted Super Admin is required for privileged user creation; root enrollment is CLI only.']);
        }

        return DB::transaction(function () use ($name, $email, $password, $role, $actor): User {
            $user = new User([
                'name' => $name,
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
            ]);

            $user->forceFill(['is_active' => true])->save();
            $user->assignRole($role);
            app(RecordIdentityAudit::class)->handle($actor, 'identity.user.created', 'user', (int) $user->getKey(), [
                'role' => $role,
            ]);

            return $user;
        });
    }
}
