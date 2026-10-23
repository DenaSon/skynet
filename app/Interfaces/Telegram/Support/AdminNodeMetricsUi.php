<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Support\PersianCalendar;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminNodeMetrics;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** MET-01: one readable Admin Bot snapshot; no polling or background service. */
final readonly class AdminNodeMetricsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminNodeMetrics $metrics,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        $snapshot = $this->metrics->handle($actor->userId);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی آمار', callback_data: 'metrics:refresh'))
            ->addRow(InlineKeyboardButton::make('📈 گزارش مصرف', callback_data: 'metrics:range:7'))
            ->addRow(InlineKeyboardButton::make('🖥️ مدیریت نودها', callback_data: 'node1:list:1'))
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));

        if ($snapshot['state'] !== 'available') {
            $message = "📊 مانیتورینگ سرورها\n\nاطلاعات فعلاً در دسترس نیست. اتصال پنل را بررسی کنید و دوباره تلاش کنید.";
        } else {
            $message = "📊 مانیتورینگ سرورها\n\n"
                ."🖥️ نودها: {$snapshot['total']} | فعال: {$snapshot['enabled']} | متصل: {$snapshot['connected']}\n"
                .'👥 کاربران آنلاین کل: '.self::counter($snapshot['onlineTotal'])."\n"
                .'📈 مصرف تاریخ '.PersianCalendar::date($snapshot['trafficDate']).' (روز UTC): '.self::bytes($snapshot['todayBytes'])."\n"
                .'📈 مصرف ۷ روز اخیر: '.self::bytes($snapshot['weekBytes'])."\n"
                ."\n".'وضعیت نودها:'."\n";

            if ($snapshot['nodes'] === []) {
                $message .= 'هنوز نودی ثبت نشده است.';
            } else {
                foreach (array_slice($snapshot['nodes'], 0, 10) as $node) {
                    $status = $node['disabled'] ? '⏸️' : ($node['connected'] ? '🟢' : '🔴');
                    $online = $node['online'] === null ? 'نامشخص' : (string) $node['online'];
                    $message .= "\n".$status.' '.self::safe($node['name']).' · آنلاین: '.$online;
                }
                if (count($snapshot['nodes']) > 10) {
                    $message .= "\n".'برای مشاهده سایر نودها، فهرست مدیریت را باز کنید.';
                }
            }

            $message .= "\n\n".'🕒 زمان دریافت: '.PersianCalendar::localDateTime($snapshot['receivedAt']).' (تهران)'
                ."\n".'— آمار لحظه دریافت است؛ صفر با اطلاعات ناموجود تفاوت دارد.';
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }

    private static function counter(?int $value): string
    {
        return $value === null ? 'نامشخص' : number_format($value);
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

    private static function safe(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 50);
    }
}
