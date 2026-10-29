<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageFulfilledSubscriptionStatus;
use App\Modules\Provisioning\Jobs\ChangeFulfilledSubscriptionStatusJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** MVP-04: tiny private Super Admin status action on existing fulfilled orders. */
final readonly class AdminSubscriptionStatusUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageFulfilledSubscriptionStatus $statuses,
        private MenuRenderer $menus,
    ) {}

    public function view(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $order = $this->id($id);
        if ($order === null) {
            $this->render($bot, 'سفارش معتبر نیست.', $this->back(0));

            return;
        }
        try {
            $state = $this->statuses->status($root, $order);
        } catch (Throwable) {
            $this->render($bot, 'این اشتراک در دسترس نیست.', $this->back($order));

            return;
        }

        $labels = [
            'active' => '🟢 فعال',
            'disabled' => '⏸️ غیرفعال',
            'review' => '🟡 نیازمند بررسی',
            'unavailable' => '⚪ قابل بررسی نیست',
        ];
        $message = '🔌 مدیریت اشتراک #'.$order."\n\nوضعیت: ".$labels[$state];
        $buttons = InlineKeyboardMarkup::make();

        if ($state === 'review') {
            $message .= "\n\nنتیجه درخواست قبلی نامشخص است؛ فقط وضعیت آن بررسی می‌شود.";
            $buttons->addRow(InlineKeyboardButton::make(
                '🔎 بررسی نتیجه', callback_data: 'full:service:check:'.$order,
            ));
        } elseif (config('services.remnawave.writes_enabled') === true) {
            if ($state === 'active') {
                $buttons->addRow(InlineKeyboardButton::make(
                    '⏸️ غیرفعال‌سازی', callback_data: 'full:service:prepare:'.$order.':disable',
                ));
            } elseif ($state === 'disabled') {
                $buttons->addRow(InlineKeyboardButton::make(
                    '✅ فعال‌سازی مجدد', callback_data: 'full:service:prepare:'.$order.':enable',
                ));
            }
        } else {
            $message .= "\n\nتغییر وضعیت سرویس فعلاً غیرفعال است.";
        }

        if ($state === 'unavailable') {
            $message .= "\n\nبرای مشاهده وضعیت دوباره تلاش کنید.";
        }

        $buttons->addRow(InlineKeyboardButton::make(
            '🔄 تازه‌سازی', callback_data: 'full:service:view:'.$order,
        ))->addRow(InlineKeyboardButton::make(
            '◀️ سفارش', callback_data: 'full:view:'.$order,
        ));
        // Keep the existing fulfillment return path and also provide a simple
        // way back to the customer's support profile.
        $buyer = DB::table('sales_orders')->where('id', $order)->value('buyer_user_id');
        if (is_numeric($buyer) && (int) $buyer > 0) {
            $buttons->addRow(InlineKeyboardButton::make(
                '👤 پرونده مشتری', callback_data: 'users:profile:'.(int) $buyer.':1:a',
            ));
        }
        $this->render($bot, $message, $buttons);
    }

    public function prepare(Nutgram $bot, string $id, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $order = $this->id($id);
        if ($order === null || ! in_array($mode, ['enable', 'disable'], true)) {
            $this->render($bot, 'درخواست معتبر نیست.', $this->back(0));

            return;
        }
        try {
            $this->statuses->authorizeChange($root, $order, $mode);
        } catch (Throwable) {
            $this->render($bot, 'وضعیت اشتراک تغییر کرده یا این عملیات ممکن نیست.', $this->back($order));

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($root, $token), [
            'order' => $order,
            'mode' => $mode,
        ], now()->addMinutes(3));

        $label = $mode === 'disable' ? 'غیرفعال‌سازی' : 'فعال‌سازی مجدد';
        $this->render($bot,
            '⚠️ تأیید نهایی'."\n\n".$label.' اشتراک سفارش #'.$order."\n\nآیا مطمئن هستید؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید', callback_data: 'full:service:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'full:service:view:'.$order)),
        );
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->render($bot, 'درخواست معتبر نیست.', $this->back(0));

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

        if (! is_array($state) || ! is_int($state['order'] ?? null)
            || ! in_array($state['mode'] ?? null, ['enable', 'disable'], true)) {
            $this->render($bot, 'این تأیید قبلاً استفاده شده یا منقضی است.', $this->back(0));

            return;
        }

        $order = $state['order'];
        try {
            $this->statuses->authorizeChange($root, $order, $state['mode']);
            ChangeFulfilledSubscriptionStatusJob::dispatch(
                $order,
                $root,
                $state['mode'],
                'mvp04:'.$order.':'.$token,
            );
            $this->render($bot,
                '✅ درخواست ثبت شد. نتیجه را کمی بعد بررسی کنید.',
                $this->back($order),
            );
        } catch (Throwable) {
            $this->render($bot, 'ثبت درخواست ممکن نشد. وضعیت را دوباره بررسی کنید.', $this->back($order));
        }
    }

    public function check(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $order = $this->id($id);
        if ($order === null) {
            $this->render($bot, 'درخواست معتبر نیست.', $this->back(0));

            return;
        }
        try {
            $this->statuses->authorizeReconcile($root, $order);
            ChangeFulfilledSubscriptionStatusJob::dispatch($order, $root, 'reconcile');
            $this->render($bot,
                '✅ بررسی وضعیت درخواست شد. نتیجه را کمی بعد تازه‌سازی کنید.',
                $this->back($order),
            );
        } catch (Throwable) {
            $this->render($bot, 'بررسی وضعیت این اشتراک فعلاً ممکن نیست.', $this->back($order));
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

    private function key(int $root, string $token): string
    {
        return 'mvp04:subscription:'.$root.':'.$token;
    }

    private function back(int $order): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت', callback_data: $order > 0 ? 'full:service:view:'.$order : 'full:completed',
        ));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }
        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
