<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
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
        private ViewWallet $wallets,
        private SettleWalletOrder $walletPayments,
    ) {}

    /** The old preview callback is still accepted, but no extra confirmation is shown. */
    public function preview(Nutgram $bot, string $id, string $days, string $gb): void
    {
        $this->purchase($bot, $id, $days, $gb);
    }

    /** Home and catalog selections both go straight to the same payment view. */
    public function choosePreset(Nutgram $bot, string $id): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null || ! $this->positive($id)) {
            $this->notice($bot, 'این پلن در دسترس نیست.');

            return;
        }

        try {
            $entry = $this->presets->find($buyer, (int) $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این پلن تغییر کرده است؛ لطفاً دوباره انتخاب کنید.');

            return;
        }

        $quote = $entry->quote;
        $this->purchase($bot, (string) $quote->planId, (string) $quote->durationDays, (string) ($quote->trafficGb ?? 0));
    }

    /** One deliberate new-purchase intent per owned source order, replay-safe. */
    public function newPurchase(Nutgram $bot, string $sourceId): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null || ! $this->positive($sourceId)) {
            $this->notice($bot, 'سفارش در دسترس نیست.');

            return;
        }
        try {
            $source = $this->orders->findPlan($buyer, (int) $sourceId);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'سفارش در دسترس نیست.');

            return;
        }
        $this->purchase($bot, (string) $source->planId, (string) $source->durationDays,
            (string) ($source->trafficGb ?? 0), sourceOrderId: $source->id);
    }

    private function purchase(Nutgram $bot, string $id, string $days, string $gb, ?int $sourceOrderId = null): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }
        if (! $this->positive($id) || ! $this->positive($days)
            || preg_match('/\\A(?:0|[1-9][0-9]{0,3})\\z/D', $gb) !== 1) {
            $this->notice($bot, 'مشخصات انتخاب معتبر نیست.');

            return;
        }

        // Never create an unpaid order when no genuine payment destination exists.
        $bankAvailable = config('sales.manual_bank_review_enabled', false) === true
            && app(ManualPaymentUi::class)->cardNumber() !== null;
        if (! $bankAvailable && config('sales.wallet_settlement_enabled', false) !== true) {
            $this->notice($bot, 'پرداخت فعلاً در دسترس نیست.');

            return;
        }

        try {
            $quote = $this->quotes->handle($buyer, (int) $id, (int) $days, $gb === '0' ? null : (int) $gb);
            if ($quote->mode === 'preset') {
                $this->presets->find($buyer, $quote->planId);
            } else {
                $this->builders->find($buyer, $quote->planId);
            }
            if ($this->squads->verifiedSquadForCheckout($quote->planId) === null) {
                $this->notice($bot, 'این پلن هنوز آماده خرید نیست. پلن دیگری انتخاب کنید.');

                return;
            }
            if (! $bankAvailable && ($this->walletBalance($buyer) ?? 0) < $quote->totalPriceRials) {
                $this->notice($bot, 'موجودی کیف پول برای این پلن کافی نیست و پرداخت بانکی فعلاً در دسترس نیست.');

                return;
            }

            // Lock the buyer's selection; durable order snapshots remain the
            // source of truth even when the database cache has been cleared.
            $key = TelegramBotContext::current()->cacheKey(
                'telegram:sales:purchase:'.$buyer.':'.$quote->planId.':'.$quote->durationDays.':'.($quote->trafficGb ?? 0),
            );
            $order = Cache::lock($key, 10)->block(3, function () use ($buyer, $quote, $sourceOrderId): OrderDetails {
                if ($sourceOrderId === null) {
                    $existing = $this->orders->pendingForQuote($buyer, $quote);
                    if ($existing !== null) {
                        return $existing;
                    }
                }

                // Stable intent for the button on this particular owned order.
                // The *next* order has a new button, so intentional repeat purchases work.
                $key = $sourceOrderId === null
                    ? bin2hex(random_bytes(16))
                    : substr(hash('sha256', 'sales-new-order:'.$buyer.':'.$sourceOrderId), 0, 32);

                return $this->create->handle($buyer, $quote, $key);
            });
        } catch (AuthorizationException|ValidationException|LockTimeoutException) {
            $this->notice($bot, 'قیمت یا دسترسی تغییر کرده است. لطفاً پلن را دوباره انتخاب کنید.');

            return;
        }

        $this->showOrder($bot, $order);
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
            $keyboard->addRow(InlineKeyboardButton::make(
                $order->status === 'pending_payment' ? '▶️ سفارش #'.$order->id.' · ادامه پرداخت' : '📄 سفارش #'.$order->id,
                callback_data: 'sales:checkout:view:'.$order->id,
            ));
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

    /** First confirmation step: read-only, expires after five minutes. */
    public function walletPreview(Nutgram $bot, string $id): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null || ! $this->positive($id)
            || config('sales.wallet_settlement_enabled', false) !== true) {
            $this->notice($bot, 'پرداخت کیف پول فعلاً در دسترس نیست.');

            return;
        }

        try {
            $order = $this->orders->findPlan($buyer, (int) $id);
            $balance = $this->walletBalance($buyer);
            $receipt = $this->payments->latestReceiptStatus(
                SubscriptionViewer::telegram((int) $this->private->id($bot)), $order->id,
            );
            if ($order->status !== 'pending_payment' || $receipt === 'submitted'
                || $balance === null || $balance < $order->totalPriceRials) {
                throw ValidationException::withMessages(['wallet' => 'Wallet is insufficient or unavailable.']);
            }
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'پرداخت کیف پول ممکن نیست؛ موجودی یا وضعیت سفارش را بررسی کنید.');

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->walletTokenKey($buyer, $order->id), [
            'token' => $token, 'amount' => $order->totalPriceRials, 'balance' => $balance,
        ], now()->addMinutes(5));

        $this->render($bot,
            '<b>💳 تأیید پرداخت از کیف پول</b>'
            ."\n\nسفارش #{$order->id}"
            ."\nپلن: ".TelegramUi::escape($this->safe($order->productName))
            ."\nمبلغ قابل برداشت: <b>".TelegramUi::toman($order->totalPriceRials).' تومان</b>'
            ."\nموجودی فعلی: <b>".TelegramUi::toman($balance).' تومان</b>'
            ."\nموجودی پس از خرید: <b>".TelegramUi::toman($balance - $order->totalPriceRials).' تومان</b>'
            ."\n\n<blockquote>با تأیید، کل مبلغ یک‌بار از کیف پول شما کسر می‌شود.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید و پرداخت', callback_data: 'sales:checkout:wallet:confirm:'.$order->id.':'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت به سفارش', callback_data: 'sales:checkout:view:'.$order->id))
                ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home')),
            html: true,
        );
    }

    /** A consumed confirmation is never allowed to debit another order. */
    public function walletConfirm(Nutgram $bot, string $id, string $token): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null || ! $this->positive($id)
            || preg_match('/\\A[a-f0-9]{32}\\z/D', $token) !== 1) {
            $this->notice($bot, 'تأیید پرداخت معتبر نیست.');

            return;
        }

        $key = $this->walletTokenKey($buyer, (int) $id);
        $expected = Cache::get($key);
        if (! is_array($expected) || ! hash_equals((string) ($expected['token'] ?? ''), $token)) {
            $this->notice($bot, 'این تأیید پرداخت منقضی یا قبلاً استفاده شده است.');

            return;
        }
        Cache::forget($key);

        try {
            $order = $this->orders->findPlan($buyer, (int) $id);
            if ($order->status !== 'pending_payment'
                || $order->totalPriceRials !== $expected['amount']
                || $this->walletBalance($buyer) !== $expected['balance']) {
                throw ValidationException::withMessages(['wallet' => 'Balance or order changed.']);
            }
            $paid = $this->walletPayments->handle(
                SubscriptionViewer::telegram((int) $this->private->id($bot)), (int) $id,
            );
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'موجودی یا وضعیت سفارش تغییر کرده است. دوباره از صفحه سفارش اقدام کنید.');

            return;
        }

        $this->showOrder($bot, $paid);
    }

    private function walletTokenKey(int $buyer, int $orderId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:sales:walletpay:'.$buyer.':'.$orderId);
    }

    private function walletBalance(int $buyer): ?int
    {
        if (config('sales.wallet_settlement_enabled', false) !== true) {
            return null;
        }
        try {
            return $this->wallets->handle($buyer)->balanceRials;
        } catch (AuthorizationException) {
            return null;
        }
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

        $balance = $this->walletBalance($order->buyerUserId);
        $walletEligible = $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && $balance !== null && $balance >= $order->totalPriceRials;
        if (! $walletEligible && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && TelegramBotContext::current()->key === TelegramBotContext::SALES
            && config('sales.manual_bank_review_enabled', false) === true) {
            app(ManualPaymentUi::class)->start($bot, (string) $order->id);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        if ($walletEligible) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '💳 پرداخت از کیف پول', callback_data: 'sales:checkout:wallet:'.$order->id, style: ButtonStyle::SUCCESS,
            ));
        }
        if ($order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && config('sales.manual_bank_review_enabled', false) === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $latestReceipt === 'rejected' ? '🧾 ارسال رسید جدید' : '🧾 ارسال رسید پرداخت',
                callback_data: 'sales:payment:start:'.$order->id,
            ));
        }
        if ($order->planId !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🛒 خرید جدید همین پلن',
                callback_data: 'sales:checkout:new:'.$order->id,
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

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard, bool $html = false): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard, $html ? 'HTML' : null);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard, parse_mode: $html ? 'HTML' : null);
    }
}
