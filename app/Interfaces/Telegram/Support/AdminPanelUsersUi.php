<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ListAdminPanelUsers;
use App\Modules\Provisioning\Application\ReadAdminPanelUser;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class AdminPanelUsersUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ListAdminPanelUsers $list,
        private ReadAdminPanelUser $detailRead,
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
                    default => 'نامشخص',
                };
                $message .= "\n{$user['username']} — {$status}";
                $keyboard->addRow(InlineKeyboardButton::make(
                    '👤 '.$user['username'],
                    callback_data: "rw:users:view:{$user['id']}:{$page->page}",
                ));
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

    public function detail(Nutgram $bot, string $requestedId, string $requestedPage = '1'): void
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

        $pageValid = ctype_digit($requestedPage) && strlen($requestedPage) <= 4
            && (int) $requestedPage >= 1 && (int) $requestedPage <= 1000;
        $id = filter_var($requestedId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! $pageValid || $id === false) {
            $this->render($bot, "👤 جزئیات کاربر\n\nدرخواست نامعتبر است.", $this->backKeyboard());

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی', callback_data: "rw:users:view:{$id}:{$requestedPage}"))
            ->addRow(InlineKeyboardButton::make('◀️ کاربران پنل', callback_data: 'rw:users:page:'.$requestedPage))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $result = $this->detailRead->handle($actor->userId, (int) $id);

        if ($result->state !== 'available') {
            $message = "👤 جزئیات کاربر\n\nدریافت اطلاعات ممکن نیست. کمی بعد دوباره تلاش کنید.";
        } else {
            $status = match ($result->status) {
                'ACTIVE' => '🟢 فعال',
                'DISABLED' => '🔴 غیرفعال',
                'LIMITED' => '🟠 محدود',
                'EXPIRED' => '⚪ منقضی',
                default => 'نامشخص',
            };
            $quota = $result->trafficLimitBytes === 0
                ? 'نامحدود'
                : number_format($result->trafficLimitBytes / 1073741824, 2, '.', '').' GiB';
            $used = number_format($result->usedTrafficBytes / 1073741824, 2, '.', '').' GiB';
            $verified = $result->subscriptionMatched ? 'تأیید شد' : 'یافت نشد';
            $message = "👤 جزئیات کاربر پنل\n\n"
                ."نام کاربری: {$result->username}\n"
                ."وضعیت: {$status}\n"
                ."حجم اختصاصی: {$quota}\n"
                ."مصرف‌شده: {$used}\n"
                ."انقضا: {$result->expiresAt}\n"
                ."تطبیق اشتراک: {$verified}";
        }

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
