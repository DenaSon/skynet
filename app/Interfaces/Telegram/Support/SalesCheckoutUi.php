<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** SB-03 Sales-only checkout. Never receives or verifies payment or calls Remnawave. */
final readonly class SalesCheckoutUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $resolve,
        private CalculatePlanQuote $quotes,
        private ListQuotedPresets $presets,
        private ListResellerBuilders $builders,
        private CheckPlanSquadReadiness $squads,
        private PlanCheckoutTokens $tokens,
        private CreatePendingPlanOrder $create,
        private OrderReader $orders,
        private ManualBankPayments $payments,
    ) {}

    public function preview(Nutgram $bot, string $id, string $days, string $gb): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        if ($this->positive($id) === false || $this->positive($days) === false
            || preg_match('/\A(?:0|[1-9][0-9]{0,3})\z/D', $gb) !== 1) {
            $this->notice($bot, 'مشخصات انتخاب معتبر نیست.');

            return;
        }

        try {
            $quote = $this->quotes->handle($buyer, (int) $id, (int) $days, $gb === '0' ? null : (int) $gb);

            // Price authorization alone is not enough: only presets or
            // reseller-only builders currently offered to this buyer.
            if ($quote->mode === 'preset') {
                $this->presets->find($buyer, $quote->planId);
            } else {
                $this->builders->find($buyer, $quote->planId);
            }

            if ($this->squads->verifiedSquadForCheckout($quote->planId) === null) {
                $this->notice($bot, 'ثبت سفارش این پلن هنوز فعال نشده است. می‌توانید قیمت آن را ببینید یا پلن دیگری انتخاب کنید.');

                return;
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این پلن یا تعرفه تغییر کرده است. لطفاً دوباره انتخاب کنید.');

            return;
        }

        $token = $this->tokens->issue($buyer, $quote);
        $price = TelegramUi::toman($quote->totalPriceRials);

        $this->render($bot, "🧾 بررسی سفارش\n\nپلن: ".$this->safe($quote->planName)
            ."\nحجم: ".($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت')
            ."\nمدت: {$quote->durationDays} روز"
            ."\nقیمت عمومی: ".TelegramUi::toman($quote->publicPriceRials).' تومان'
            ."\nتخفیف: {$quote->discountPercent}٪"
            ."\nمبلغ نهایی: {$price} تومان"
            ."\n\nثبت سفارش هیچ پرداخت یا فعال‌سازی خودکاری انجام نمی‌دهد.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ثبت سفارش', callback_data: 'sales:checkout:commit:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'menu:plan-catalog')),
        );
    }

    public function commit(Nutgram $bot, string $token): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        $expected = $this->tokens->peek($buyer, $token);
        if ($expected === null) {
            $this->notice($bot, 'پیش‌فاکتور معتبر نیست یا منقضی شده است. قیمت را دوباره بررسی کنید.');

            return;
        }

        try {
            $order = $this->create->handle($buyer, $expected, $token);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'قیمت، دسترسی یا وضعیت سرویس تغییر کرده است. لطفاً دوباره استعلام بگیرید.');

            return;
        }

        $this->showOrder($bot, $order);
    }

    public function listOrders(Nutgram $bot): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        try {
            $orders = $this->orders->recentPlans($buyer);
        } catch (AuthorizationException) {
            $this->notice($bot, 'دسترسی مجاز نیست.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['📋 سفارش‌های من', ''];
        foreach ($orders as $order) {
            $lines[] = '#'.$order->id.' — '.$this->safe($order->productName).' — '.TelegramUi::toman($order->totalPriceRials).' تومان';
            $keyboard->addRow(InlineKeyboardButton::make('📄 سفارش #'.$order->id, callback_data: 'sales:checkout:view:'.$order->id));
        }

        if ($orders === []) {
            $lines[] = 'هنوز سفارشی ثبت نکرده‌اید.';
        }

        $keyboard->addRow(InlineKeyboardButton::make('📋 پلن‌ها', callback_data: 'menu:plan-catalog'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function viewOrder(Nutgram $bot, string $id): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        if ($this->positive($id) === false) {
            $this->notice($bot, 'سفارش در دسترس نیست.');

            return;
        }

        try {
            $order = $this->orders->findPlan($buyer, (int) $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'سفارش در دسترس نیست.');

            return;
        }

        $this->showOrder($bot, $order);
    }

    private function showOrder(Nutgram $bot, OrderDetails $order): void
    {
        $latestReceipt = $this->payments->latestReceiptStatus(
            SubscriptionViewer::telegram((int) $bot->user()->id),
            $order->id,
        );

        $status = match (true) {
            $order->status === 'fulfilled' => '✅ اشتراک فعال شد',
            $order->status === 'paid' => '✅ پرداخت تأیید شده؛ در انتظار فعال‌سازی',
            $latestReceipt === 'submitted' => '🕘 رسید در انتظار بررسی',
            $latestReceipt === 'rejected' => '❌ رسید رد شده؛ نیاز به ارسال رسید صحیح',
            default => '🟠 در انتظار پرداخت',
        };

        $keyboard = InlineKeyboardMarkup::make();
        if ($order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && config('sales.manual_bank_review_enabled', false) === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $latestReceipt === 'rejected' ? '🧾 ارسال رسید جدید' : '🧾 ارسال رسید پرداخت',
                callback_data: 'sales:payment:start:'.$order->id,
            ));
        }
        if ($order->status === 'fulfilled') {
            $keyboard->addRow(InlineKeyboardButton::make('📦 مشاهده اشتراک', callback_data: 'sales:subs:view:'.$order->id.':0'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        $this->render($bot, "✅ سفارش #{$order->id}\n\nپلن: ".$this->safe($order->productName)
            ."\nحجم: ".($order->trafficGb === null ? 'نامحدود' : $order->trafficGb.' گیگابایت')
            ."\nمدت: {$order->durationDays} روز"
            ."\nمبلغ ثبت‌شده: ".TelegramUi::toman($order->totalPriceRials).' تومان'
            ."\nوضعیت: {$status}"
            ."\n\n".$this->orderHint($order, $latestReceipt),
            $keyboard,
        );
    }

    private function orderHint(OrderDetails $order, ?string $receipt): string
    {
        if ($order->status === 'fulfilled') {
            return 'اشتراک این سفارش فعال شده است.';
        }
        if ($order->status === 'paid') {
            return 'پرداخت تأیید شده است. سرویس هنوز فعال نشده است.';
        }
        if ($receipt === 'submitted') {
            return 'رسید شما ثبت شده و در انتظار بررسی پرداخت است.';
        }
        if (config('sales.manual_bank_review_enabled', false) !== true) {
            return 'ارسال رسید پرداخت فعلاً در دسترس نیست.';
        }

        return 'پس از پرداخت، رسید را ارسال کنید. تأیید پرداخت فقط با بررسی واقعی بانک انجام می‌شود.';
    }

    private function buyer(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            $this->notice($bot, 'دسترسی مجاز نیست.');

            return null;
        }

        return $actor->userId;
    }

    private function positive(string $value): bool
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1;
    }

    private function safe(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $value));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "🧾 سفارش\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'menu:plan-catalog')));
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
