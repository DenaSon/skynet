<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminCommandCenter;
use App\Modules\Provisioning\Infrastructure\Remnawave\RemnawaveNodeSummary;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** OPS-CMD-01: trusted private Admin presentation; no financial/provider writes. */
final readonly class AdminCommandCenterUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminCommandCenter $center,
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

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        $failures = null;
        try {
            $snapshot = $this->center->handle($actor->userId);
            $text = $this->report($snapshot);
            $failures = self::number($snapshot['stats'], 'notificationIssues');
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (\Throwable) {
            $text = "<b>🏔️ دنابان | وضعیت دنا</b>\n\nدریافت گزارش فعلاً ممکن نیست. دوباره بررسی کنید.";
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'denaban:show'));

        if ($failures !== null && $failures > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔔 بررسی اعلان‌های ناموفق ('.number_format($failures).')',
                callback_data: 'denaban:notifications:1',
            ));
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('💳 امور مالی', callback_data: 'menu:admin:finance'),
            InlineKeyboardButton::make('🖥️ سرورها', callback_data: 'menu:admin:servers'),
        );

        if (config('billing.topups_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('💳 رسیدهای شارژ', callback_data: 'finance:topups:1'));
        }
        if (config('sales.manual_bank_review_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧾 رسیدهای خرید', callback_data: 'bank:list'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param array{cash:array<string,mixed>|null,stats:array<string,mixed>|null,pendingTopups:int|null,receivedAt:string} $snapshot */
    private function report(array $snapshot): string
    {
        $cash = $snapshot['cash'];
        $stats = $snapshot['stats'];
        $pendingTopups = $snapshot['pendingTopups'];

        $items = [
            $pendingTopups,
            self::number($stats, 'receiptsPending'),
            self::number($stats, 'provisioningReview'),
            self::number($stats, 'fulfillmentReview'),
            self::number($stats, 'failedJobs'),
            self::number($stats, 'notificationIssues'),
        ];
        $known = array_filter($items, static fn (?int $n): bool => $n !== null);
        $incomplete = count($known) !== count($items)
            || $cash === null || self::number($stats, 'nodes') === null;
        $todo = array_sum($known);
        $summary = $todo > 0
            ? number_format($todo).' مورد برای پیگیری ثبت شده؛ جزئیات را بررسی کنید.'
            : ($incomplete
                ? 'وضعیت بخش‌های در دسترس بررسی شد؛ بعضی اطلاعات کامل نیست.'
                : 'در بخش‌های بررسی‌شده موردی برای پیگیری ثبت نشده.');

        $lines = [
            '<b>🏔️ دنابان | وضعیت دنا</b>',
            '',
            $summary,
            '',
            '<b>⚠️ نیازمند رسیدگی</b>',
            '🧾 رسیدهای خرید: '.self::format(self::number($stats, 'receiptsPending')),
            '💳 رسیدهای شارژ: '.self::format($pendingTopups),
            '📦 عملیات اشتراک: '.self::format(self::number($stats, 'provisioningReview')),
            '🔧 سفارش‌های نیازمند بررسی: '.self::format(self::number($stats, 'fulfillmentReview')),
            '⚙️ کارهای ناموفق: '.self::format(self::number($stats, 'failedJobs')),
            '🔔 اعلان‌های ناموفق: '.self::format(self::number($stats, 'notificationIssues')),
            '',
            '<b>💰 وضعیت مالی</b>',
            '💵 دریافتی‌های تأییدشده از ابتدا: '.self::toman(self::number($cash, 'cashInRials')),
            '🛒 فروش امروز: '.self::toman(self::number($stats, 'salesTodayRials')),
            '💼 مانده فعلی کیف پول‌ها: '.self::toman(self::number($cash, 'currentWalletBalanceRials')),
            '',
            '<b>👥 کاربران و اشتراک‌ها</b>',
            'کاربران دناگیت: '.self::format(self::number($stats, 'localUsers')),
            'اشتراک‌های فعال پنل: '.self::format(self::number($stats, 'subscriptionsActive')),
            'آنلاین در پنل: '.self::format(self::number($stats, 'online')),
            '',
            '<b>🖥️ وضعیت سرورها</b>',
            'نودهای متصل: '.self::format(self::number($stats, 'connected'))
                .' از '.self::format(self::number($stats, 'nodes')),
            'هاست‌ها: '.self::format(self::number($stats, 'hosts')),
            ...self::nodeLines($stats),
            '',
            '<blockquote>دریافتی‌ها فقط پرداخت‌های ثبت و تأییدشده‌اند؛ موجودی بانک یا سود خالص نیستند. مانده کیف پول فروش جدید نیست. اتصال نود نیز تضمین دسترسی کاربران نیست. «نامشخص» به معنی صفر نیست.</blockquote>',
            '🔄 آخرین بررسی: '.PersianCalendar::localDateTime($snapshot['receivedAt']),
        ];

        return implode("\n", $lines);
    }

    /**
     * Only the already-read /api/nodes inventory is used. Never show raw
     * node addresses, private network metadata or provider credentials.
     *
     * @param array<string,mixed>|null $stats
     * @return list<string>
     */
    private static function nodeLines(?array $stats): array
    {
        $nodes = $stats['nodeSummaries'] ?? null;
        if (! is_array($nodes)) {
            return ['جزئیات نودها: نامشخص'];
        }

        if ($nodes === []) {
            return ['هنوز نودی ثبت نشده است.'];
        }

        if (count($nodes) > 500) {
            return ['جزئیات نودها: نامشخص'];
        }

        // The reader owns the typed/sanitized snapshot; show disconnected
        // nodes first, without hiding the active nodes in short inventories.
        usort($nodes, static function (RemnawaveNodeSummary $a, RemnawaveNodeSummary $b): int {
            $priority = static fn (RemnawaveNodeSummary $node): int => $node->disabled
                ? 3 : ($node->connected ? 2 : ($node->connecting === true ? 1 : 0));

            return ($priority($a) <=> $priority($b)) ?: strcmp($a->name, $b->name);
        });

        $lines = ['<b>جزئیات نودها (گزارش RW)</b>'];
        foreach (array_slice($nodes, 0, 5) as $node) {
            $status = $node->disabled ? '⏸️ غیرفعال'
                : ($node->connected ? '🟢 متصل'
                    : ($node->connecting === true ? '🟠 در حال اتصال' : '🔴 قطع'));
            $name = TelegramUi::escape(mb_substr(
                (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $node->name), 0, 50,
            ));
            $lines[] = $status.' · '.$name;
            $lines[] = '👥 آنلاین این نود: '.self::format($node->usersOnline);
            $lines[] = '📡 دریافت: '.self::rate($node->rxBytesPerSec)
                .' | ارسال: '.self::rate($node->txBytesPerSec);

            if (! $node->connected && ! $node->disabled) {
                $lines[] = '🕒 آخرین تغییر وضعیت: '.($node->lastStatusChange === null
                    ? 'نامشخص' : PersianCalendar::utcDateTime($node->lastStatusChange));
            }

            if ($node->trafficTrackingActive === true && $node->trafficLimitBytes !== null
                && $node->trafficLimitBytes > 0 && $node->trafficUsedBytes !== null) {
                $percent = $node->trafficUsedBytes / $node->trafficLimitBytes * 100;
                $lines[] = '📊 مصرف از سقف نود: '.number_format($percent, 1).'٪';
            }
        }

        if (count($nodes) > 5) {
            $lines[] = 'برای سایر نودها، مدیریت سرورها را باز کنید.';
        }

        return $lines;
    }

    private static function rate(?int $bytesPerSecond): string
    {
        if ($bytesPerSecond === null) {
            return 'نامشخص';
        }

        if ($bytesPerSecond < 1024) {
            return number_format($bytesPerSecond).' B/s';
        }

        if ($bytesPerSecond < 1048576) {
            return number_format($bytesPerSecond / 1024, 1).' KiB/s';
        }

        return number_format($bytesPerSecond / 1048576, 2).' MiB/s';
    }

    /** @param array<string,mixed>|null $data */
    private static function number(?array $data, string $key): ?int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : null;
    }

    private static function format(?int $value): string
    {
        return $value === null ? 'نامشخص' : number_format($value);
    }

    private static function toman(?int $rials): string
    {
        return $rials === null ? 'نامشخص' : number_format(intdiv($rials, 10)).' تومان';
    }
}
