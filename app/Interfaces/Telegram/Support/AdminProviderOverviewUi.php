<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\AdminProviderOverview;
use App\Modules\Provisioning\Application\ReadAdminProviderOverview;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class AdminProviderOverviewUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminProviderOverview $read,
    ) {}

    public function show(Nutgram $bot): void
    {
        $telegramId = $this->private->id($bot);

        if ($telegramId === null) {
            return;
        }

        // Refresh and old callbacks must recheck current role and account status.
        $actor = $this->actors->handle($telegramId);

        if ($actor?->canManageRoles() !== true) {
            if ($bot->isCallbackQuery()) {
                TelegramUi::answerCallback($bot, 'دسترسی مجاز نیست.');

                return;
            }

            $bot->sendMessage(text: 'دسترسی مجاز نیست.');

            return;
        }

        // This shared Application Action independently reauthorizes the root.
        $overview = $this->read->handle($actor->userId);

        $message = match ($overview->state) {
            'available' => $this->details($overview),
            'error' => "🖥️ وضعیت سرورها\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.",
            default => "🖥️ وضعیت سرورها\n\nاطلاعات سرورها هنوز آماده نمایش نیست.",
        };

        $keyboard = InlineKeyboardMarkup::make();

        if (config('services.remnawave.admin_reads_verified', false) === true) {
            $keyboard->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی', callback_data: 'servers:refresh'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }

    private function details(AdminProviderOverview $result): string
    {
        return "🖥️ وضعیت سرورها\n\n"
            ."سرورهای متصل: {$result->connectedNodes} از {$result->nodes}\n"
            ."میزبان‌ها: {$result->hosts}\n"
            ."گروه‌ها: {$result->internalSquads}\n"
            ."پروفایل‌ها: {$result->configProfiles}\n"
            ."نمونه‌های فعال پنل: {$result->instances}\n"
            ."درخواست‌های داخلی نیازمند بررسی: {$result->needsReview}"
            .($result->nodes === 0 ? "\n\nهنوز سروری تعریف نشده است." : '');
    }
}
