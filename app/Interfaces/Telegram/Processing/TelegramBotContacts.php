<?php

namespace App\Interfaces\Telegram\Processing;

use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        // Topic IDs belong to one private chat. Never reuse one after relinking.
        if ($context->key === TelegramBotContext::ADMIN
            && Schema::hasColumn('telegram_bot_contacts', 'admin_notification_thread_id')) {
            DB::table('telegram_bot_contacts')->where('user_id', $actor->userId)
                ->where('bot_key', 'admin')->where('chat_id', '!=', $chatId)
                ->update(['admin_notification_thread_id' => null]);
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

        $updateId = $request->input('update_id');
        // Only processed private Sales Bot updates for commercial accounts.
        // No content, callback data, phone, chat ID or external provider data.
        if ($context->key !== TelegramBotContext::SALES
            || $actor->isAdmin()
            || array_intersect($actor->roles, ['admin', 'super-admin']) !== []
            || array_intersect($actor->roles, ['member', 'reseller']) === []
            || ! is_int($updateId) || $updateId < 0
            || ! Schema::hasTable('sales_bot_interactions')) {
            return;
        }

        // The unique event ID also prevents recounting after webhook receipt pruning.
        // A retry must not overwrite the first observation or its account.
        DB::table('sales_bot_interactions')->upsert([
            [
                'user_id' => $actor->userId,
                'update_id' => $updateId,
                'kind' => is_array($request->input('callback_query')) ? 'callback' : 'message',
                'recorded_at' => now(),
            ],
        ], ['update_id'], ['update_id']);
    }
}
