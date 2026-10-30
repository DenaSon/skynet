<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\AdminProviderOverview;
use App\Modules\Provisioning\Application\AdminSystemStats;
use App\Modules\Provisioning\Application\ReadAdminProviderOverview;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

final readonly class AdminProviderOverviewUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminProviderOverview $read,
    ) {}

    public function show(Nutgram $bot, bool $simple = false): void
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

        $keyboard->addRow(
            InlineKeyboardButton::make('📊 مانیتورینگ', callback_data: 'metrics:refresh'),
            InlineKeyboardButton::make('🖥️ نودها', callback_data: 'node1:list:1'),
        );
        $keyboard->addRow(
            InlineKeyboardButton::make('🌐 هاست‌ها', callback_data: 'host1:list:1'),
            InlineKeyboardButton::make('🧩 پروفایل‌ها', callback_data: 'cfg1:list:1'),
        );

        $keyboard->addRow(InlineKeyboardButton::make('🌐 مدیریت DNS', callback_data: 'cf2:zones'));
        $keyboard->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی', callback_data: 'servers:refresh'));

        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $keyboard->addRow(InlineKeyboardButton::make(
            $simple ? '✨ نمای جدید' : '📝 نمای ساده',
            callback_data: $simple ? 'menu:admin:servers' : 'servers:simple',
        ));

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        if (! $simple && $overview->state === 'available') {
            try {
                TelegramUi::editRichText(
                    $bot,
                    new InputRichMessage(html: self::richReport($overview), is_rtl: true),
                    $keyboard,
                );

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Keep the original read-only Admin view on unsupported clients.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }

    private static function richReport(AdminProviderOverview $result): string
    {
        $overview = '<tr><td>🖥️ سرورهای متصل</td><td><b>'.number_format($result->connectedNodes ?? 0)
            .' از '.number_format($result->nodes ?? 0).'</b></td></tr>'
            .'<tr><td>🌐 میزبان‌ها</td><td>'.number_format($result->hosts ?? 0).'</td></tr>'
            .'<tr><td>🧩 پروفایل‌ها</td><td>'.number_format($result->configProfiles ?? 0).'</td></tr>'
            .'<tr><td>👥 گروه‌ها</td><td>'.number_format($result->internalSquads ?? 0).'</td></tr>'
            .'<tr><td>⚙️ نمونه‌های فعال پنل</td><td>'.number_format($result->instances ?? 0).'</td></tr>'
            .'<tr><td>⚠️ نیازمند بررسی</td><td>'.number_format($result->needsReview ?? 0).'</td></tr>';

        $nodes = '';
        foreach (array_slice($result->nodeSummaries ?? [], 0, 10) as $node) {
            $state = $node['disabled'] ? '⏸️ غیرفعال'
                : ($node['connected'] ? '🟢 متصل' : '🔴 قطع');
            $online = is_int($node['usersOnline'] ?? null)
                ? number_format($node['usersOnline']) : 'نامشخص';
            $nodes .= '<tr><td>'.TelegramUi::escape(self::safeNodeName((string) $node['name'])).'</td>'
                .'<td>'.TelegramUi::escape($state).'</td><td>'.$online.'</td></tr>';
        }

        $nodesTable = $nodes === '' ? '<p>هنوز سروری تعریف نشده است.</p>'
            : '<table bordered striped compact><tr><td><b>نود</b></td><td><b>وضعیت</b></td><td><b>آنلاین</b></td></tr>'
                .$nodes.'</table>';

        if (count($result->nodeSummaries ?? []) > 10) {
            $nodesTable .= '<p>سایر نودها در بخش «🖥️ نودها» قابل مشاهده‌اند.</p>';
        }

        $stats = '';
        if ($result->systemStats !== null) {
            $stats = '<details><summary>📊 جزئیات منابع پنل</summary><p>'
                .TelegramUi::escape('اطلاعات زیر از پنل گزارش شده‌اند.').'</p><p>'
                .str_replace("\n", '</p><p>', TelegramUi::escape(trim(self::systemStats($result->systemStats))))
                .'</p></details>';
        }

        return '<h2>🖥️ وضعیت سرورها</h2>'
            .'<h3>📋 نمای کلی شبکه</h3>'
            .'<table bordered striped compact>'.$overview.'</table>'
            .'<h3>🌐 وضعیت نودها</h3>'.$nodesTable
            .$stats
            .'<footer>آمار اتصال از Remnawave است؛ تأخیر TCP در فاز بعد افزوده می‌شود.</footer>';
    }

    private static function safeNodeName(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 50);
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
            .($result->nodes === 0 ? "\n\nهنوز سروری تعریف نشده است." : '')
            .($result->systemStats !== null ? self::systemStats($result->systemStats) : '');
    }

    private static function systemStats(AdminSystemStats $stats): string
    {
        $total = number_format($stats->memoryTotal / 1073741824, 2, '.', '');
        $used = number_format($stats->memoryUsed / 1073741824, 2, '.', '');
        $free = number_format($stats->memoryFree / 1073741824, 2, '.', '');
        $percent = (int) round($stats->memoryUsed * 100 / $stats->memoryTotal);
        $seconds = (int) floor($stats->uptimeSeconds);
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $duration = ($days > 0 ? "{$days} روز و " : '')."{$hours} ساعت و {$minutes} دقیقه";

        return "\n\n📊 آمار گزارش‌شده پنل\n"
            ."هسته‌های CPU: {$stats->cpuCores}\n"
            ."RAM مصرف‌شده: {$used} از {$total} GiB ({$percent}٪)\n"
            ."RAM آزاد: {$free} GiB\n"
            ."زمان فعالیت: {$duration}\n"
            ."کاربران کل: {$stats->totalUsers}\n"
            ."کاربران آنلاین: {$stats->onlineUsers}";
    }
}
