<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\AdminPendingReceiptFollowup;
use App\Support\PersianCalendar;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

final readonly class AdminPendingReceiptFollowupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private AdminPendingReceiptFollowup $followups,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $page = '1', ?string $userId = null): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        $number = self::id($page);
        $buyer = $userId === null ? null : self::id($userId);
        if ($number === null || $number > 1000 || ($userId !== null && $buyer === null)) {
            $this->render($bot, 'صفحه معتبر نیست.', $this->back());

            return;
        }

        try {
            $data = $this->followups->browse($root, $number, $buyer);
        } catch (Throwable) {
            $this->render($bot, 'دریافت سفارش‌های بدون رسید ممکن نیست.', $this->back());

            return;
        }

        $title = '🧾 سفارش‌های بدون رسید';
        $text = $title."\nصفحه ".$number."\n\n";
        $rows = '';
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($data['items'] as $item) {
            $value = TelegramUi::toman((int) $item->total_price_rials).' تومان';
            $status = self::contact($item);
            $text .= '#'.$item->id.' · کاربر #'.$item->buyer_user_id.' · '.$value.' · '.$status."\n";
            $rows .= '<tr><td>#'.$item->id.' · کاربر #'.$item->buyer_user_id.'</td>'
                .'<td>'.$value.' · '.$status.'</td></tr>';
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔎 سفارش #'.$item->id, callback_data: 'receipt:followup:view:'.$item->id,
            ));
        }
        if ($data['items'] === []) {
            $text .= 'سفارش واجد شرایطی در این صفحه وجود ندارد.';
            $rows = '<tr><td>نتیجه</td><td>سفارشی یافت نشد</td></tr>';
        }

        $nav = [];
        if ($number > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: $this->listingCallback($number - 1, $buyer));
        }
        if ($data['hasNext']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: $this->listingCallback($number + 1, $buyer));
        }
        if ($nav !== []) {
            $keyboard->addRow(...$nav);
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: $this->listingCallback($number, $buyer)))
            ->addRow(InlineKeyboardButton::make('◀️ رسیدهای خرید', callback_data: 'bank:list'));

        $text .= "\n\n⚠️ ثبت‌نشدن رسید، اثبات واریز یا عدم واریز نیست.";
        $rich = '<h2>'.$title.'</h2><table bordered striped compact>'.$rows.'</table>'
            .'<footer>صفحه '.$number.' · بدون رسید ثبت‌شده · هیچ پرداختی تأیید نمی‌شود.</footer>';
        $this->render($bot, $text, $keyboard, $rich);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $orderId = self::id($id);
        if ($orderId === null) {
            $this->render($bot, 'سفارش معتبر نیست.', $this->back());

            return;
        }

        try {
            $snapshot = $this->followups->detail($root, $orderId);
        } catch (ValidationException) {
            $this->render($bot, 'این سفارش دیگر در انتظار رسید نیست.', $this->back());

            return;
        } catch (Throwable) {
            $this->render($bot, 'دریافت اطلاعات سفارش ممکن نیست.', $this->back());

            return;
        }

        $order = $snapshot['order'];
        $name = TelegramUi::escape(mb_substr((string) $order->name, 0, 64));
        $contact = self::contact($order);
        $tgId = is_numeric($order->telegram_user_id) ? (string) $order->telegram_user_id : 'ثبت نشده';
        $date = PersianCalendar::utcDateTime((string) $order->created_at);
        $value = TelegramUi::toman((int) $order->total_price_rials).' تومان';
        $latest = $snapshot['latest'];
        $state = $latest === null ? 'قبلاً یادآوری ثبت نشده' : self::delivery((string) $latest->state);
        $text = "<b>🧾 سفارش بدون رسید #{$orderId}</b>\n\n"
            ."مشتری: {$name} · #{$order->buyer_user_id}\n"
            .'پلن: '.TelegramUi::escape((string) $order->product_name)."\n"
            ."مبلغ سفارش: {$value}\n"
            ."زمان سفارش: {$date}\n"
            ."شناسه تلگرام: {$tgId}\n"
            ."بات فروش: {$contact}\n"
            ."آخرین یادآوری: {$state}\n\n"
            .'⚠️ واریز بانک هنوز از این سفارش احراز نشده است.';

        $keyboard = InlineKeyboardMarkup::make();
        if (is_numeric($order->telegram_user_id) && (int) $order->telegram_user_id > 0) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '📋 کپی شناسه تلگرام',
                copy_text: CopyTextButton::make((string) $order->telegram_user_id),
            ));
        }
        if ($snapshot['canSend']) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '📨 یادآوری ارسال رسید', callback_data: 'receipt:followup:prepare:'.$orderId,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('👤 پرونده مشتری', callback_data: 'users:profile:'.$order->buyer_user_id.':1:a'))
            ->addRow(InlineKeyboardButton::make('◀️ سفارش‌های بدون رسید', callback_data: 'receipt:followup:list:1'));

        $rich = '<h2>🧾 سفارش بدون رسید #'.$orderId.'</h2>'
            .'<table bordered striped compact>'
            .'<tr><td>مشتری</td><td>'.$name.' · #'.$order->buyer_user_id.'</td></tr>'
            .'<tr><td>پلن</td><td>'.TelegramUi::escape((string) $order->product_name).'</td></tr>'
            .'<tr><td>مبلغ سفارش</td><td>'.$value.'</td></tr>'
            .'<tr><td>زمان سفارش</td><td>'.$date.'</td></tr>'
            .'<tr><td>شناسه تلگرام</td><td>'.$tgId.'</td></tr>'
            .'<tr><td>ارتباط با بات فروش</td><td>'.$contact.'</td></tr>'
            .'<tr><td>آخرین یادآوری</td><td>'.$state.'</td></tr>'
            .'</table><footer>⚠️ وضعیت واریز هنوز تأیید نشده است.</footer>';
        $this->render($bot, $text, $keyboard, $rich);
    }

    public function prepare(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $orderId = self::id($id);
        if ($orderId === null) {
            $this->render($bot, 'درخواست معتبر نیست.', $this->back());

            return;
        }
        try {
            $snapshot = $this->followups->detail($root, $orderId);
            if (! $snapshot['canSend']) {
                throw ValidationException::withMessages(['order' => 'Unavailable']);
            }
        } catch (Throwable) {
            $this->render($bot, 'ارسال یادآوری فعلاً ممکن نیست؛ وضعیت سفارش و بات فروش را بررسی کنید.', $this->back($orderId));

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put(self::key($root, $token), $orderId, now()->addMinutes(3));
        $text = '⚠️ تأیید یادآوری ارسال رسید'."\n\nسفارش #".$orderId
            ."\nپیام از بات فروش برای مشتری ارسال می‌شود؛ هیچ پرداختی تأیید یا سرویسی فعال نخواهد شد."
            ."\n\nآیا تأیید می‌کنید؟";
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ ثبت درخواست ارسال', callback_data: 'receipt:followup:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'receipt:followup:view:'.$orderId));
        $rich = '<h2>⚠️ تأیید یادآوری ارسال رسید</h2>'
            .'<table bordered striped compact><tr><td>سفارش</td><td>#'.$orderId.'</td></tr>'
            .'<tr><td>فرستنده</td><td>بات فروش</td></tr></table>'
            .'<footer>هیچ پرداختی تأیید یا سرویسی فعال نخواهد شد.</footer>';
        $this->render($bot, $text, $keyboard, $rich);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->render($bot, 'تأیید معتبر نیست.', $this->back());

            return;
        }

        $key = self::key($root, $token);
        $orderId = Cache::lock($key.':lock', 10)->block(2, static function () use ($key): mixed {
            return Cache::pull($key);
        });
        if (! is_int($orderId) || $orderId < 1) {
            $this->render($bot, 'این تأیید قبلاً استفاده شده یا منقضی شده است.', $this->back());

            return;
        }

        try {
            $deliveryId = $this->followups->enqueue($root, $orderId, $token);
            DispatchTelegramNotification::dispatch($deliveryId);
            $message = "✅ درخواست یادآوری سفارش #{$orderId} ثبت شد."
                ."\nوضعیت ارسال را از صفحه سفارش بررسی کنید.";
        } catch (Throwable) {
            $message = 'ثبت یادآوری ممکن نشد؛ وضعیت سفارش، رسید و ارتباط بات فروش را دوباره بررسی کنید.';
        }

        $this->render($bot, $message, $this->back($orderId));
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        $actor = $telegramId === null ? null : $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private static function contact(object $order): string
    {
        if (! is_numeric($order->telegram_user_id) || (int) $order->telegram_user_id < 1) {
            return 'شناسه تلگرام ثبت نشده';
        }
        if ($order->delivery_status === 'blocked') {
            return '🔴 بات مسدود شده';
        }
        if ($order->delivery_status === 'reachable'
            && (string) $order->chat_id === (string) $order->telegram_user_id) {
            return '🟢 ارتباط ثبت‌شده';
        }

        return '⚪ ارتباط قابل تأیید نیست';
    }

    private static function delivery(string $state): string
    {
        return match ($state) {
            'sent' => '✅ تحویل به تلگرام',
            'pending', 'processing' => '🕘 در انتظار ارسال',
            'unavailable' => '⚠️ ارسال ممکن نشد',
            'dead' => '⚠️ ارسال ناموفق',
            default => 'نامشخص',
        };
    }

    private function listingCallback(int $page, ?int $user): string
    {
        return $user === null
            ? 'receipt:followup:list:'.$page
            : 'receipt:followup:user:'.$user.':'.$page;
    }

    private static function key(int $actor, string $token): string
    {
        return 'receipt:followup:confirm:'.$actor.':'.$token;
    }

    private static function id(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function back(?int $orderId = null): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت', callback_data: $orderId === null
                ? 'receipt:followup:list:1' : 'receipt:followup:view:'.$orderId,
        ));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
