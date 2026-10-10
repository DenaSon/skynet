<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateUser
{
    public function handle(string $name, string $email, string $password, string $role = 'member'): User
    {
        return DB::transaction(function () use ($name, $email, $password, $role): User {
            $user = new User([
                'name' => $name,
                'email' => mb_strtolower(trim($email)),
                'password' => $password,
            ]);

            $user->forceFill(['is_active' => true])->save();
            $user->assignRole($role);

            return $user;
        });
    }
}
