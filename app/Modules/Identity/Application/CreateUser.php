<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\UserRole;
use App\Modules\Identity\Infrastructure\Models\User;

final class CreateUser
{
    public function handle(string $name, string $email, string $password, UserRole $role = UserRole::Member): User
    {
        $user = new User([
            'name' => $name,
            'email' => mb_strtolower(trim($email)),
            'password' => $password,
        ]);

        $user->forceFill([
            'role' => $role,
            'is_active' => true,
        ])->save();

        return $user;
    }
}
