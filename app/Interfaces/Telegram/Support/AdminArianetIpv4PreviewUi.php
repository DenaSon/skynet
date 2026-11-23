<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ChangeAdminArianetIpv4;
use App\Modules\Provisioning\Application\PreviewAdminArianetIpv4Change;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** ARI-03A: private root-only preview; intentionally has no confirm/write button. */
final readonly class AdminArianetIpv4PreviewUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private PreviewAdminArianetIpv4Change $preview,
        private ChangeAdminArianetIpv4 $changes,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $requestedId, string $requestedPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        $id = self::number($requestedId, PHP_INT_MAX);
        $page = self::number($requestedPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'انتخاب سرور معتبر نیست.', self::backToList());

            return;
        }

        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari4:preview:'.$id.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور', callback_data: 'ari2:view:'.$id.':'.$page));
        try {
            $result = $this->preview->handle($root, $id);
        } catch (Throwable) {
            $this->render($bot, 'دریافت پیش‌نمایش تعویض IP ممکن نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }

        if (is_string($result['ipv4'])) {
            $buttons = InlineKeyboardMarkup::make()
                ->addRow(TelegramUi::copy($result['ipv4'], '📋 کپی IPv4 فعلی'))
                ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari4:preview:'.$id.':'.$page))
                ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور', callback_data: 'ari2:view:'.$id.':'.$page));
        }

        // ARI-03B write controls are absent on Production regardless of ENV.
        // A saved operation status remains inspectable without any provider write.
        try {
            if ($this->changes->enabled()) {
                $buttons->addRow(InlineKeyboardButton::make('⚠️ آماده‌سازی تعویض IP (آزمایشی)',
                    callback_data: 'ari5:begin:'.$id.':'.$page));
            }
            if ($this->changes->status($root, $id) !== null) {
                $buttons->addRow(InlineKeyboardButton::make('📋 وضعیت درخواست تعویض',
                    callback_data: 'ari5:status:'.$id.':'.$page));
            }
        } catch (Throwable) {
            // A missing operation journal during deployment does not hide
            // the already-available ARI-03A read-only preview.
        }

        $node = $result['node'];
        $dns = $result['dns'];
        $fields = [
            ['سرور', $result['name']],
            ['وضعیت', $result['status'] === 'running' ? '🟢 روشن' : '⚠️ '.self::safe($result['status'])],
            ['IPv4 فعلی', $result['ipv4'] ?? 'نامشخص'],
            ['امکان تعویض', self::yesNo($result['changeAllowed'])],
            ['عملیات معلق', self::yesNo($result['pending'])],
            ['تعویض رایگان باقی‌مانده', self::count($result['freeLeft'])],
            ['تعداد تعویض انجام‌شده', self::count($result['changeUsed'])],
            ['هزینه تعویض بعدی', self::fee($result)],
            ['نود مرتبط', $node === null
                ? ($result['nodeUuid'] === null ? 'ثبت نشده' : 'اطلاعات نود در دسترس نیست')
                : $node['name']],
            ['آدرس مدیریت نود', $node['address'] ?? 'نامشخص'],
            ['تطبیق آدرس نود', self::nodeStatus($result['nodeState'])],
            ['DNS مدیریت نود', self::dnsStatus($dns['state'])],
        ];

        if ($dns['name'] !== null) {
            $fields[] = ['نام رکورد A', $dns['name']];
            $fields[] = ['IPv4 رکورد A', $dns['ipv4']];
            $fields[] = ['حالت Cloudflare', $dns['proxied'] === true ? 'Proxied' : 'DNS Only'];
        }

        $warnings = [];
        if ($result['ipv4'] === null || $result['changeAllowed'] !== true || $result['pending'] !== false) {
            $warnings[] = 'امکان اجرای تعویض IP در این لحظه تأیید نشده است.';
        }
        if ($result['freeLeft'] === null || ($result['freeLeft'] === 0 && $result['feeRials'] === null)) {
            $warnings[] = 'سهمیه یا هزینه نیازمند بررسی مجدد است.';
        }
        if ($result['nodeState'] !== 'ip_match'
            && ! ($result['nodeState'] === 'hostname' && $dns['state'] === 'match')) {
            $warnings[] = 'ارتباط نود با IPv4 فعلی به‌طور کامل تأیید نشده است.';
        }
        if ($result['nodeState'] === 'ip_match') {
            $warnings[] = 'نود از IP مستقیم استفاده می‌کند؛ پس از تعویض، تغییر آدرس آن ضروری خواهد بود.';
        }
        if ($result['nodeState'] === 'hostname' && $dns['state'] === 'match') {
            $warnings[] = 'رکورد A مدیریت نود احتمالاً پس از تعویض IP نیاز به بازبینی دارد.';
        }

        $rows = '';
        $plain = '<b>🔎 پیش‌نمایش تعویض IPv4</b>'."\n\n";
        foreach ($fields as [$label, $value]) {
            $rows .= '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
                .TelegramUi::escape($value).'</b></td></tr>';
            $plain .= TelegramUi::escape($label).': '.TelegramUi::escape($value)."\n";
        }

        $warningRich = '';
        foreach ($warnings as $warning) {
            $warningRich .= '<p>⚠️ '.TelegramUi::escape($warning).'</p>';
            $plain .= "\n⚠️ ".TelegramUi::escape($warning);
        }

        $notice = 'فقط آدرس مدیریت نود بررسی شده است؛ سایر دامنه‌های اتصال کاربران، '
            .'رکوردهای Host و کیفیت اتصال نیازمند بررسی جداگانه‌اند. '
            .'این پیش‌نمایش مجوز انجام عملیات نیست و هیچ تغییری اعمال نمی‌کند.';
        $rich = '<h2>🔎 پیش‌نمایش تعویض IPv4</h2>'
            .'<p>آریاسرویس · اطلاعات زمان درخواست</p>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .$warningRich.'<footer>🔒 '.TelegramUi::escape($notice).'</footer>';

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
                // Preserve the same bounded view and buttons for HTML-only clients.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $fallback, reply_markup: $buttons, parse_mode: 'HTML');
    }

    private static function backToList(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    private static function number(string $requested, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $requested) !== 1
            || strlen($requested) > strlen((string) $max)) {
            return null;
        }
        $value = filter_var($requested, FILTER_VALIDATE_INT);

        return is_int($value) && $value <= $max ? $value : null;
    }

    private static function safe(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value)), 0, 48);
    }

    private static function count(?int $count): string
    {
        return $count === null ? 'نامشخص' : PersianCalendar::number(number_format($count));
    }

    private static function yesNo(?bool $state): string
    {
        return $state === null ? 'نامشخص' : ($state ? 'بله' : 'خیر');
    }

    /** @param array<string,mixed> $preview */
    private static function fee(array $preview): string
    {
        if (is_int($preview['freeLeft']) && $preview['freeLeft'] > 0) {
            return 'رایگان';
        }

        return is_int($preview['feeRials'])
            ? TelegramUi::tomanPersian($preview['feeRials']).' تومان'
            : 'نامشخص';
    }

    private static function nodeStatus(string $state): string
    {
        return match ($state) {
            'ip_match' => '✅ IP یکسان',
            'ip_mismatch' => '⚠️ IP متفاوت',
            'hostname' => '🌐 آدرس دامنه‌ای',
            'unlinked' => 'اتصال ثبت نشده',
            default => 'نیازمند بررسی',
        };
    }

    private static function dnsStatus(string $state): string
    {
        return match ($state) {
            'match' => '✅ رکورد A مطابق IP فعلی',
            'mismatch' => '⚠️ رکورد A با IP فعلی متفاوت است',
            'not_applicable' => 'بررسی رکورد A لازم/ممکن نیست',
            default => '⚠️ رکورد قابل تأیید نیست',
        };
    }
}
