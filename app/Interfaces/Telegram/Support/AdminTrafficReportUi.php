<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;
use App\Support\PersianCalendar;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminTrafficReport;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** MET-02: bounded, on-demand reports in the existing private Admin Bot. */
final readonly class AdminTrafficReportUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminTrafficReport $reports,
        private MenuRenderer $menus,
    ) {}

    public function summary(Nutgram $bot, string $days = '7', string $requestedPage = '1'): void
    {
        $actorId = $this->actor($bot);
        if ($actorId === null) {
            return;
        }

        if (! ctype_digit($requestedPage) || strlen($requestedPage) > 3
            || (int) $requestedPage < 1 || (int) $requestedPage > 100) {
            $this->render($bot, 'شماره صفحه معتبر نیست.', $this->back());

            return;
        }

        try {
            $report = $this->reports->summary($actorId, $days);
        } catch (ValidationException) {
            $this->render($bot, 'بازه گزارش معتبر نیست.', $this->back());

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('۷ روز', callback_data: 'metrics:range:7'),
                InlineKeyboardButton::make('۳۰ روز', callback_data: 'metrics:range:30'),
            );

        if ($report['state'] !== 'available') {
            $message = "📈 گزارش مصرف\n\nدریافت آمار این بازه ممکن نیست. کمی بعد تازه‌سازی کنید.";
        } else {
            $message = "📈 گزارش مصرف {$report['days']} روزه\n"
                .'از '.PersianCalendar::date($report['start']).' تا '.PersianCalendar::date($report['end'])." (روزهای UTC، تاریخ شمسی)\n\n"
                ."📅 مصرف روزانه:\n";

            if ($report['trend'] === []) {
                $message .= 'اطلاعات روزانه‌ای ثبت نشده است.';
            } else {
                foreach (array_slice($report['trend'], -30) as $row) {
                    $message .= "\n".PersianCalendar::date($row['date']).' · '.self::bytes($row['total']);
                }
            }

            $message .= "\n\n🖥️ مصرف نودها:";
            $pages = max(1, (int) ceil(count($report['nodes']) / 10));
            $page = min((int) $requestedPage, $pages);
            if ($report['nodes'] === []) {
                $message .= "\nنودی ثبت نشده است.";
            } else {
                $message .= "\nصفحه {$page} از {$pages}:";
                foreach (array_slice($report['nodes'], ($page - 1) * 10, 10) as $node) {
                    $message .= "\n".self::safe($node['name']).' · '.self::bytes($node['total']);
                    $keyboard->addRow(InlineKeyboardButton::make(
                        '👥 '.self::safe($node['name']),
                        callback_data: 'metrics:node:'.$days.':'.$node['uuid'],
                    ));
                }
                $navigation = [];
                if ($page > 1) {
                    $navigation[] = InlineKeyboardButton::make('◀️ قبلی',
                        callback_data: 'metrics:page:'.$days.':'.($page - 1));
                }
                if ($page < $pages) {
                    $navigation[] = InlineKeyboardButton::make('بعدی ▶️',
                        callback_data: 'metrics:page:'.$days.':'.($page + 1));
                }
                if ($navigation !== []) {
                    $keyboard->addRow(...$navigation);
                }
            }

            $message .= "\n\nزمان دریافت: ".PersianCalendar::localDateTime($report['receivedAt']).' (تهران)'
                ."\nمقادیر نامشخص به‌معنای مصرف صفر نیستند.";
        }

        $refreshPage = isset($page) ? 'metrics:page:'.$days.':'.$page : 'metrics:range:'.$days;
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی گزارش', callback_data: $refreshPage));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مانیتورینگ', callback_data: 'metrics:refresh'));
        $this->render($bot, $message, $keyboard);
    }

    public function node(Nutgram $bot, string $days, string $uuid): void
    {
        $actorId = $this->actor($bot);
        if ($actorId === null) {
            return;
        }

        try {
            $report = $this->reports->nodeUsers($actorId, $days, $uuid);
        } catch (ValidationException) {
            $this->render($bot, 'این انتخاب معتبر نیست.', $this->back());

            return;
        }

        if ($report['state'] !== 'available') {
            $message = "👥 کاربران پرمصرف\n\nدریافت گزارش این نود ممکن نیست. فهرست نودها را بررسی کنید.";
        } else {
            $message = '👥 کاربران پرمصرف · '.self::safe($report['name'])
                ."\nاز ".PersianCalendar::date($report['start']).' تا '.PersianCalendar::date($report['end'])." (روزهای UTC، تاریخ شمسی)\n\n";
            if ($report['users'] === []) {
                $message .= 'در این بازه کاربری در فهرست پرمصرف‌ها ثبت نشده است.';
            } else {
                foreach ($report['users'] as $i => $user) {
                    $message .= ($i + 1).'. '.self::safe($user['username']).' · '.self::bytes($user['total'])."\n";
                }
            }
            $message .= "\nزمان دریافت: ".PersianCalendar::localDateTime($report['receivedAt']).' (تهران)';
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'metrics:node:'.$days.':'.$uuid))
            ->addRow(InlineKeyboardButton::make('◀️ گزارش مصرف', callback_data: 'metrics:range:'.$days));
        $this->render($bot, $message, $keyboard);
    }

    private function actor(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
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

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ مانیتورینگ', callback_data: 'metrics:refresh'));
    }

    private static function safe(?string $raw): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw ?? '')), 0, 48);
    }

    private static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'نامشخص';
        }
        if ($bytes < 1048576) {
            return number_format($bytes).' بایت';
        }
        if ($bytes < 1073741824) {
            return number_format($bytes / 1048576, 2).' MiB';
        }

        return number_format($bytes / 1073741824, 2).' GiB';
    }
}
