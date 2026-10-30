<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanPriceQuote;
use App\Modules\Sales\Application\PlanSummary;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use App\Modules\Sales\Application\RegisterPendingResellerOrder;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/** Reseller-only read presenter; existing Application Actions remain authoritative. */
#[Layout('live.layout')]
final class Dashboard extends Component
{
    public string $section = 'overview';

    public int $reportDays = 30;

    public ?int $serviceCursor = null;

    #[Locked]
    public ?int $selectedServiceOrderId = null;

    /** @var array{status:string,expireAt:string,usedBytes:int,limitBytes:int}|null */
    #[Locked]
    public ?array $serviceLiveUsage = null;

    #[Locked]
    public ?string $serviceUsageCheckedAt = null;

    public ?string $serviceDetailError = null;

    public ?string $serviceUsageError = null;

    public ?int $selectedPlanId = null;

    public string $daysInput = '';

    public string $gbInput = '';

    public bool $quoteRequested = false;

    public bool $planUnavailable = false;

    public string $customerUsername = '';

    public ?string $orderError = null;

    #[Locked]
    public ?string $confirmedQuoteHash = null;

    #[Locked]
    public ?string $orderIntentKey = null;

    #[Locked]
    public ?int $createdOrderId = null;

    #[Locked]
    public ?string $walletConfirmToken = null;

    #[Locked]
    public ?int $walletConfirmAmountRials = null;

    #[Locked]
    public ?int $walletConfirmBalanceRials = null;

    public ?string $walletError = null;

    private const SECTIONS = ['overview', 'services', 'credit', 'reports', 'shop'];

    public function showSection(string $section): void
    {
        if (in_array($section, self::SECTIONS, true)) {
            if ($this->section !== $section) {
                $this->resetPlanSelection();
            }
            $this->section = $section;
            $this->serviceCursor = null;
            $this->resetServiceDetail();
        }
    }

    public function selectReportDays(int $days): void
    {
        if (in_array($days, [7, 30], true)) {
            $this->reportDays = $days;
        }
    }

    public function nextServices(int $cursor): void
    {
        $this->resellerId();
        if ($cursor > 0 && $cursor <= 999_999_999_999_999) {
            $this->resetServiceDetail();
            $this->serviceCursor = $cursor;
        }
    }

    public function firstServices(): void
    {
        $this->resellerId();
        $this->resetServiceDetail();
        $this->serviceCursor = null;
    }

    /** Only a subscription visible on the current owned service page may be selected. */
    public function openService(int $orderId): void
    {
        $this->resellerId();
        $this->resetServiceDetail();
        if ($this->section !== 'services' || $orderId < 1) {
            $this->serviceDetailError = 'این سرویس در دسترس نیست.';

            return;
        }

        $viewer = SubscriptionViewer::web();
        $page = app(ListMyServices::class)->handle($viewer, 6, $this->serviceCursor);
        $visible = false;
        foreach ($page->items as $service) {
            if ($service->orderId === $orderId) {
                $visible = true;
                break;
            }
        }

        if (! $visible) {
            $this->serviceDetailError = 'این سرویس در دسترس نیست.';

            return;
        }

        try {
            // Read-only owner verification; no Provider access on opening a detail.
            app(GetMySubscription::class)->handle($viewer, $orderId);
            $this->selectedServiceOrderId = $orderId;
        } catch (ValidationException) {
            $this->serviceDetailError = 'این سرویس در دسترس نیست.';
        }
    }

    public function backToServices(): void
    {
        $this->resellerId();
        $this->resetServiceDetail();
    }

    /** One owner-verified Provider GET per explicit request; no background polling. */
    public function refreshServiceUsage(): void
    {
        $this->resellerId();
        $this->serviceLiveUsage = null;
        $this->serviceUsageCheckedAt = null;
        $this->serviceUsageError = null;

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null) {
            $this->serviceUsageError = 'ابتدا سرویس خود را انتخاب کنید.';

            return;
        }

        $viewer = SubscriptionViewer::web();
        $detail = app(GetMySubscription::class)->handle($viewer, $this->selectedServiceOrderId);
        if (! $detail->canRequestLink) {
            $this->serviceUsageError = 'دریافت وضعیت فعلی این سرویس امکان‌پذیر نیست.';

            return;
        }

        try {
            $usage = app(GetSubscriptionUsage::class)->handle($viewer, $this->selectedServiceOrderId);
            $expired = (new \DateTimeImmutable($usage->expireAt))->getTimestamp() <= time();
            $exhausted = $usage->limitBytes > 0 && $usage->usedBytes >= $usage->limitBytes;
            $status = match (true) {
                $expired, $usage->status === 'EXPIRED' => 'expired',
                $usage->status === 'DISABLED' => 'disabled',
                $exhausted => 'exhausted',
                $usage->status === 'LIMITED' => 'limited',
                $usage->status === 'ACTIVE' => 'active',
                default => 'unknown',
            };
            $this->serviceLiveUsage = [
                'status' => $status,
                'expireAt' => $usage->expireAt,
                'usedBytes' => $usage->usedBytes,
                'limitBytes' => $usage->limitBytes,
            ];
            $this->serviceUsageCheckedAt = now()->toIso8601String();
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // Provider exceptions are not rendered or serialized to the browser.
            report($exception);
            $this->serviceUsageError = 'اطلاعات به‌روز سرویس در دسترس نیست. لطفاً دوباره بررسی کنید.';
        }
    }

    private function resetServiceDetail(): void
    {
        $this->selectedServiceOrderId = null;
        $this->serviceDetailError = null;
        $this->serviceLiveUsage = null;
        $this->serviceUsageCheckedAt = null;
        $this->serviceUsageError = null;
    }

    /** Select only a currently quoted preset or a published reseller builder. */
    public function selectPlan(int $planId): void
    {
        $userId = $this->resellerId();
        $this->resetPlanSelection();

        if ($planId < 1) {
            $this->planUnavailable = true;

            return;
        }

        try {
            $plan = app(ListQuotedPresets::class)->find($userId, $planId)->plan;
        } catch (ValidationException) {
            try {
                $plan = app(ListResellerBuilders::class)->find($userId, $planId);
            } catch (ValidationException) {
                $this->planUnavailable = true;

                return;
            }
        }

        $this->section = 'shop';
        $this->selectedPlanId = $plan->id;
        $this->daysInput = (string) ($plan->durationDays ?? $plan->minDays);
        $this->gbInput = $plan->kind === 'time' ? '' : (string) ($plan->trafficGb ?? $plan->minGb);
    }

    public function previewQuote(): void
    {
        $buyerId = $this->resellerId();
        if ($this->createdOrderId !== null) {
            return;
        }

        $this->quoteRequested = true;
        $this->confirmedQuoteHash = null;
        $this->orderIntentKey = null;
        $this->orderError = null;

        if ($this->selectedPlanId === null) {
            return;
        }

        try {
            $quote = $this->selectedQuote($buyerId);
            $this->confirmedQuoteHash = self::quoteHash($quote);
            $this->orderIntentKey = bin2hex(random_bytes(16));
        } catch (ValidationException) {
            // The current read-only render displays the contextual quote error.
        }
    }

    /** Explicit customer-confirmed order creation; payment is always separate. */
    public function confirmOrder(): void
    {
        $buyerId = $this->resellerId();
        if ($this->createdOrderId !== null) {
            return;
        }

        $this->orderError = null;
        if ($this->section !== 'shop' || $this->confirmedQuoteHash === null
            || $this->orderIntentKey === null || $this->selectedPlanId === null) {
            $this->orderError = 'ابتدا پیش‌فاکتور را بررسی و تأیید کنید.';

            return;
        }

        try {
            $quote = $this->selectedQuote($buyerId);
            if (! hash_equals($this->confirmedQuoteHash, self::quoteHash($quote))) {
                throw ValidationException::withMessages(['quote' => 'Quote changed.']);
            }

            $order = app(RegisterPendingResellerOrder::class)->handle(
                $buyerId, $quote, $this->orderIntentKey, $this->customerUsername,
            );
            $this->createdOrderId = $order->id;
        } catch (ValidationException $exception) {
            if (isset($exception->errors()['label'])) {
                $this->orderError = 'نام کاربری مشتری را در یک خط و حداکثر ۶۰ نویسه وارد کنید.';
            } else {
                $this->orderError = 'ثبت سفارش امکان‌پذیر نیست. مشخصات و قیمت را دوباره بررسی کنید.';
                $this->confirmedQuoteHash = null;
                $this->orderIntentKey = null;
                $this->quoteRequested = false;
            }
        }
    }

    /** Open only a currently owned managed-plan order, including after app reload. */
    public function openOrder(int $orderId): void
    {
        $buyerId = $this->resellerId();
        $this->resetPlanSelection();
        $this->section = 'shop';

        try {
            $order = app(OrderReader::class)->findNewPlan($buyerId, $orderId);
            $this->createdOrderId = $order->id;
        } catch (ValidationException) {
            $this->orderError = 'سفارش در دسترس نیست.';
        }
    }

    /** Five-minute, server-backed wallet confirmation; this never debits. */
    public function prepareWalletPayment(): void
    {
        $buyerId = $this->resellerId();
        $this->forgetWalletConfirmation($buyerId);
        $this->walletError = null;

        if ($this->section !== 'shop' || $this->createdOrderId === null
            || config('sales.wallet_settlement_enabled') !== true) {
            $this->walletError = 'پرداخت از اعتبار فعلاً در دسترس نیست.';

            return;
        }

        try {
            $order = app(OrderReader::class)->findNewPlan($buyerId, $this->createdOrderId);
            if ($order->status !== 'pending_payment') {
                $this->walletError = 'این سفارش دیگر در انتظار پرداخت نیست.';

                return;
            }

            app(ManageResellerServiceLabels::class)->assertPendingDetails($buyerId, $order->id);
            $balance = app(ViewWallet::class)->handle($buyerId)->balanceRials;
            if ($balance < $order->totalPriceRials) {
                $this->walletError = 'اعتبار شما کافی نیست. ابتدا اعتبار خود را افزایش دهید.';

                return;
            }

            $token = bin2hex(random_bytes(16));
            Cache::put(self::walletConfirmationKey($buyerId, $order->id, $token), [
                'amount' => $order->totalPriceRials,
                'balance' => $balance,
            ], now()->addMinutes(5));
            $this->walletConfirmToken = $token;
            $this->walletConfirmAmountRials = $order->totalPriceRials;
            $this->walletConfirmBalanceRials = $balance;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException) {
            $this->walletError = 'اطلاعات سفارش کامل نیست یا وضعیت آن تغییر کرده است.';
        }
    }

    /** Only an explicit fresh confirmation may invoke the existing settlement Action. */
    public function confirmWalletPayment(): void
    {
        $buyerId = $this->resellerId();
        $orderId = $this->createdOrderId;
        $token = $this->walletConfirmToken;
        $this->walletError = null;

        if ($this->section !== 'shop' || $orderId === null || $token === null) {
            $this->walletError = 'ابتدا مبلغ و اعتبار خود را برای پرداخت بررسی کنید.';

            return;
        }

        $key = self::walletConfirmationKey($buyerId, $orderId, $token);
        $expected = Cache::pull($key);
        $this->walletConfirmToken = null;
        $this->walletConfirmAmountRials = null;
        $this->walletConfirmBalanceRials = null;

        if (! is_array($expected)
            || ! isset($expected['amount'], $expected['balance'])
            || ! is_int($expected['amount']) || ! is_int($expected['balance'])) {
            $this->walletError = 'تأیید پرداخت منقضی شده است. مبلغ را دوباره بررسی کنید.';

            return;
        }

        try {
            $order = app(OrderReader::class)->findNewPlan($buyerId, $orderId);
            if ($order->status !== 'pending_payment'
                || $order->totalPriceRials !== $expected['amount']
                || app(ViewWallet::class)->handle($buyerId)->balanceRials !== $expected['balance']) {
                $this->walletError = 'مبلغ، اعتبار یا وضعیت سفارش تغییر کرده است. دوباره بررسی کنید.';

                return;
            }

            app(SettleWalletOrder::class)->handle(SubscriptionViewer::web(), $orderId);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->walletError = match (true) {
                isset($exception->errors()['activation']) => 'پرداخت فعلاً آماده نیست؛ مبلغی برداشت نشد.',
                isset($exception->errors()['customer']) => 'نام کاربری مشتری را در سفارش تکمیل کنید.',
                isset($exception->errors()['plan']) => 'مشخصات سرویس تغییر کرده است. مبلغی برداشت نشد.',
                default => 'پرداخت انجام نشد. وضعیت سفارش و اعتبار را دوباره بررسی کنید.',
            };
        } catch (Throwable $exception) {
            report($exception);
            $this->walletError = 'وضعیت پرداخت مشخص نیست. پیش از اقدام دوباره، وضعیت سفارش و اعتبارتان را بررسی کنید.';
        }
    }

    public function cancelWalletPayment(): void
    {
        $this->forgetWalletConfirmation($this->resellerId());
    }

    private static function walletConfirmationKey(int $buyerId, int $orderId, string $token): string
    {
        return 'denagate-live:wallet:'.$buyerId.':'.$orderId.':'.$token;
    }

    private function forgetWalletConfirmation(int $buyerId): void
    {
        if ($this->createdOrderId !== null && $this->walletConfirmToken !== null) {
            Cache::forget(self::walletConfirmationKey($buyerId, $this->createdOrderId, $this->walletConfirmToken));
        }

        $this->walletConfirmToken = null;
        $this->walletConfirmAmountRials = null;
        $this->walletConfirmBalanceRials = null;
        $this->walletError = null;
    }

    public function backToPlans(): void
    {
        $this->resellerId();
        $this->resetPlanSelection();
    }

    private function resetPlanSelection(): void
    {
        $this->forgetWalletConfirmation($this->resellerId());
        $this->selectedPlanId = null;
        $this->daysInput = '';
        $this->gbInput = '';
        $this->quoteRequested = false;
        $this->planUnavailable = false;
        $this->customerUsername = '';
        $this->orderError = null;
        $this->confirmedQuoteHash = null;
        $this->orderIntentKey = null;
        $this->createdOrderId = null;
    }

    private function resellerId(): int
    {
        $userId = Auth::id();
        if (! is_int($userId) || ! app(ResolveSalesBuyer::class)->isReseller($userId)) {
            throw new AuthorizationException;
        }

        return $userId;
    }

    private static function positiveNumber(string $value): ?int
    {
        return preg_match('/\\A[1-9][0-9]{0,3}\\z/D', $value) === 1 ? (int) $value : null;
    }

    /** Recheck catalog visibility and the actual published price on every confirmation. */
    private function selectedQuote(int $buyerId): PlanPriceQuote
    {
        if ($this->selectedPlanId === null || $this->selectedPlanId < 1) {
            throw ValidationException::withMessages(['plan' => 'No selected plan.']);
        }

        try {
            $plan = app(ListQuotedPresets::class)->find($buyerId, $this->selectedPlanId)->plan;
        } catch (ValidationException) {
            $plan = app(ListResellerBuilders::class)->find($buyerId, $this->selectedPlanId);
        }

        $days = self::positiveNumber($this->daysInput);
        $gb = $plan->kind === 'time' ? null : self::positiveNumber($this->gbInput);

        if ($days === null || ($plan->kind === 'time' && $this->gbInput !== '')
            || ($plan->kind !== 'time' && $gb === null)) {
            throw ValidationException::withMessages(['quantity' => 'Invalid duration or traffic.']);
        }

        return app(CalculatePlanQuote::class)->handle($buyerId, $plan->id, $days, $gb);
    }

    private static function quoteHash(PlanPriceQuote $quote): string
    {
        return hash('sha256', json_encode(get_object_vars($quote), JSON_THROW_ON_ERROR));
    }

    public function render(): View
    {
        $userId = $this->resellerId();

        $data = [
            'name' => Auth::user()->name,
            'wallet' => null,
            'counts' => null,
            'services' => [],
            'labels' => [],
            'selectedService' => null,
            'nextServiceCursor' => null,
            'report' => null,
            'readError' => false,
            'catalogPresets' => [],
            'catalogBuilders' => [],
            'selectedPlan' => null,
            'planQuote' => null,
            'quoteError' => null,
            'createdOrder' => null,
            'createdCustomerName' => null,
            'recentOrders' => [],
        ];

        try {
            if ($this->section === 'shop' && $this->createdOrderId !== null) {
                $data['createdOrder'] = app(OrderReader::class)->findNewPlan($userId, $this->createdOrderId);
                $data['createdCustomerName'] = app(ManageResellerServiceLabels::class)
                    ->pendingName($userId, $this->createdOrderId);
            } elseif ($this->section === 'shop') {
                if ($this->selectedPlanId === null) {
                    $data['recentOrders'] = app(OrderReader::class)->recentNewPlans($userId);
                }
                $presets = app(ListQuotedPresets::class)->handle($userId);
                $builders = app(ListResellerBuilders::class)->handle($userId);
                $data['catalogPresets'] = $presets;
                $data['catalogBuilders'] = $builders;

                if ($this->selectedPlanId !== null) {
                    $selected = null;
                    foreach ($presets as $entry) {
                        if ($entry->plan->id === $this->selectedPlanId) {
                            $selected = $entry->plan;
                            break;
                        }
                    }
                    if ($selected === null) {
                        foreach ($builders as $builder) {
                            if ($builder->id === $this->selectedPlanId) {
                                $selected = $builder;
                                break;
                            }
                        }
                    }

                    if (! $selected instanceof PlanSummary) {
                        $data['quoteError'] = 'این پلن دیگر قابل ارائه نیست. لطفاً پلن دیگری انتخاب کنید.';
                    } else {
                        $data['selectedPlan'] = $selected;

                        if ($this->quoteRequested) {
                            $days = self::positiveNumber($this->daysInput);
                            $gb = $selected->kind === 'time' ? null : self::positiveNumber($this->gbInput);

                            if ($days === null || ($selected->kind === 'time' && $this->gbInput !== '')
                                || ($selected->kind !== 'time' && $gb === null)) {
                                $data['quoteError'] = 'مقدار روز یا حجم معتبر نیست. لطفاً دوباره بررسی کنید.';
                            } else {
                                try {
                                    $quote = app(CalculatePlanQuote::class)->handle(
                                        $userId, $selected->id, $days, $gb,
                                    );
                                    if ($this->confirmedQuoteHash !== null
                                        && ! hash_equals($this->confirmedQuoteHash, self::quoteHash($quote))) {
                                        $data['quoteError'] = 'مبلغ یا مشخصات تغییر کرده است. لطفاً دوباره پیش‌فاکتور بگیرید.';
                                    } else {
                                        $data['planQuote'] = $quote;
                                    }
                                } catch (ValidationException) {
                                    $data['quoteError'] = 'برای این انتخاب قیمت قابل ارائه نیست. مقادیر را تغییر دهید یا پلن دیگری انتخاب کنید.';
                                }
                            }
                        }
                    }
                }
            }

            if ($this->section === 'overview' || $this->section === 'credit') {
                $data['wallet'] = app(ViewWallet::class)->handle($userId);
            }

            if ($this->section === 'overview' || $this->section === 'services') {
                $services = app(ListMyServices::class);
                $data['counts'] = $services->counts(SubscriptionViewer::web());
                if ($this->section === 'services' && $this->selectedServiceOrderId !== null) {
                    $detail = app(GetMySubscription::class)->handle(
                        SubscriptionViewer::web(), $this->selectedServiceOrderId,
                    );
                    $data['selectedService'] = $detail;
                    $data['labels'] = app(ManageResellerServiceLabels::class)->forOrders(
                        $userId, [$detail->summary->orderId],
                    );
                } elseif ($this->section === 'services') {
                    $cursor = $this->serviceCursor;
                    if ($cursor !== null && ($cursor < 1 || $cursor > 999_999_999_999_999)) {
                        $cursor = null;
                    }
                    $page = $services->handle(SubscriptionViewer::web(), 6, $cursor);
                    $data['services'] = $page->items;
                    $data['nextServiceCursor'] = $page->nextBeforeOrderId;
                    $data['labels'] = app(ManageResellerServiceLabels::class)->forOrders(
                        $userId, array_map(static fn ($service): int => $service->orderId, $page->items),
                    );
                }
            }

            if ($this->section === 'overview' || $this->section === 'reports') {
                $days = in_array($this->reportDays, [7, 30], true) ? $this->reportDays : 30;
                $data['report'] = app(ReadResellerSalesReport::class)->handle(
                    $userId, $this->section === 'overview' ? 30 : $days,
                );
            }
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            $data['readError'] = true;
        }

        return view('live.dashboard', $data);
    }
}
