<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\TelegramLinkToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IssueTelegramLinkCode
{
    public function handle(int $userId): string
    {
        return DB::transaction(function () use ($userId): string {
            $user = User::query()->lockForUpdate()->find($userId);

            if ($user === null) {
                throw ValidationException::withMessages(['user' => 'User not found.']);
            }

            if (! $user->is_active || TelegramAccount::query()->where('user_id', $user->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'user' => 'The account is inactive or already linked to Telegram.',
                ]);
            }

            // Reissuing a code invalidates any previously unused code for this account.
            TelegramLinkToken::query()->where('user_id', $user->getKey())->delete();
            $code = bin2hex(random_bytes(24));

            TelegramLinkToken::query()->create([
                'user_id' => $user->getKey(),
                'token_hash' => hash('sha256', $code),
                'expires_at' => now()->addMinutes(10),
            ]);

            return $code;
        });
    }
}
