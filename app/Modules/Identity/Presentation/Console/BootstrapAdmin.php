<?php

namespace App\Modules\Identity\Presentation\Console;

use App\Modules\Identity\Application\CreateUser;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class BootstrapAdmin extends Command
{
    protected $signature = 'identity:bootstrap-admin';

    protected $description = 'Interactively create the first ParsaGate administrator';

    public function handle(CreateUser $createUser): int
    {
        if (User::query()->role(['admin', 'super-admin'])->exists()) {
            $this->error('An administrator already exists. Manage users through an authenticated admin session.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $email = mb_strtolower(trim((string) $this->ask('Email')));
        $password = (string) $this->secret('Password (at least 12 characters)');

        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($createUser, $name, $email, $password): void {
            $admin = $createUser->handle($name, $email, $password);
            $admin->syncRoles(['admin']);
        });

        $this->info('Administrator created. Sign in at /login.');

        return self::SUCCESS;
    }
}
