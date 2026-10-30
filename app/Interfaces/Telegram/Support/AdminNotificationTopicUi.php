<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Optional, private Admin-only notification topic pilot; no business-state changes. */
final readonly class AdminNotificationTopicUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $actors,
    ) {}

    public static function eligible(TelegramActor $actor): bool
    {
        return $actor->canManageRoles() || $actor->allows('purchases.review')
            || $actor->allows('topups.review') || $actor->allows('resellers.review');
    }

    public function show(Nutgram $bot): void
    {
        $actor = $this->authorized($bot);
        if ($actor === null) {
            return;
        }

        $contact = DB::table('telegram_bot_contacts')->where('bot_key', 'admin')
            ->where('user_id', $actor->userId)->where('chat_id', $this->privateUser->id($bot))
            ->first(['admin_notification_thread_id']);
        $thread = $contact?->admin_notification_thread_id;

        if ($thread !== null && (int) $thread > 1) {
            $this->render($bot, "✅ اعلان‌های مدیریت برای شما فعال است.\n\nاعلان‌های مجاز در اتاق «🔔 اعلان‌های مدیریت» نمایش داده می‌شوند.");

            return;
        }

        if ($thread !== null) {
            $this->render($bot, "⚠️ وضعیت ساخت اتاق اعلان‌ها نیازمند بررسی است.\nبرای جلوگیری از ساخت اتاق تکراری، لطفاً با مدیر اصلی هماهنگ کنید.");

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔔 فعال‌سازی اعلان‌های من', callback_data: 'admin:notice-topic:enable'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'menu:admin'));
        $this->render($bot, "🔔 اعلان‌های مدیریت\n\nیک اتاق خصوصی برای اعلان‌های مجاز شما ساخته می‌شود. این قابلیت اختیاری است.", $keyboard);
    }

    public function enable(Nutgram $bot): void
    {
        $actor = $this->authorized($bot);
        if ($actor === null) {
            return;
        }
        $chat = $this->privateUser->id($bot);
        if ($chat === null) {
            return;
        }

        // A first-ever callback may arrive before contact bookkeeping finishes.
        DB::table('telegram_bot_contacts')->insertOrIgnore([
            'user_id' => $actor->userId, 'bot_key' => 'admin', 'chat_id' => $chat,
            'delivery_status' => 'reachable', 'last_seen_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A persistent one-time reservation also prevents duplicate topic creation
        // when Telegram accepts a request but its response times out.
        $claimed = DB::table('telegram_bot_contacts')->where('bot_key', 'admin')
            ->where('user_id', $actor->userId)->where('chat_id', $chat)
            ->whereNull('admin_notification_thread_id')
            ->update(['admin_notification_thread_id' => 0, 'updated_at' => now()]);

        if ($claimed !== 1) {
            $this->show($bot);

            return;
        }

        try {
            $token = (string) config('nutgram.token');
            if ($token === '') {
                throw new \RuntimeException('Admin Bot is not configured.');
            }

            $response = Http::timeout(10)->asForm()->post(
                'https://api.telegram.org/bot'.$token.'/createForumTopic',
                ['chat_id' => $chat, 'name' => '🔔 اعلان‌های مدیریت'],
            );
            $threadId = $response->json('result.message_thread_id');
            if (! $response->successful() || $response->json('ok') !== true
                || ! is_int($threadId) || $threadId <= 1) {
                throw new \RuntimeException('Topic creation not confirmed.');
            }

            DB::table('telegram_bot_contacts')->where('bot_key', 'admin')
                ->where('user_id', $actor->userId)->where('chat_id', $chat)
                ->where('admin_notification_thread_id', 0)
                ->update(['admin_notification_thread_id' => $threadId, 'updated_at' => now()]);
        } catch (Throwable) {
            // Keep the uncertain reservation: a blind retry may create duplicate topics.
            $this->render($bot, "⚠️ ساخت اتاق اعلان‌ها تأیید نشد.\nبرای بررسی و جلوگیری از ایجاد اتاق تکراری، با مدیر اصلی هماهنگ کنید.");

            return;
        }

        $this->render($bot, "✅ اتاق «🔔 اعلان‌های مدیریت» ساخته شد.\n\nاز این پس اعلان‌های مجاز در اتاق اختصاصی شما نمایش داده می‌شوند.");
    }

    private function authorized(Nutgram $bot): ?TelegramActor
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if (config('telegram_notifications.admin_topic.enabled') !== true
            || ! Schema::hasColumn('telegram_bot_contacts', 'admin_notification_thread_id')
            || $actor === null || ! self::eligible($actor)) {
            $this->render($bot, 'این بخش برای حساب شما فعال نیست.');

            return null;
        }

        return $actor;
    }

    private function render(Nutgram $bot, string $message, ?InlineKeyboardMarkup $keyboard = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
