<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\TelegramLinkToken;
use Illuminate\Support\Facades\DB;

final class LinkTelegramAccount
{
    public function handle(string $code, int $telegramUserId): bool
    {
        if ($telegramUserId <= 0 || ! preg_match('/^[a-f0-9]{48}$/D', $code)) {
            return false;
        }

        return DB::transaction(function () use ($code, $telegramUserId): bool {
            $token = TelegramLinkToken::query()
                ->where('token_hash', hash('sha256', $code))
                ->lockForUpdate()->first();

            if ($token === null || $token->expires_at->isPast()) {
                return false;
            }

            $user = $token->user()->lockForUpdate()->first();

            if ($user === null || ! $user->is_active
                || TelegramAccount::query()->where('user_id', $user->getKey())->exists()
                || TelegramAccount::query()->where('telegram_user_id', $telegramUserId)->exists()) {
                return false;
            }

            TelegramAccount::query()->create([
                'user_id' => $user->getKey(),
                'telegram_user_id' => $telegramUserId,
            ]);

            $token->delete();

            return true;
        });
    }
}
