<?php

namespace App\Modules\Identity\Presentation\Console;

use App\Modules\Identity\Application\RecordIdentityAudit;
use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PromoteSuperAdmin extends Command
{
    protected $signature = 'identity:super-admin:promote {user : Existing internal user ID}';

    protected $description = 'Enroll an existing Telegram-linked allowlisted user as a Super Admin';

    public function handle(SuperAdminTrust $trust): int
    {
        $id = (string) $this->argument('user');
        $allowed = $trust->allowedTelegramIds();

        if (! ctype_digit($id) || (int) $id < 1 || count($allowed) < 2) {
            $this->error('A valid user ID and at least TWO unique SUPER_ADMIN_TELEGRAM_IDS are required.');

            return self::FAILURE;
        }

        $user = User::query()->with('telegramAccount')->find((int) $id);

        if ($user === null || ! $user->is_active
            || $user->telegramAccount === null
            || ! in_array((string) $user->telegramAccount->telegram_user_id, $allowed, true)) {
            $this->error('The user must be active and linked to an allowlisted Telegram ID.');

            return self::FAILURE;
        }

        if (! $this->confirm("Promote user {$user->id} to Super Admin (replaces existing roles)?", false)) {
            $this->warn('No changes made.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($user): void {
            $user->syncRoles(['super-admin']);
            app(RecordIdentityAudit::class)->handle(null, 'identity.super_admin.enrolled', 'user', (int) $user->getKey());
        });
        $this->info('Trusted Super Admin enrollment completed.');

        return self::SUCCESS;
    }
}
