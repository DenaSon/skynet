<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\UserDirectoryEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Resolve a current public username through a bot the customer already started. */
final class TelegramUserContactLink
{
    public function for(UserDirectoryEntry $user): ?string
    {
        $username = $this->username($user->id, $user->telegramUserId);

        return $username === null ? null : 'https://t.me/'.$username;
    }

    /** Best-effort, verified public Telegram username; never infer it from display names. */
    public function username(int $userId, ?int $telegramUserId): ?string
    {
        if ($userId < 1 || $telegramUserId === null || $telegramUserId <= 0) {
            return null;
        }

        // Do not guess usernames from the account display name or use numeric
        // tg:// links in Admin inline keyboards (Telegram rejects such markup).
        foreach (['sales' => config('telegram.sales.token'), 'admin' => config('nutgram.token')] as $botKey => $token) {
            if (! is_string($token) || $token === '') {
                continue;
            }

            $contact = DB::table('telegram_bot_contacts')
                ->where('user_id', $userId)
                ->where('bot_key', $botKey)
                ->where('chat_id', $telegramUserId)
                ->where('delivery_status', 'reachable')
                ->exists();

            if (! $contact) {
                continue;
            }

            try {
                $response = Http::connectTimeout(2)->timeout(3)->get(
                    'https://api.telegram.org/bot'.$token.'/getChat',
                    ['chat_id' => $telegramUserId],
                );

                if (! $response->successful() || $response->json('ok') !== true
                    || (string) $response->json('result.id') !== (string) $telegramUserId) {
                    continue;
                }

                $username = $response->json('result.username');
                if (is_string($username)
                    && preg_match('/\A[A-Za-z0-9_]{5,32}\z/D', $username) === 1) {
                    return $username;
                }
            } catch (Throwable) {
                // Profile navigation is optional; never break the Admin user page.
            }
        }

        return null;
    }
}
