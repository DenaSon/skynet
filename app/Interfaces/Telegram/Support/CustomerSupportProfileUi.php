<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ReadCustomerSupportProfile;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadCustomerLiveServices;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** CUS-360-01: concise, read-only 360° support card for an authorized Admin. */
final readonly class CustomerSupportProfileUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadCustomerSupportProfile $profiles,
        private ReadCustomerLiveServices $services,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $subjectId, string $page, string $mode): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->isAdmin() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $subjectId) !== 1
            || preg_match('/\A[1-9][0-9]{0,5}\z/D', $page) !== 1
            || (int) $page > 100000 || ! in_array($mode, ['a', 's'], true)) {
            $this->menus->showUserNotice($bot, 'درخواست پرونده معتبر نیست.');

            return;
        }

        try {
            $profile = $this->profiles->handle($actor->userId, (int) $subjectId);
            $text = $this->report($profile);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->menus->showUserNotice($bot, 'کاربر پیدا نشد.');

            return;
        } catch (Throwable) {
            $this->menus->showUserNotice($bot, 'دریافت پرونده ممکن نیست. دوباره بررسی کنید.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: "users:profile:{$subjectId}:{$page}:{$mode}"));

        if ($profile['servicesTotal'] > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '📡 وضعیت زنده سرویس‌ها', callback_data: "c360:l:{$subjectId}:{$page}:{$mode}:1",
            ));
        }

        if ($profile['financeAllowed']) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '💼 جزئیات کیف پول',
                callback_data: "finance:wallet:{$subjectId}",
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make(
            '◀️ جزئیات کاربر', callback_data: "users:view:{$subjectId}:{$page}:{$mode}",
        ))->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));

        TelegramUi::answerCallback($bot);
        TelegramUi::editText($bot, $text, $keyboard, 'HTML');
    }

    /** CUS-360-02: local service directory, no RW request until one is chosen. */
    public function services(Nutgram $bot, string $subjectId, string $userPage, string $mode, string $servicePage): void
    {
        $actor = $this->reader($bot);
        if ($actor === null) {
            return;
        }
        if (! self::valid($subjectId, $userPage, $mode, $servicePage)) {
            $this->menus->showUserNotice($bot, 'درخواست معتبر نیست.');

            return;
        }

        try {
            $list = $this->services->page($actor, (int) $subjectId, (int) $servicePage);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->menus->showUserNotice($bot, 'کاربر یا صفحه موردنظر پیدا نشد.');

            return;
        } catch (Throwable) {
            $this->menus->showUserNotice($bot, 'دریافت فهرست سرویس‌ها ممکن نیست.');

            return;
        }

        $lines = ['<b>📡 سرویس‌های مشتری</b>', '', 'یک سرویس را برای بررسی وضعیت زنده انتخاب کنید.', ''];
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($list['items'] as $item) {
            $name = self::safe($item['name']);
            $lines[] = '• #'.$item['id'].' · '.$name.' · '.self::serviceState($item['state']);
            $keyboard->addRow(InlineKeyboardButton::make(
                '#'.$item['id'].' · '.mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $item['name']), 0, 30),
                callback_data: "c360:v:{$subjectId}:{$item['id']}:{$userPage}:{$mode}:{$servicePage}",
            ));
        }
        if ($list['items'] === []) {
            $lines[] = 'سرویسی در این صفحه ثبت نشده است.';
        }
        $navigation = [];
        if ($list['page'] > 1) {
            $navigation[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: "c360:l:{$subjectId}:{$userPage}:{$mode}:".($list['page'] - 1));
        }
        if ($list['hasNext']) {
            $navigation[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: "c360:l:{$subjectId}:{$userPage}:{$mode}:".($list['page'] + 1));
        }
        if ($navigation !== []) {
            $keyboard->addRow(...$navigation);
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: "c360:l:{$subjectId}:{$userPage}:{$mode}:{$servicePage}"))
            ->addRow(InlineKeyboardButton::make('◀️ پرونده کاربر', callback_data: "users:profile:{$subjectId}:{$userPage}:{$mode}"));

        TelegramUi::answerCallback($bot);
        TelegramUi::editText($bot, implode("\n", $lines), $keyboard, 'HTML');
    }

    public function service(Nutgram $bot, string $subjectId, string $orderId, string $userPage, string $mode, string $servicePage): void
    {
        $actor = $this->reader($bot);
        if ($actor === null) {
            return;
        }
        if (! self::valid($subjectId, $userPage, $mode, $servicePage)
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', $orderId) !== 1) {
            $this->menus->showUserNotice($bot, 'درخواست معتبر نیست.');

            return;
        }

        try {
            $data = $this->services->detail($actor, (int) $subjectId, (int) $orderId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->menus->showUserNotice($bot, 'سرویس موردنظر پیدا نشد.');

            return;
        } catch (Throwable) {
            $this->menus->showUserNotice($bot, 'دریافت اطلاعات سرویس ممکن نیست.');

            return;
        }

        $lines = ['<b>📦 وضعیت سرویس مشتری</b>', '#'.$data['orderId'].' · '.self::safe($data['name']), '',
            'وضعیت ثبت‌شده: '.self::serviceState($data['localState'])];
        if ($data['state'] !== 'available') {
            $lines[] = 'وضعیت زنده: نامشخص';
            $lines[] = 'دریافت یا تطبیق اطلاعات پنل ممکن نیست؛ این وضعیت به معنی قطع یا حذف سرویس نیست.';
        } else {
            $lines[] = 'وضعیت پنل: '.self::providerStatus($data['remoteStatus']);
            $lines[] = 'تطبیق شناسه سرویس: تأیید شد';
            $lines[] = 'حجم کل: '.self::volume($data['limitBytes'], true);
            $lines[] = 'مصرف‌شده: '.self::volume($data['usedBytes']);
            $lines[] = 'باقی‌مانده: '.($data['limitBytes'] === 0 ? 'نامحدود' : self::volume($data['remainingBytes']));
            $lines[] = 'انقضا: '.($data['expiresAt'] === null ? 'نامشخص' : PersianCalendar::utcDateTime($data['expiresAt']));
            $lines[] = 'زمان باقی‌مانده: '.($data['daysLeft'] === null ? 'نامشخص' : number_format($data['daysLeft']).' روز');
            if ($data['remoteStatus'] !== 'ACTIVE') {
                $lines[] = '⚠️ برای بررسی مشکل سرویس، وضعیت پنل را در نظر بگیرید.';
            }
        }
        $lines[] = '';
        $lines[] = '<i>اطلاعات از پنل خوانده می‌شود؛ آنلاین بودن دستگاه یا دسترسی از اینترنت ایران را تأیید نمی‌کند.</i>';
        $lines[] = '🔄 زمان بررسی: '.PersianCalendar::localDateTime(now()->timezone('Asia/Tehran')->format('Y-m-d H:i'));

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: "c360:v:{$subjectId}:{$orderId}:{$userPage}:{$mode}:{$servicePage}"))
            ->addRow(InlineKeyboardButton::make('◀️ سرویس‌های مشتری', callback_data: "c360:l:{$subjectId}:{$userPage}:{$mode}:{$servicePage}"))
            ->addRow(InlineKeyboardButton::make('🏠 پرونده کاربر', callback_data: "users:profile:{$subjectId}:{$userPage}:{$mode}"));

        TelegramUi::answerCallback($bot);
        TelegramUi::editText($bot, implode("\n", $lines), $keyboard, 'HTML');
    }

    private function reader(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->isAdmin() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private static function valid(string $subjectId, string $page, string $mode, string $servicePage): bool
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $subjectId) === 1
            && preg_match('/\A[1-9][0-9]{0,5}\z/D', $page) === 1
            && (int) $page <= 100000
            && in_array($mode, ['a', 's'], true)
            && preg_match('/\A[1-9][0-9]{0,3}\z/D', $servicePage) === 1
            && (int) $servicePage <= 1000;
    }

    private static function providerStatus(?string $value): string
    {
        return match ($value) {
            'ACTIVE' => '🟢 فعال',
            'DISABLED' => '⏸️ غیرفعال',
            'EXPIRED' => '⌛ منقضی‌شده',
            'LIMITED' => '⚠️ حجم تمام‌شده',
            default => 'نامشخص',
        };
    }

    private static function volume(?int $bytes, bool $limit = false): string
    {
        if ($bytes === null) {
            return 'نامشخص';
        }
        if ($limit && $bytes === 0) {
            return 'نامحدود';
        }

        return number_format($bytes / 1073741824, 2).' گیگابایت';
    }

    /** @param array<string,mixed> $data */
    private function report(array $data): string
    {
        $user = $data['user'];
        $name = TelegramUi::escape(mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $user->name), 0, 65));
        $lines = [
            '<b>👤 پرونده ۳۶۰ درجه مشتری</b>',
            '<b>'.$name.'</b> · #'.$user->id,
            ($user->active ? '🟢 فعال' : '🔴 غیرفعال').' · تلگرام '.($user->telegramLinked ? 'متصل' : 'متصل نیست'),
            '',
            '<b>📦 سرویس‌های ثبت‌شده</b>',
            'تعداد: '.number_format($data['servicesTotal'])
                .' | فعال در سیستم: '.number_format($data['servicesActive'])
                .' | نیازمند بررسی: '.number_format($data['servicesReview']),
        ];

        foreach ($data['recentServices'] as $service) {
            $lines[] = '• #'.$service['id'].' · '.self::safe($service['name'])
                .' · '.self::serviceState($service['state']);
        }
        if ($data['recentServices'] === []) {
            $lines[] = 'سرویسی برای این حساب ثبت نشده است.';
        }
        $lines[] = '<i>وضعیت‌ها محلی هستند؛ اتصال و انقضای زنده اینجا بررسی نمی‌شوند.</i>';

        $lines[] = '';
        $lines[] = '<b>🛒 خرید و پرداخت</b>';
        $lines[] = 'سفارش‌ها: '.number_format($data['ordersTotal'])
            .' | در انتظار پرداخت: '.number_format($data['ordersPending']);
        $lines[] = 'آخرین پرداخت تأییدشده: '.($data['lastSettledAt'] === null
            ? 'ثبت نشده' : PersianCalendar::utcDateTime($data['lastSettledAt']));
        foreach ($data['recentOrders'] as $order) {
            $lines[] = '• #'.$order['id'].' · '.self::safe($order['name'])
                .' · '.self::orderState($order['status'])
                .' · '.PersianCalendar::utcDateTime($order['at']);
        }

        if ($data['financeAllowed']) {
            $lines[] = '';
            $lines[] = '<b>💰 کیف پول</b>';
            $lines[] = $data['financeUnavailable']
                ? 'مانده فعلاً در دسترس نیست.'
                : 'مانده ثبت‌شده: '.TelegramUi::toman($data['walletBalanceRials']).' تومان';
        }

        $lines[] = '';
        $lines[] = '<b>📱 ارتباط با مشتری</b>';
        if ($data['salesContact'] === null) {
            $lines[] = 'ارتباط ثبت‌شده‌ای با بات فروش وجود ندارد.';
        } else {
            $lines[] = 'بات فروش: '.self::contactState($data['salesContact']['status']);
            $lines[] = 'آخرین تعامل ثبت‌شده: '.PersianCalendar::utcDateTime($data['salesContact']['lastSeen']);
        }
        $lines[] = '';
        $lines[] = '🔄 آخرین بررسی: '.PersianCalendar::localDateTime(now()->timezone('Asia/Tehran')->format('Y-m-d H:i'));

        return implode("\n", $lines);
    }

    private static function safe(string $value): string
    {
        return TelegramUi::escape(mb_substr(
            (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value), 0, 45,
        ));
    }

    private static function serviceState(string $value): string
    {
        return match ($value) {
            'active' => 'فعال (ثبت‌شده)',
            'needs_review' => 'نیازمند بررسی',
            'disabled' => 'غیرفعال',
            default => 'وضعیت دیگر',
        };
    }

    private static function orderState(string $value): string
    {
        return match ($value) {
            'pending_payment' => 'منتظر پرداخت',
            'paid' => 'پرداخت‌شده',
            'fulfilled' => 'فعال‌سازی ثبت‌شده',
            'cancelled', 'canceled' => 'لغوشده',
            'needs_review' => 'نیازمند بررسی',
            default => 'وضعیت دیگر',
        };
    }

    private static function contactState(string $value): string
    {
        return match ($value) {
            'reachable' => 'ارتباط ثبت‌شده',
            'blocked' => 'مسدودشده',
            'unreachable' => 'غیرقابل دسترس',
            default => 'وضعیت نامشخص',
        };
    }
}
