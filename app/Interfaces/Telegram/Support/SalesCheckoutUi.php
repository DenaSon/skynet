<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CreatePendingPlanOrder;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\OrderDetails;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
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
        private ManageResellerServiceLabels $customerLabels,
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
        if (DB::table('sales_renewal_orders')->where('sales_order_id', $source->id)->exists()) {
            $this->notice($bot, 'برای تمدید دوباره، از بخش سرویس‌های من اقدام کنید.');

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

        // Reseller purchases use wallet settlement exclusively; receipts are
        // reserved for wallet top-ups, not new subscription purchases.
        if (app(ResolveSalesBuyer::class)->isReseller($buyer)
            && config('sales.wallet_settlement_enabled', false) !== true) {
            $this->notice($bot, 'پرداخت سرویس برای پنل فروش فعلاً در دسترس نیست.');

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
            if (! $bankAvailable && ! app(ResolveSalesBuyer::class)->isReseller($buyer)
                && ($this->walletBalance($buyer) ?? 0) < $quote->totalPriceRials) {
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
            $this->notice($bot, 'این پیش‌فاکتور منقضی یا قبلاً استفاده شده است. پلن را دوباره انتخاب و مبلغ را بررسی کنید.');

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
            $this->customerLabels->assertPendingDetails($buyer, $order->id);
            $balance = $this->walletBalance($buyer);
            $receipt = $this->payments->latestReceiptStatus(
                SubscriptionViewer::telegram((int) $this->private->id($bot)), $order->id,
            );
            if ($order->status !== 'pending_payment' || $receipt === 'submitted'
                || $balance === null || $balance < $order->totalPriceRials) {
                throw ValidationException::withMessages(['wallet' => 'Wallet is insufficient or unavailable.']);
            }
        } catch (AuthorizationException|ValidationException $error) {
            if ($error instanceof ValidationException && isset($error->errors()['customer'])) {
                $this->viewOrder($bot, $id);

                return;
            }
            $this->notice($bot, 'موجودی برای این سفارش کافی نیست، یا رسید بانکی در انتظار بررسی است. از صفحه سفارش وضعیت را بررسی کنید.');

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
                ->addRow(InlineKeyboardButton::make('✅ تأیید و پرداخت', callback_data: 'sales:walletpay:confirm:'.$order->id.':'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('◀️ لغو و بازگشت', callback_data: 'sales:walletpay:cancel:'.$order->id))
                ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home')),
            html: true,
        );
    }

    /** Explicit cancellation invalidates even previously displayed buttons. */
    public function walletCancel(Nutgram $bot, string $id): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null || ! $this->positive($id)) {
            $this->notice($bot, 'سفارش در دسترس نیست.');

            return;
        }

        Cache::forget($this->walletTokenKey($buyer, (int) $id));
        $this->viewOrder($bot, $id);
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
            $this->notice($bot, 'این دکمه منقضی یا قبلاً استفاده شده است. از سفارش‌های من دوباره وارد پرداخت شوید.');

            return;
        }
        Cache::forget($key);

        try {
            $order = $this->orders->findPlan($buyer, (int) $id);
            $this->customerLabels->assertPendingDetails($buyer, $order->id);
            if ($order->status !== 'pending_payment'
                || $order->totalPriceRials !== $expected['amount']
                || $this->walletBalance($buyer) !== $expected['balance']) {
                throw ValidationException::withMessages(['wallet' => 'Balance or order changed.']);
            }
            $paid = $this->walletPayments->handle(
                SubscriptionViewer::telegram((int) $this->private->id($bot)), (int) $id,
            );
        } catch (AuthorizationException|ValidationException $error) {
            $message = match (true) {
                $error instanceof ValidationException && isset($error->errors()['customer']) => 'ابتدا نام و شماره موبایل مشتری را تکمیل کنید؛ مبلغی برداشت نشد.',
                $error instanceof ValidationException && isset($error->errors()['activation']) => 'پرداخت فعلاً آماده نیست؛ مبلغی از کیف پول شما برداشت نشد. کمی بعد دوباره اقدام کنید.',
                $error instanceof ValidationException && isset($error->errors()['plan']) => 'مشخصات این پلن تغییر کرده یا خرید آن متوقف شده است؛ مبلغی برداشت نشد. از بخش خرید، پلن را دوباره انتخاب کنید.',
                default => 'مبلغ، موجودی یا وضعیت سفارش تغییر کرده است؛ برداشت انجام نشد. از صفحه سفارش دوباره اقدام کنید.',
            };
            $this->notice($bot, $message);

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

    /** Existing pending order gets a private display name before payment options appear. */
    private function beginCustomerName(Nutgram $bot, int $orderId): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        app(SalesSubscriptionsUi::class)->clearCustomerLabelDraft($buyer);
        app(SalesSubscriptionsUi::class)->clearCustomerSearch($buyer);
        Cache::put($this->customerNameKey($buyer), ['order_id' => $orderId], now()->addMinutes(10));
        $this->render($bot, "<b>👤 نام و نام خانوادگی مشتری</b>\n\n<b>مرحله ۱ از ۲</b>\nنام و نام خانوادگی مشتری را در یک پیام ارسال کنید (حداکثر ۶۰ کاراکتر)."
            ."\nاین نام در سرویس‌های شما نمایش داده می‌شود و شناسه فنی اشتراک تغییر نمی‌کند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:checkout:name:cancel'))
                ->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders')),
            true,
        );
    }

    /** Consume a name only while its owner has a live checkout draft. */
    public function customerNameText(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || str_starts_with(trim($text), '/')) {
            return false;
        }
        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            return false;
        }
        // Redis can return numeric cache scalars as strings. New drafts use a
        // structured payload; accept old numeric drafts until their TTL expires.
        $pending = Cache::get($this->customerNameKey($actor->userId));
        $orderId = is_array($pending) ? ($pending['order_id'] ?? null) : $pending;
        if ((! is_int($orderId) && ! is_string($orderId))
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', (string) $orderId) !== 1) {
            return false;
        }
        $orderId = (int) $orderId;

        try {
            $this->customerLabels->savePending($actor->userId, $orderId, $text);
            $order = $this->orders->findPlan($actor->userId, $orderId);
        } catch (ValidationException $error) {
            if (isset($error->errors()['label'])) {
                $this->notice($bot, 'نام و نام خانوادگی را در یک خط و با فاصله وارد کنید (حداکثر ۶۰ کاراکتر).');

                return true;
            }
            $this->clearCustomerName($actor->userId);
            $this->notice($bot, 'این سفارش برای ثبت نام مشتری در دسترس نیست.');

            return true;
        } catch (AuthorizationException) {
            $this->clearCustomerName($actor->userId);
            $this->notice($bot, 'دسترسی ثبت نام مشتری فعال نیست.');

            return true;
        }

        $this->clearCustomerName($actor->userId);
        $this->showOrder($bot, $order);

        return true;
    }

    public function cancelCustomerName(Nutgram $bot): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }
        $this->clearCustomerName($buyer);
        $this->notice($bot, 'ثبت نام مشتری لغو شد؛ پرداختی انجام نشده است.');
    }

    public function clearCustomerName(int $buyerId): void
    {
        Cache::forget($this->customerNameKey($buyerId));
    }

    private function customerNameKey(int $buyerId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:sales:customer_name:'.$buyerId);
    }

    /** Second step of the existing reseller purchase: customer mobile. */
    private function beginCustomerPhone(Nutgram $bot, int $orderId): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }

        $this->clearCustomerName($buyer);
        app(SalesSubscriptionsUi::class)->clearCustomerPhoneDraft($buyer);
        Cache::put($this->customerPhoneKey($buyer), ['order_id' => $orderId], now()->addMinutes(10));
        $this->render($bot,
            "<b>📱 شماره موبایل مشتری</b>\n\n<b>مرحله ۲ از ۲</b>\n"
            ."شماره موبایل مشتری را ارسال کنید.\nمثال: <code>09123456789</code>\n"
            .'این شماره فقط برای مدیریت مشتریان شما استفاده می‌شود.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:checkout:phone:cancel'))
                ->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders')),
            true,
        );
    }

    public function customerPhoneText(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || str_starts_with(trim($text), '/')) {
            return false;
        }
        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            return false;
        }
        $draft = Cache::get($this->customerPhoneKey($actor->userId));
        $orderId = is_array($draft) ? ($draft['order_id'] ?? null) : null;
        if ((! is_int($orderId) && ! is_string($orderId))
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', (string) $orderId) !== 1) {
            return false;
        }

        try {
            $this->customerLabels->savePendingPhone($actor->userId, (int) $orderId, $text);
            $order = $this->orders->findPlan($actor->userId, (int) $orderId);
        } catch (ValidationException $error) {
            if (isset($error->errors()['phone'])) {
                $this->notice($bot, 'شماره معتبر نیست. نمونه: 09123456789');

                return true;
            }
            $this->clearCustomerPhone($actor->userId);
            $this->notice($bot, 'این سفارش برای ثبت شماره مشتری در دسترس نیست.');

            return true;
        } catch (AuthorizationException) {
            $this->clearCustomerPhone($actor->userId);
            $this->notice($bot, 'دسترسی ثبت شماره مشتری فعال نیست.');

            return true;
        }

        $this->clearCustomerPhone($actor->userId);
        $this->showOrder($bot, $order);

        return true;
    }

    public function cancelCustomerPhone(Nutgram $bot): void
    {
        $buyer = $this->buyer($bot);
        if ($buyer === null) {
            return;
        }
        $this->clearCustomerPhone($buyer);
        $this->notice($bot, 'ثبت شماره مشتری لغو شد؛ پرداختی انجام نشده است.');
    }

    public function clearCustomerPhone(int $buyerId): void
    {
        Cache::forget($this->customerPhoneKey($buyerId));
    }

    private function customerPhoneKey(int $buyerId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:sales:checkout_phone:'.$buyerId);
    }

    private function showOrder(Nutgram $bot, OrderDetails $order): void
    {
        $actor = $this->resolve->handle((int) $bot->user()->id);
        $isReseller = $actor !== null && in_array('reseller', $actor->roles, true);
        $latestReceipt = $this->payments->latestReceiptStatus(
            SubscriptionViewer::telegram((int) $bot->user()->id),
            $order->id,
        );
        // Older submitted receipts remain visible; never obstruct an existing review.
        if ($isReseller && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && ! DB::table('sales_renewal_orders')->where('sales_order_id', $order->id)->exists()) {
            if ($this->customerLabels->pendingName($order->buyerUserId, $order->id) === null) {
                $this->beginCustomerName($bot, $order->id);

                return;
            }
            if ($this->customerLabels->pendingPhone($order->buyerUserId, $order->id) === null) {
                $this->beginCustomerPhone($bot, $order->id);

                return;
            }
        }
        $sourceRenewal = DB::table('sales_renewal_orders')
            ->where('sales_order_id', $order->id)->value('source_order_id');

        $status = match (true) {
            $order->status === 'fulfilled' => $sourceRenewal === null ? '✅ اشتراک فعال شد' : '✅ اشتراک تمدید شد',
            $order->status === 'paid' => '✅ پرداخت تأیید شده؛ در انتظار فعال‌سازی',
            $latestReceipt === 'submitted' => '🕘 رسید ثبت شد؛ در انتظار بررسی مدیر',
            $latestReceipt === 'rejected' => '❌ رسید تأیید نشده؛ امکان ارسال رسید جدید',
            default => '🟠 در انتظار پرداخت',
        };

        $balance = $this->walletBalance($order->buyerUserId);
        $walletEligible = $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && $balance !== null && $balance >= $order->totalPriceRials;
        if (! $isReseller && ! $walletEligible && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
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
        if (! $isReseller && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && config('sales.manual_bank_review_enabled', false) === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $latestReceipt === 'rejected' ? '🧾 ارسال رسید جدید' : '🧾 ارسال رسید پرداخت',
                callback_data: 'sales:payment:start:'.$order->id,
            ));
        }
        if ($isReseller && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
            && ! $walletEligible) {
            if (config('billing.topups_enabled', false) === true) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    '➕ افزایش موجودی کیف پول', callback_data: 'sales:wallet:topup',
                ));
            }
            $keyboard->addRow(InlineKeyboardButton::make('💰 کیف پول من', callback_data: 'sales:wallet'));
        }
        if ($order->planId !== null && $sourceRenewal === null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🛒 خرید جدید همین پلن',
                callback_data: 'sales:checkout:new:'.$order->id,
            ));
        }
        if ($order->status === 'fulfilled') {
            $keyboard->addRow(InlineKeyboardButton::make('📦 مشاهده اشتراک',
                callback_data: 'sales:subs:view:'.($sourceRenewal ?? $order->id).':0'));
        } elseif ($sourceRenewal !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('📦 سرویس اصلی',
                callback_data: 'sales:subs:view:'.$sourceRenewal.':0'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📋 سفارش‌های من', callback_data: 'sales:checkout:orders'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        $this->render($bot, ($sourceRenewal === null ? "✅ سفارش #{$order->id}" : "🔄 سفارش تمدید #{$order->id}")
            ."\n\nپلن: ".$this->safe($order->productName)
            ."\nحجم: ".($order->trafficGb === null ? 'نامحدود' : $order->trafficGb.' گیگابایت')
            ."\nمدت: {$order->durationDays} روز"
            ."\n".($isReseller ? 'قیمت مخصوص پنل فروش' : 'مبلغ ثبت‌شده').': '
            .TelegramUi::toman($order->totalPriceRials).' تومان'
            ."\nوضعیت: {$status}"
            ."\n\n".($isReseller && $order->status === 'pending_payment' && $latestReceipt !== 'submitted'
                ? ($walletEligible
                    ? 'موجودی کیف پول کافی است. برای خرید، «پرداخت از کیف پول» را انتخاب کنید.'
                    : 'برای خرید سرویس از کیف پول استفاده کنید. موجودی کافی نیست یا پرداخت کیف پول موقتاً غیرفعال است؛ از بخش کیف پول موجودی را افزایش دهید.')
                : $this->orderHint($order, $latestReceipt)),
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
            return 'رسید دریافت شده است. تا بررسی واریز توسط مدیر، موجودی و وضعیت پرداخت تغییر نمی‌کند.';
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
