<?php

namespace App\Interfaces\Telegram\Processing;

use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TelegramBotContacts
{
    public function __construct(private readonly ResolveTelegramActor $actors) {}

    public function record(Request $request, string $botKey): void
    {
        $context = new TelegramBotContext($botKey);
        $message = $request->input('message');

        if (! is_array($message)) {
            $message = $request->input('callback_query.message');
        }

        $from = $request->input('callback_query.from.id', $request->input('message.from.id'));
        $chatId = data_get($message, 'chat.id');
        $chatType = data_get($message, 'chat.type');

        // Never register groups, forged chat IDs, or unlinked/inactive identities.
        if (! is_int($from) || $from <= 0 || ! is_int($chatId) || $chatId !== $from
            || $chatType !== 'private') {
            return;
        }

        $actor = $this->actors->handle($from);

        if ($actor === null) {
            return;
        }

        DB::table('telegram_bot_contacts')->upsert([
            [
                'user_id' => $actor->userId,
                'bot_key' => $context->key,
                'chat_id' => $chatId,
                'delivery_status' => 'reachable',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['bot_key', 'user_id'], ['chat_id', 'delivery_status', 'last_seen_at', 'updated_at']);
    }
}
