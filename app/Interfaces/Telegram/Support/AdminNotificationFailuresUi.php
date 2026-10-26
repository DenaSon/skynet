<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminNotificationFailures;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** OPS-CMD-01A: read-only, private root notification investigation. */
final readonly class AdminNotificationFailuresUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminNotificationFailures $failures,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $page = '1'): void
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

        try {
            $data = $this->failures->handle($actor->userId, $page);
            $text = $this->report($data);
            $keyboard = $this->keyboard($data['page'], $data['pages']);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $text = "🔔 اعلان‌های ناموفق\n\nصفحه موردنظر معتبر نیست.";
            $keyboard = $this->keyboard(1, 1);
        } catch (\Throwable) {
            $text = "🔔 اعلان‌های ناموفق\n\nدریافت سوابق فعلاً ممکن نیست. دوباره بررسی کنید.";
            $keyboard = $this->keyboard(1, 1);
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param array{total:int,page:int,pages:int,items:list<array<string,int|string|null>>} $data */
    private function report(array $data): string
    {
        $lines = [
            '<b>🔔 اعلان‌های ناموفق</b>',
            '',
            number_format($data['total']).' سابقه · صفحه '.$data['page'].' از '.$data['pages'],
            'این سوابق الزاماً مشکل فعال نیستند. پیش از ارسال مجدد، علت را بررسی کنید.',
        ];

        if ($data['items'] === []) {
            $lines[] = '';
            $lines[] = '✅ فعلاً اعلان ناموفقی ثبت نشده است.';
        }

        foreach ($data['items'] as $item) {
            $lines[] = '';
            $lines[] = '<b>#'.$item['id'].' · '.self::event((string) $item['eventType']).'</b>';
            $lines[] = '🤖 '.self::bot((string) $item['bot']).' · کاربر #'.$item['recipientId'];
            $lines[] = 'وضعیت: '.self::state((string) $item['state']).' · تلاش: '.number_format((int) $item['attempts']);
            $lines[] = 'علت: '.self::reason((string) $item['reason']);
            foreach (['orderId' => 'سفارش', 'claimId' => 'رسید خرید', 'topupId' => 'شارژ کیف پول', 'panelRequestId' => 'درخواست پنل فروش'] as $key => $label) {
                if ($item[$key] !== null) {
                    $lines[] = $label.' #'.$item[$key];

                    break;
                }
            }
            $lines[] = '🕒 '.PersianCalendar::utcDateTime((string) $item['updatedAt']);
        }

        return implode("\n", $lines);
    }

    private function keyboard(int $page, int $pages): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'denaban:notifications:'.$page));

        if ($pages > 1) {
            $row = [];
            if ($page > 1) {
                $row[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'denaban:notifications:'.($page - 1));
            }
            if ($page < $pages) {
                $row[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'denaban:notifications:'.($page + 1));
            }
            $keyboard->addRow(...$row);
        }

        return $keyboard->addRow(InlineKeyboardButton::make('◀️ دنابان', callback_data: 'denaban:show'));
    }

    private static function event(string $value): string
    {
        return match ($value) {
            'ReceiptSubmitted' => 'رسید جدید خرید',
            'PaymentReviewed' => 'نتیجه بررسی پرداخت',
            'WalletTopupSubmitted' => 'رسید شارژ کیف پول',
            'WalletTopupApproved' => 'تأیید شارژ کیف پول',
            'WalletTopupRejected' => 'رد شارژ کیف پول',
            'WalletPurchaseSettled' => 'پرداخت از کیف پول',
            'FulfillmentPlanBlocked' => 'مشکل فعال‌سازی سفارش',
            'TrialActivated' => 'فعال‌شدن تست رایگان',
            'TrialEnded' => 'پایان تست رایگان',
            'SubscriptionActivated' => 'فعال‌شدن اشتراک',
            'SubscriptionRenewed' => 'تمدید اشتراک',
            'SubscriptionExpiry24h' => 'هشدار انقضای اشتراک',
            'SubscriptionExpiry1h' => 'هشدار انقضای نزدیک',
            'SubscriptionLowTraffic' => 'هشدار اتمام حجم',
            'SalesPanelSubmitted' => 'درخواست پنل فروش',
            'SalesPanelApproved' => 'تأیید پنل فروش',
            'SalesPanelRejected' => 'رد پنل فروش',
            default => 'رویداد دیگر',
        };
    }

    private static function bot(string $value): string
    {
        return match ($value) {
            'admin' => 'بات مدیریت',
            'sales' => 'بات فروش',
            default => 'بات نامشخص',
        };
    }

    private static function state(string $value): string
    {
        return match ($value) {
            'dead' => 'تلاش‌ها تمام شده',
            'unavailable' => 'ارسال ممکن نبوده',
            default => 'نامشخص',
        };
    }

    private static function reason(string $value): string
    {
        return match ($value) {
            'trial_delivery_uncertain' => 'نتیجه ارسال نامشخص است؛ برای جلوگیری از پیام تکراری، ارسال مجدد انجام نمی‌شود',
            'trial_already_attempted' => 'ارسال پایان تست قبلاً انجام یا ثبت شده است',
            'trial_not_active' => 'تست دیگر فعال نیست',
            'transport' => 'مشکل ارتباط با تلگرام',
            'not_started' => 'کاربر بات مقصد را شروع نکرده',
            'blocked' => 'بات توسط کاربر مسدود شده',
            'inactive' => 'حساب گیرنده غیرفعال است',
            'not_authorized', 'role_revoked' => 'دسترسی گیرنده دیگر معتبر نیست',
            'already_reviewed', 'already_resolved' => 'موضوع قبلاً بررسی شده',
            'stale_subscription' => 'هشدار اشتراک دیگر معتبر نیست',
            'superseded_by_activation' => 'اعلان با فعال‌شدن سرویس جایگزین شده',
            'invalid_settlement', 'invalid_claim', 'invalid_topup', 'invalid_panel' => 'اطلاعات رویداد معتبر نیست',
            'renewal_not_complete', 'not_active' => 'وضعیت اشتراک تأیید نشده',
            'inactive_wallet' => 'کیف پول در دسترس نیست',
            'sales_disabled' => 'بات فروش برای ارسال فعال نیست',
            'missing_credentials' => 'تنظیمات ارسال کامل نیست',
            'invalid_bot', 'invalid_recipient' => 'مقصد اعلان معتبر نیست',
            'invalid_media', 'source_unavailable' => 'فایل پیام در دسترس نیست',
            'unsupported_event' => 'نوع اعلان پشتیبانی نمی‌شود',
            '' => 'ثبت نشده',
            default => 'علت دیگری ثبت شده؛ نیازمند بررسی',
        };
    }
}
