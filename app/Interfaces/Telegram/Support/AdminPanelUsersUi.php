<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ListAdminPanelUsers;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class AdminPanelUsersUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ListAdminPanelUsers $list,
    ) {}

    public function browse(Nutgram $bot, string $requestedPage = '1'): void
    {
        $telegramId = $this->private->id($bot);

        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            if ($bot->isCallbackQuery()) {
                TelegramUi::answerCallback($bot, 'دسترسی مجاز نیست.');
            } else {
                $bot->sendMessage(text: 'دسترسی مجاز نیست.');
            }

            return;
        }

        if (! ctype_digit($requestedPage) || strlen($requestedPage) > 4
            || (int) $requestedPage < 1 || (int) $requestedPage > 1000) {
            $this->render($bot, '👥 کاربران پنل'."\n\n".'صفحه نامعتبر است.', $this->backKeyboard());

            return;
        }

        $page = $this->list->handle($actor->userId, (int) $requestedPage);
        $keyboard = InlineKeyboardMarkup::make();

        if ($page->state !== 'available') {
            $message = "👥 کاربران پنل\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.";
        } else {
            $message = "👥 کاربران پنل\n\nتعداد: {$page->total} | صفحه {$page->page}\n";
            foreach ($page->users as $user) {
                $status = match ($user['status']) {
                    'ACTIVE' => '🟢 فعال',
                    'DISABLED' => '🔴 غیرفعال',
                    'LIMITED' => '🟠 محدود',
                    'EXPIRED' => '⚪ منقضی',
                };
                $message .= "\n{$user['username']} — {$status}";
            }
            if ($page->users === []) {
                $message .= "\nکاربری در این صفحه وجود ندارد.";
            }

            $navigation = [];
            if ($page->page > 1) {
                $navigation[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'rw:users:page:'.($page->page - 1));
            }
            if ($page->hasNext) {
                $navigation[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'rw:users:page:'.($page->page + 1));
            }
            if ($navigation !== []) {
                $keyboard->addRow(...$navigation);
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی', callback_data: 'rw:users:page:'.$requestedPage));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, $message, $keyboard);
    }

    private function backKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
