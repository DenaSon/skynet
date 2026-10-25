<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ReadCustomerSupportProfile;
use App\Modules\Identity\Application\ResolveTelegramActor;
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
