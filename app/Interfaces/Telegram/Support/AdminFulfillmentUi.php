<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReviewPaidFulfillment;
use App\Modules\Provisioning\Jobs\FulfillPaidPlanOrderJob;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** OP-03: Admin-only explicit confirmation. Remote calls happen in the worker. */
final readonly class AdminFulfillmentUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReviewPaidFulfillment $reviews,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, bool $completed = false): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $orders = $completed ? $this->reviews->completed($root) : $this->reviews->pending($root);
        } catch (Throwable) {
            $this->render($bot, 'نمایش سفارش‌ها فعلاً ممکن نیست.', $this->home());

            return;
        }

        $lines = [$completed ? '✅ فعال‌سازی‌های انجام‌شده' : '🔌 فعال‌سازی اشتراک‌ها', 'آخرین ۲۰ سفارش', ''];
        $buttons = InlineKeyboardMarkup::make();
        foreach ($orders as $order) {
            $state = $this->status($order->intent_state);
            $lines[] = '#'.$order->id.' · '.$state;
            $buttons->addRow(InlineKeyboardButton::make(
                'سفارش #'.$order->id.' · '.$state,
                callback_data: 'full:view:'.$order->id,
            ));
        }
        if ($orders === []) {
            $lines[] = $completed
                ? 'هنوز اشتراک فعال‌شده‌ای ثبت نشده است.'
                : 'سفارش پرداخت‌شده‌ای برای فعال‌سازی یا بررسی وجود ندارد.';
        }
        if (! $completed && (config('services.remnawave.writes_enabled') !== true
            || config('services.remnawave.squad_bindings_live_verified') !== true)) {
            $lines[] = '';
            $lines[] = 'فعال‌سازی سرویس در حال حاضر غیرفعال است.';
        }
        $buttons->addRow(InlineKeyboardButton::make(
            '🔄 تازه‌سازی', callback_data: $completed ? 'full:completed' : 'full:list',
        ));
        $buttons->addRow(InlineKeyboardButton::make(
            $completed ? '🕘 در انتظار فعال‌سازی' : '✅ فعال‌سازی‌های انجام‌شده',
            callback_data: $completed ? 'full:list' : 'full:completed',
        ));
        $buttons->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, implode("\n", $lines), $buttons);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $orderId = $this->id($id);
        if ($orderId === null) {
            $this->render($bot, 'شناسه سفارش معتبر نیست.', $this->home());

            return;
        }
        try {
            $order = $this->reviews->detail($root, $orderId);
        } catch (Throwable) {
            $this->render($bot, 'این سفارش در دسترس نیست.', $this->home());

            return;
        }

        $lines = [
            '📋 سفارش #'.$orderId,
            'محصول: '.$this->safe($order->product_name),
            'مبلغ ثبت‌شده: '.TelegramUi::toman((int) $order->total_price_rials).' تومان',
            'مدت: '.$order->duration_days.' روز',
            'حجم: '.($order->traffic_gb === null ? 'نامحدود' : $order->traffic_gb.' گیگابایت'),
            'وضعیت: '.$this->status($order->intent_state),
        ];

        $buttons = InlineKeyboardMarkup::make();
        if ($order->intent_state === 'pending') {
            $lines[] = '';
            $lines[] = 'درخواست فعال‌سازی خودکار ثبت شده است. وضعیت را تازه‌سازی کنید.';
            $lines[] = 'اگر انجام نشد، از پشتیبانی فنی درخواست بررسی صف کنید.';
        }
        if ($order->intent_state === 'completed') {
            $lines[] = '';
            $lines[] = 'فعال‌سازی این سفارش ثبت شده است. مشتری می‌تواند اشتراک خود را در بات فروش مشاهده کند.';
            $buttons->addRow(InlineKeyboardButton::make(
                '🔌 مدیریت وضعیت اشتراک', callback_data: 'full:service:view:'.$orderId,
            ));
        }
        if (in_array($order->intent_state, ['processing', 'needs_review'], true)) {
            $lines[] = '';
            $lines[] = 'در صورت نامشخص‌بودن نتیجه، فقط وضعیت سرویس موجود بررسی می‌شود.';
            $buttons->addRow(InlineKeyboardButton::make(
                '🔎 بررسی وضعیت سرویس',
                callback_data: 'full:prepare:'.$orderId.':reconcile',
            ));
        }
        $buttons->addRow(InlineKeyboardButton::make(
            '🔄 تازه‌سازی', callback_data: 'full:view:'.$orderId,
        ));
        $buttons->addRow(InlineKeyboardButton::make(
            '◀️ سفارش‌ها', callback_data: $order->intent_state === 'completed' ? 'full:completed' : 'full:list',
        ));
        $this->render($bot, implode("\n", $lines), $buttons);
    }

    public function prepare(Nutgram $bot, string $id, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $order = $this->id($id);
        if ($order === null || $mode !== 'reconcile') {
            $this->render($bot, 'درخواست معتبر نیست.', $this->home());

            return;
        }
        try {
            $this->reviews->authorizeRequest($root, $order, $mode === 'reconcile');
        } catch (Throwable) {
            $this->render($bot, 'این سفارش برای عملیات انتخاب‌شده آماده نیست.', $this->home());

            return;
        }
        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($root, $token), ['id' => $order, 'mode' => $mode], now()->addMinutes(3));
        $this->render($bot,
            '⚠️ تأیید بررسی وضعیت سفارش #'.$order."\n\n".'فقط وضعیت سرویس موجود بررسی می‌شود؛ سرویس جدیدی ساخته نخواهد شد.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید', callback_data: 'full:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'full:view:'.$order)),
        );
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->render($bot, 'درخواست معتبر نیست.', $this->home());

            return;
        }
        $key = $this->key($root, $token);
        $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key): mixed {
            $value = Cache::get($key);
            if (is_array($value)) {
                Cache::forget($key);
            }

            return $value;
        });

        if (! is_array($state) || ! is_int($state['id'] ?? null)
            || ($state['mode'] ?? null) !== 'reconcile') {
            $this->render($bot, 'این تأیید قبلاً استفاده شده یا منقضی است.', $this->home());

            return;
        }
        $order = $state['id'];
        try {
            $this->reviews->authorizeRequest($root, $order, $state['mode'] === 'reconcile');
            FulfillPaidPlanOrderJob::dispatch($order, $state['mode'] === 'reconcile', $root);
            $this->render($bot,
                '✅ درخواست سفارش #'.$order.' ثبت شد.'."\n\nنتیجه را از فهرست سفارش‌ها بررسی کنید.",
                $this->home());
        } catch (Throwable) {
            $this->render($bot, 'ثبت درخواست ممکن نشد؛ وضعیت سفارش را دوباره بررسی کنید.', $this->home());
        }
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

    private function id(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function key(int $operatorId, string $token): string
    {
        return 'op03:fulfillment:'.$operatorId.':'.$token;
    }

    private function status(string $state): string
    {
        return match ($state) {
            'pending' => 'آماده بررسی',
            'processing' => 'در حال فعال‌سازی',
            'needs_review' => 'نیازمند بررسی',
            'completed' => '✅ فعال‌سازی ثبت شد',
            default => 'نامشخص',
        };
    }

    private function safe(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 60);
    }

    private function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ سفارش‌ها', callback_data: 'full:list',
        ));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}
