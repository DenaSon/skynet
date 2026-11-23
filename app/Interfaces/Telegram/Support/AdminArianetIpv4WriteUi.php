<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ChangeAdminArianetIpv4;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/**
 * ARI-03B: reviewed staging-only Cloud IP change. No Production write route
 * can dispatch while application environment is production.
 */
final readonly class AdminArianetIpv4WriteUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ChangeAdminArianetIpv4 $changes,
        private MenuRenderer $menus,
    ) {}

    public function prepare(Nutgram $bot, string $rawId, string $rawPage): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        $page = self::number($rawPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'شناسه سرور معتبر نیست.', self::home());

            return;
        }

        $back = self::back($id, $page);
        try {
            $prepared = $this->changes->prepare($actor, $id);
        } catch (Throwable) {
            $this->render($bot, 'آماده‌سازی درخواست ممکن نیست؛ وضعیت سرور، محدودیت‌ها و عملیات قبلی را بررسی کنید.', $back);

            return;
        }

        $p = $prepared['preview'];
        $fee = $p['freeLeft'] > 0 ? 'رایگان' : TelegramUi::tomanPersian($p['feeRials']).' تومان';
        $fields = [
            ['سرور', $p['name']],
            ['IPv4 فعلی', $p['ipv4']],
            ['هزینه تأییدشده', $fee],
            ['سهمیه رایگان', (string) $p['freeLeft']],
            ['نود مرتبط', $p['node']['name']],
            ['آدرس مدیریت نود', $p['node']['address']],
        ];
        $rows = '';
        $plain = '<b>⚠️ تأیید تعویض IPv4</b>';
        foreach ($fields as [$label, $value]) {
            $rows .= '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'.TelegramUi::escape($value).'</b></td></tr>';
            $plain .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }

        $warning = 'این عملیات در محیط آزمایشی هم ممکن است IP و اتصال را تغییر دهد. '
            .'DNS، Remnawave و اتصال کاربران خودکار اصلاح نمی‌شوند. '
            .'تأیید یک‌بارمصرف است و درخواست نامعلوم مجدداً ارسال نمی‌شود.';
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('⚠️ تأیید تغییر IP در محیط آزمایشی',
                callback_data: 'ari5:apply:'.$id.':'.$page.':'.$prepared['token']))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'ari5:cancel:'.$id.':'.$page.':'.$prepared['token']))
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش', callback_data: 'ari4:preview:'.$id.':'.$page));
        $rich = '<h2>⚠️ تأیید تعویض IPv4</h2><table bordered striped compact>'.$rows.'</table>'
            .'<footer>'.TelegramUi::escape($warning).'</footer>';
        $this->render($bot, $plain."\n\n".TelegramUi::escape($warning), $buttons, $rich);
    }

    public function apply(Nutgram $bot, string $rawId, string $rawPage, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        $page = self::number($rawPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::home());

            return;
        }

        try {
            $status = $this->changes->apply($actor, $id, $token);
        } catch (Throwable) {
            $this->render($bot, 'تأیید اجرا نشد؛ وضعیت عملیات را بررسی کنید. درخواست را تکرار نکنید.',
                self::statusBack($id, $page));

            return;
        }

        $message = $status['status'] === 'accepted'
            ? '🟡 درخواست توسط آریاسرویس پذیرفته شد؛ هنوز نتیجه نهایی مشخص نیست.'
            : '⚠️ نتیجه درخواست قطعی نیست و به بررسی نیاز دارد. هیچ درخواست تکراری ارسال نکنید.';
        $this->render($bot, $message, self::statusBack($id, $page));
    }

    public function cancel(Nutgram $bot, string $rawId, string $rawPage, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        $page = self::number($rawPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::home());

            return;
        }

        try {
            $this->changes->cancel($actor, $id, $token);
            $this->render($bot, '✅ درخواست پیش از اجرا لغو شد.', self::back($id, $page));
        } catch (Throwable) {
            $this->render($bot, 'لغو درخواست ممکن نیست؛ وضعیت آن را بررسی کنید.',
                self::statusBack($id, $page));
        }
    }

    public function status(Nutgram $bot, string $rawId, string $rawPage, bool $reconcile = false): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        $page = self::number($rawPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::home());

            return;
        }

        try {
            $row = $reconcile ? $this->changes->reconcile($actor, $id) : $this->changes->status($actor, $id);
        } catch (Throwable) {
            $this->render($bot, 'بررسی وضعیت ممکن نیست؛ از وضعیت ثبت‌شده استفاده کنید و عملیات را تکرار نکنید.',
                self::statusBack($id, $page));

            return;
        }

        $buttons = self::statusBack($id, $page);
        if ($row === null) {
            $this->render($bot, 'درخواستی برای این سرور ثبت نشده است.', $buttons);

            return;
        }

        if ($row['status'] === 'accepted') {
            $buttons = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔎 بررسی نتیجه در آریاسرویس',
                    callback_data: 'ari5:reconcile:'.$id.':'.$page))
                ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش',
                    callback_data: 'ari4:preview:'.$id.':'.$page));
        }
        $state = match ($row['status']) {
            'prepared' => 'در انتظار تأیید',
            'reserved', 'uncertain' => '⚠️ نتیجه نامشخص؛ نیازمند بررسی دستی',
            'accepted' => '🟡 پذیرفته‌شده؛ در انتظار نتیجه',
            'awaiting_coordination' => '⚠️ IP تغییر کرده؛ هماهنگی DNS و نود هنوز انجام نشده',
            'needs_review' => '⚠️ نیازمند بررسی دستی',
            'expired', 'cancelled' => 'منقضی یا لغوشده',
            default => 'وضعیت نیازمند بررسی',
        };
        $rows = self::row('وضعیت', $state)
            .self::row('IPv4 قبلی', $row['oldIpv4'])
            .self::row('IPv4 جدید', $row['newIpv4'] ?? 'هنوز تأیید نشده')
            .self::row('هزینه مبنا', TelegramUi::tomanPersian($row['feeRials']).' تومان')
            .self::row('شناسه عملیات', $row['actionId'] === null ? 'نامشخص' : (string) $row['actionId']);
        $simple = "<b>📋 وضعیت تعویض IPv4</b>\n\n"
            .'وضعیت: '.TelegramUi::escape($state)
            ."\nIPv4 قبلی: ".TelegramUi::escape($row['oldIpv4'])
            ."\nIPv4 جدید: ".TelegramUi::escape($row['newIpv4'] ?? 'هنوز تأیید نشده')
            ."\nهزینه مبنا: ".TelegramUi::tomanPersian($row['feeRials']).' تومان';
        $footer = 'موفقیت تعویض در Cloud به معنای سالم‌بودن اتصال کاربران نیست. '
            .'هیچ تغییر خودکاری در DNS یا نود انجام نشده است.';
        $rich = '<h2>📋 وضعیت تعویض IPv4</h2><table bordered striped compact>'.$rows.'</table>'
            .'<footer>'.TelegramUi::escape($footer).'</footer>';
        $this->render($bot, $simple."\n\n".TelegramUi::escape($footer), $buttons, $rich);
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
                // The existing authorized HTML fallback has identical controls.
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
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'.TelegramUi::escape($value).'</b></td></tr>';
    }

    private static function number(string $raw, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || strlen($raw) > strlen((string) $max)) {
            return null;
        }
        $number = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($number) && $number <= $max ? $number : null;
    }

    private static function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    private static function back(int $id, int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش', callback_data: 'ari4:preview:'.$id.':'.$page));
    }

    private static function statusBack(int $id, int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📋 وضعیت درخواست', callback_data: 'ari5:status:'.$id.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش', callback_data: 'ari4:preview:'.$id.':'.$page));
    }
}
