<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\InspectAdminArianetIpv4Dependencies;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** Private trusted-root read-only review of Cloud IPv4 coordination dependencies. */
final readonly class AdminArianetIpv4DependenciesUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private InspectAdminArianetIpv4Dependencies $dependencies,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $rawServerId, string $rawPage): void
    {
        $actorId = $this->root($bot);
        if ($actorId === null) {
            return;
        }

        $serverId = self::positive($rawServerId, PHP_INT_MAX);
        $page = self::positive($rawPage, 100);
        if ($serverId === null || $page === null) {
            $this->render($bot, 'انتخاب سرور معتبر نیست.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                    '◀️ سرورهای ابری', callback_data: 'ari2:list:1',
                )));

            return;
        }

        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی مجدد', callback_data: 'ari6:impact:'.$serverId.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش IPv4', callback_data: 'ari4:preview:'.$serverId.':'.$page));

        try {
            $report = $this->dependencies->handle($actorId, $serverId);
        } catch (Throwable) {
            $this->render($bot,
                'دریافت وابستگی‌های اتصال ممکن نیست. تا زمان بررسی کامل، تعویض IP را انجام ندهید.',
                $buttons);

            return;
        }

        $fields = [
            ['سرور', $report['cloudName']],
            ['IPv4 قبلی', $report['oldIp']],
            ['IPv4 جدید', $report['newIp'] ?? 'هنوز تعیین نشده'],
            ['نود مرتبط', $report['nodeName']],
            ['آدرس مدیریت نود', $report['nodeAddress'] ?? 'نامشخص'],
            ['تعداد اتصال‌های مرتبط', self::digits($report['affectedHostCount'])],
            ['اتصال‌های دارای IP مستقیم', self::digits($report['directHostCount'])],
            ['رکوردهای DNS شناسایی‌شده', self::digits(count($report['dns']))],
            ['نتیجه بررسی', $report['ready'] ? '✅ بدون تعارض شناخته‌شده' : '⚠️ نیازمند رسیدگی'],
        ];

        $rows = '';
        $plain = '<b>🔎 بررسی دامنه‌ها و اتصال‌ها</b>';
        foreach ($fields as [$label, $value]) {
            $rows .= self::row($label, $value);
            $plain .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $hostRows = '';
        foreach ($report['hosts'] as $host) {
            $mark = $host['unverified'] || $host['shared'] ? '⚠️ ' : '';
            $name = $mark.$host['name'];
            $value = $host['address'].' · '.($host['type'] === 'direct_ip' ? 'IP مستقیم' : 'دامنه');
            $hostRows .= self::row($name, $value);
            $plain .= "\n\n".TelegramUi::escape($name).' · '.TelegramUi::escape($value);
        }

        $dnsRows = '';
        foreach ($report['dns'] as $record) {
            $state = $record['matched'] ? '✅ ' : '⚠️ ';
            $proxy = $record['proxied'] ? 'Proxied' : 'DNS Only';
            $dnsRows .= self::row($state.$record['hostname'], $record['ipv4'].' · '.$proxy);
        }

        $warnings = '';
        foreach ($report['issues'] as $issue) {
            $warnings .= '<p>⚠️ '.TelegramUi::escape($issue).'</p>';
            $plain .= "\n⚠️ ".TelegramUi::escape($issue);
        }

        $notice = 'این گزارش فقط وابستگی‌های قابل شناسایی را نشان می‌دهد. '
            .'تطبیق رکوردها، سلامت واقعی اتصال کاربران یا آمادگی قطعی برای تعویض IP را تضمین نمی‌کند. '
            .'هیچ تنظیمی در آریاسرویس، Cloudflare یا Remnawave تغییر نکرده است.';
        $rich = '<h2>🔎 بررسی دامنه‌ها و اتصال‌ها</h2><p>اطلاعات زمان درخواست</p>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .($hostRows === '' ? '<p>اتصال مرتبطی شناسایی نشده است.</p>'
                : '<h3>اتصال‌های مرتبط</h3><table bordered striped compact>'.$hostRows.'</table>')
            .($dnsRows === '' ? '' : '<h3>رکوردهای DNS</h3>'
                .'<table bordered striped compact>'.$dnsRows.'</table>')
            .$warnings.'<footer>🔒 '.TelegramUi::escape($notice).'</footer>';

        $this->render($bot, $plain."\n\n🔒 ".TelegramUi::escape($notice), $buttons, $rich);
    }

    private function root(Nutgram $bot): ?int
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

    private function render(Nutgram $bot, string $fallback, InlineKeyboardMarkup $buttons, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $buttons);

                return;
            } catch (TelegramException) {
                // Existing authorized HTML/native fallback.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $fallback, reply_markup: $buttons, parse_mode: 'HTML');
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
            .TelegramUi::escape($value).'</b></td></tr>';
    }

    private static function positive(string $value, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
            || strlen($value) > strlen((string) $max)) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($id) && $id <= $max ? $id : null;
    }

    private static function digits(int $value): string
    {
        return PersianCalendar::number(number_format($value));
    }
}
