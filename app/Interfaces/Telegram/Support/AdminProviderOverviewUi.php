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

        $actor = $this->actors->handle($telegramId);

        if ($actor?->canManageRoles() !== true) {
            $bot->sendMessage(text: 'دسترسی مجاز نیست.');

            return;
        }

        $overview = $this->read->handle($actor->userId);

        $message = match ($overview->state) {
            'available' => $this->details($overview),
            'error' => "🖥️ وضعیت سرورها\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.",
            default => "🖥️ وضعیت سرورها\n\nاطلاعات سرورها هنوز آماده نمایش نیست.",
        };

        $bot->sendMessage(
            text: $message,
            reply_markup: InlineKeyboardMarkup::make()->addRow(
                InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'),
            ),
        );
    }

    private function details(AdminProviderOverview $result): string
    {
        return "🖥️ وضعیت سرورها\n\n"
            ."سرورهای متصل: {$result->connectedNodes} از {$result->nodes}\n"
            ."میزبان‌ها: {$result->hosts}\n"
            ."گروه‌ها: {$result->internalSquads}\n"
            ."پروفایل‌ها: {$result->configProfiles}\n"
            ."نمونه‌های فعال: {$result->instances}\n"
            ."درخواست‌های نیازمند بررسی: {$result->needsReview}";
    }
}
