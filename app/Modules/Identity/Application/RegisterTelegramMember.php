<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegisterTelegramMember
{
    /**
     * Call within a transaction. Public Telegram enrollment has no elevated-role option.
     *
     * @return array{user_id:int,created:bool}
     */
    public function handle(int $telegramId, string $firstName): array
    {
        if ($telegramId < 1) {
            throw ValidationException::withMessages(['telegram' => 'Invalid Telegram identity.']);
        }

        $existing = TelegramAccount::query()->where('telegram_user_id', $telegramId)->first();

        if ($existing !== null) {
            return ['user_id' => $existing->user_id, 'created' => false];
        }

        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $firstName));
        $name = mb_substr($name === '' ? 'Telegram member' : $name, 0, 120);

        // Never put the Telegram ID into an admin-visible email field.
        // This randomized non-routable placeholder is NOT verified contact information.
        $user = new User([
            'name' => $name,
            'email' => 'telegram-'.bin2hex(random_bytes(16)).'@accounts.skynet.invalid',
            'password' => Str::random(80),
        ]);
        $user->forceFill(['is_active' => true])->save();
        $user->assignRole('member');

        TelegramAccount::query()->create([
            'user_id' => $user->getKey(),
            'telegram_user_id' => $telegramId,
        ]);

        app(RecordIdentityAudit::class)->handle(null, 'identity.telegram.member_registered', 'user', (int) $user->getKey());

        return ['user_id' => (int) $user->getKey(), 'created' => true];
    }
}
