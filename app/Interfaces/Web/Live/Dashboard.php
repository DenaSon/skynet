<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Interfaces\Telegram\Support\SubscriptionQrPng;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\GetMySubscription;
use App\Modules\Provisioning\Application\GetSubscriptionLink;
use App\Modules\Provisioning\Application\GetSubscriptionUsage;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Provisioning\Application\ManageResellerSubscriptionStatus;
use App\Modules\Provisioning\Jobs\ChangeResellerSubscriptionStatusJob;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\CreatePendingRenewalOrder;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\OrderReader;
use App\Modules\Sales\Application\PlanPriceQuote;
use App\Modules\Sales\Application\PlanSummary;
use App\Modules\Sales\Application\PrepareRenewalDraft;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use App\Modules\Sales\Application\RegisterPendingResellerOrder;
use App\Modules\Sales\Application\SettleWalletOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    #[Locked]
    public ?string $serviceStatusConfirmToken = null;

    #[Locked]
    public ?string $serviceStatusConfirmMode = null;

    public ?string $serviceStatusError = null;

    public ?string $serviceStatusNotice = null;

    /** Connection URL and QR are credentials, never in the list or automatic render. */
    #[Locked]
    public ?string $serviceSubscriptionUrl = null;

    #[Locked]
    public ?string $serviceQrDataUri = null;

    public ?string $serviceConnectionError = null;

    #[Locked]
    public bool $editingServiceLabel = false;

    public string $serviceLabelInput = '';

    public ?string $serviceLabelError = null;

    public ?string $serviceLabelNotice = null;

    /** Non-payable owner-verified preview; the browser may not edit pricing. */
    #[Locked]
    public ?array $renewalPreview = null;

    #[Locked]
    public ?string $renewalConfirmToken = null;

    #[Locked]
    public ?int $renewalConfirmAmountRials = null;

    #[Locked]
    public ?int $renewalConfirmBalanceRials = null;

    public ?string $renewalError = null;

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
        $this->forgetServiceStatusConfirmation($this->resellerId());

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
            self::logSafeFailure('service_usage', $exception);
            $this->serviceUsageError = 'اطلاعات به‌روز سرویس در دسترس نیست. لطفاً دوباره بررسی کنید.';
        }
    }

    /** Prepare a fresh owner-scoped, three-minute, one-use confirmation. */
    public function prepareServiceStatus(string $mode): void
    {
        $buyerId = $this->resellerId();
        $this->forgetServiceStatusConfirmation($buyerId);
        $this->serviceStatusNotice = null;
        $this->serviceStatusError = null;

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null
            || ! in_array($mode, ['enable', 'disable'], true)) {
            $this->serviceStatusError = 'ابتدا سرویس و وضعیت فعلی آن را بررسی کنید.';

            return;
        }

        try {
            app(ManageResellerSubscriptionStatus::class)->authorizeChange(
                $buyerId, $this->selectedServiceOrderId, $mode,
            );
            $token = bin2hex(random_bytes(16));
            Cache::put(self::serviceStatusKey($buyerId, $this->selectedServiceOrderId, $token), [
                'mode' => $mode,
            ], now()->addMinutes(3));
            $this->serviceStatusConfirmToken = $token;
            $this->serviceStatusConfirmMode = $mode;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->serviceStatusError = 'تغییر وضعیت این سرویس امکان‌پذیر نیست. وضعیت را دوباره بررسی کنید.';
        }
    }

    /** Queue exactly one attempt, never call the Provider inside a Livewire request. */
    public function confirmServiceStatus(): void
    {
        $buyerId = $this->resellerId();
        $orderId = $this->selectedServiceOrderId;
        $token = $this->serviceStatusConfirmToken;
        $mode = $this->serviceStatusConfirmMode;
        $this->serviceStatusError = null;
        $this->serviceStatusNotice = null;
        $this->serviceStatusConfirmToken = null;
        $this->serviceStatusConfirmMode = null;

        if ($this->section !== 'services' || $orderId === null || $token === null
            || ! in_array($mode, ['enable', 'disable'], true)
            || ! app(ManageResellerSubscriptionStatus::class)->enabled()) {
            $this->serviceStatusError = 'درخواست منقضی شده است. دوباره وضعیت سرویس را بررسی کنید.';

            return;
        }

        $key = self::serviceStatusKey($buyerId, $orderId, $token);
        $snapshot = Cache::pull($key);
        if (! is_array($snapshot) || ($snapshot['mode'] ?? null) !== $mode) {
            $this->serviceStatusError = 'این تأیید قبلاً استفاده شده یا منقضی شده است.';

            return;
        }

        try {
            app(ManageResellerSubscriptionStatus::class)->authorizeChange($buyerId, $orderId, $mode);
            ChangeResellerSubscriptionStatusJob::dispatch(
                $buyerId, $orderId, $mode, 'live:status:'.$orderId.':'.$buyerId.':'.$token,
            );
            $this->serviceStatusNotice = 'درخواست ثبت شد. برای مشاهده نتیجه، کمی بعد «بررسی وضعیت» را بزنید.';
            $this->serviceLiveUsage = null;
            $this->serviceUsageCheckedAt = null;
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->serviceStatusError = 'ثبت درخواست ممکن نشد. وضعیت سرویس را بررسی کنید و در صورت نیاز با پشتیبانی تماس بگیرید.';
        }
    }

    public function cancelServiceStatus(): void
    {
        $this->forgetServiceStatusConfirmation($this->resellerId());
        $this->serviceStatusNotice = null;
    }

    private static function serviceStatusKey(int $buyerId, int $orderId, string $token): string
    {
        return 'denagate-live:reseller-status:'.$buyerId.':'.$orderId.':'.$token;
    }

    private function forgetServiceStatusConfirmation(int $buyerId): void
    {
        if ($this->selectedServiceOrderId !== null && $this->serviceStatusConfirmToken !== null) {
            Cache::forget(self::serviceStatusKey(
                $buyerId, $this->selectedServiceOrderId, $this->serviceStatusConfirmToken,
            ));
        }
        $this->serviceStatusConfirmToken = null;
        $this->serviceStatusConfirmMode = null;
        $this->serviceStatusError = null;
    }

    /** Explicitly disclose this one currently usable subscription, never in lists. */
    public function revealServiceConnection(): void
    {
        $this->resellerId();
        $this->hideServiceConnection();

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null) {
            $this->serviceDetailError = 'ابتدا سرویس خود را انتخاب کنید.';

            return;
        }

        $viewer = SubscriptionViewer::web();
        $detail = app(GetMySubscription::class)->handle($viewer, $this->selectedServiceOrderId);
        if (! $detail->canRequestLink) {
            $this->serviceConnectionError = 'اطلاعات اتصال این سرویس فعلاً در دسترس نیست.';

            return;
        }

        try {
            // Existing Action validates owner, exact remote identity and usable status,
            // before and after one GET. Do not log credential-bearing exceptions.
            $link = app(GetSubscriptionLink::class)->handle($viewer, $this->selectedServiceOrderId);
            $png = app(SubscriptionQrPng::class)->render($link->subscriptionUrl);

            $this->serviceSubscriptionUrl = $link->subscriptionUrl;
            $this->serviceQrDataUri = 'data:image/png;base64,'.base64_encode($png);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->serviceConnectionError = 'دریافت اطلاعات اتصال امکان‌پذیر نیست. لطفاً دوباره تلاش کنید.';
        }
    }

    /** Clear secrets from the next browser snapshot as well as the view. */
    public function hideServiceConnection(): void
    {
        $this->resellerId();
        $this->serviceSubscriptionUrl = null;
        $this->serviceQrDataUri = null;
        $this->serviceConnectionError = null;
    }

    /** Load only the current owner's optional customer name. */
    public function editServiceLabel(): void
    {
        $buyerId = $this->resellerId();
        $this->serviceLabelError = null;
        $this->serviceLabelNotice = null;
        if ($this->section !== 'services' || $this->selectedServiceOrderId === null) {
            $this->serviceDetailError = 'ابتدا سرویس خود را انتخاب کنید.';

            return;
        }

        $detail = app(GetMySubscription::class)->handle(
            SubscriptionViewer::web(), $this->selectedServiceOrderId,
        );
        if (! in_array($detail->summary->status, ['active', 'provisioning', 'needs_review'], true)) {
            $this->serviceLabelError = 'ویرایش نام برای این سرویس امکان‌پذیر نیست.';

            return;
        }

        $this->serviceLabelInput = app(ManageResellerServiceLabels::class)
            ->get($buyerId, $this->selectedServiceOrderId) ?? '';
        $this->editingServiceLabel = true;
    }

    /** Saves through the existing transaction-locked owner-scoped Action. */
    public function saveServiceLabel(): void
    {
        $buyerId = $this->resellerId();
        $this->serviceLabelError = null;
        $this->serviceLabelNotice = null;

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null
            || ! $this->editingServiceLabel) {
            $this->serviceLabelError = 'ابتدا نام مشتری را برای ویرایش باز کنید.';

            return;
        }

        try {
            $label = trim($this->serviceLabelInput);
            app(ManageResellerServiceLabels::class)->save(
                $buyerId, $this->selectedServiceOrderId, $label === '' ? null : $label,
            );
            $this->serviceLabelInput = '';
            $this->editingServiceLabel = false;
            $this->serviceLabelNotice = 'نام مشتری ذخیره شد.';
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException) {
            $this->serviceLabelError = 'نام مشتری باید حداکثر ۶۰ نویسه و بدون نویسه‌های نامعتبر باشد.';
        }
    }

    public function cancelServiceLabel(): void
    {
        $this->resellerId();
        $this->editingServiceLabel = false;
        $this->serviceLabelInput = '';
        $this->serviceLabelError = null;
        $this->serviceLabelNotice = null;
    }

    /** Read-only draft: fresh published price and owner/provider eligibility. */
    public function previewServiceRenewal(): void
    {
        $buyerId = $this->resellerId();
        $this->renewalError = null;
        $this->renewalPreview = null;
        $this->forgetRenewalConfirmation($buyerId);

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null) {
            $this->serviceDetailError = 'ابتدا سرویس خود را انتخاب کنید.';

            return;
        }

        try {
            $existing = app(OrderReader::class)->latestRenewalForSource($buyerId, $this->selectedServiceOrderId);
            if ($existing !== null && in_array($existing->status, ['pending_payment', 'paid'], true)) {
                $this->renewalError = 'ابتدا وضعیت سفارش تمدید قبلی را بررسی کنید.';

                return;
            }

            $draft = app(PrepareRenewalDraft::class)->handle(
                SubscriptionViewer::web(), $this->selectedServiceOrderId,
            );
            $this->renewalPreview = [
                'source' => $draft->sourceOrderId,
                'name' => $draft->planName,
                'days' => $draft->durationDays,
                'gb' => $draft->trafficGb,
                'amount' => $draft->priceRials,
            ];
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->renewalError = 'در حال حاضر امکان محاسبه تمدید نیست. لطفاً دوباره تلاش کنید.';
        }
    }

    /** Recheck fresh price and ownership before converting preview to an order. */
    public function createServiceRenewal(): void
    {
        $buyerId = $this->resellerId();
        $this->renewalError = null;
        $this->forgetRenewalConfirmation($buyerId);
        $source = $this->selectedServiceOrderId;
        $previous = $this->renewalPreview;
        if ($this->section !== 'services' || $source === null || $previous === null
            || ($previous['source'] ?? null) !== $source || config('sales.renewal_enabled', false) !== true) {
            $this->renewalError = 'ابتدا جزئیات و مبلغ تمدید را بررسی کنید.';

            return;
        }

        try {
            $draft = app(PrepareRenewalDraft::class)->handle(SubscriptionViewer::web(), $source);
            if ($draft->priceRials !== ($previous['amount'] ?? null)
                || $draft->durationDays !== ($previous['days'] ?? null)
                || $draft->trafficGb !== ($previous['gb'] ?? null)
                || $draft->planName !== ($previous['name'] ?? null)) {
                $this->renewalPreview = null;
                $this->renewalError = 'قیمت یا مشخصات تمدید تغییر کرده است. دوباره پیش‌فاکتور بگیرید.';

                return;
            }

            // Existing locked Action rechecks eligibility, price and prior order,
            // and binds this purchase to the original subscription (no CREATE).
            $order = app(CreatePendingRenewalOrder::class)->handle(SubscriptionViewer::web(), $source);
            $this->renewalPreview = null;
            if ($order->totalPriceRials !== $draft->priceRials) {
                // A changed/pending order is not paid; show canonical amount before confirmation.
                $this->renewalError = 'مبلغ سفارش تغییر کرده است. پیش از پرداخت آن را بررسی کنید.';
            }
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->renewalPreview = null;
            $this->renewalError = 'ثبت تمدید امکان‌پذیر نیست. مشخصات سرویس را دوباره بررسی کنید.';
        }
    }

    /** Five-minute single-use snapshot, backed by same wallet ledger as LIVE-3C. */
    public function prepareRenewalPayment(): void
    {
        $buyerId = $this->resellerId();
        $this->forgetRenewalConfirmation($buyerId);
        $this->renewalError = null;

        if ($this->section !== 'services' || $this->selectedServiceOrderId === null
            || config('sales.renewal_enabled', false) !== true
            || config('sales.wallet_settlement_enabled') !== true) {
            $this->renewalError = 'پرداخت تمدید فعلاً در دسترس نیست.';

            return;
        }

        $order = app(OrderReader::class)->latestRenewalForSource($buyerId, $this->selectedServiceOrderId);
        if ($order === null || $order->status !== 'pending_payment') {
            $this->renewalError = 'سفارش تمدید در انتظار پرداخت پیدا نشد.';

            return;
        }

        $balance = app(ViewWallet::class)->handle($buyerId)->balanceRials;
        if ($balance < $order->totalPriceRials) {
            $this->renewalError = 'اعتبار شما کافی نیست. ابتدا اعتبار خود را افزایش دهید.';

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put(self::walletConfirmationKey($buyerId, $order->id, $token), [
            'amount' => $order->totalPriceRials,
            'balance' => $balance,
        ], now()->addMinutes(5));
        $this->renewalConfirmToken = $token;
        $this->renewalConfirmAmountRials = $order->totalPriceRials;
        $this->renewalConfirmBalanceRials = $balance;
    }

    public function confirmRenewalPayment(): void
    {
        $buyerId = $this->resellerId();
        $this->renewalError = null;
        $source = $this->selectedServiceOrderId;
        $token = $this->renewalConfirmToken;

        if ($this->section !== 'services' || $source === null || $token === null) {
            $this->renewalError = 'ابتدا مبلغ و اعتبار را برای پرداخت بررسی کنید.';

            return;
        }

        $order = app(OrderReader::class)->latestRenewalForSource($buyerId, $source);
        $key = $order === null ? null : self::walletConfirmationKey($buyerId, $order->id, $token);
        $snapshot = $key === null ? null : Cache::pull($key);
        $this->renewalConfirmToken = null;
        $this->renewalConfirmAmountRials = null;
        $this->renewalConfirmBalanceRials = null;

        if ($order === null || ! is_array($snapshot)
            || ! isset($snapshot['amount'], $snapshot['balance'])
            || ! is_int($snapshot['amount']) || ! is_int($snapshot['balance'])) {
            $this->renewalError = 'تأیید پرداخت منقضی شده است. مبلغ را دوباره بررسی کنید.';

            return;
        }

        if ($order->status !== 'pending_payment'
            || $order->totalPriceRials !== $snapshot['amount']
            || app(ViewWallet::class)->handle($buyerId)->balanceRials !== $snapshot['balance']) {
            $this->renewalError = 'مبلغ، اعتبار یا وضعیت سفارش تغییر کرده است. دوباره بررسی کنید.';

            return;
        }

        try {
            // Only existing transactional debit and unique :renew fulfillment outbox.
            app(SettleWalletOrder::class)->handle(SubscriptionViewer::web(), $order->id);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (ValidationException $exception) {
            $this->renewalError = match (true) {
                isset($exception->errors()['activation']) => 'پرداخت فعلاً آماده نیست؛ مبلغی برداشت نشد.',
                isset($exception->errors()['plan']) => 'مشخصات سرویس تغییر کرده است؛ مبلغی برداشت نشد.',
                default => 'پرداخت انجام نشد. وضعیت سفارش و اعتبار را دوباره بررسی کنید.',
            };
        } catch (Throwable $exception) {
            self::logSafeFailure('renewal_payment', $exception);
            $this->renewalError = 'وضعیت پرداخت مشخص نیست. ابتدا وضعیت سفارش و اعتبارتان را بررسی کنید.';
        }
    }

    public function cancelRenewal(): void
    {
        $this->forgetRenewalConfirmation($this->resellerId());
        $this->forgetServiceStatusConfirmation($this->resellerId());
        $this->serviceStatusNotice = null;
        $this->renewalPreview = null;
        $this->renewalError = null;
    }

    private function forgetRenewalConfirmation(int $buyerId): void
    {
        if ($this->selectedServiceOrderId !== null && $this->renewalConfirmToken !== null) {
            $order = app(OrderReader::class)->latestRenewalForSource($buyerId, $this->selectedServiceOrderId);
            if ($order !== null) {
                Cache::forget(self::walletConfirmationKey($buyerId, $order->id, $this->renewalConfirmToken));
            }
        }

        $this->renewalConfirmToken = null;
        $this->renewalConfirmAmountRials = null;
        $this->renewalConfirmBalanceRials = null;
    }

    private function resetServiceDetail(): void
    {
        $this->forgetRenewalConfirmation($this->resellerId());
        $this->renewalPreview = null;
        $this->renewalError = null;
        $this->selectedServiceOrderId = null;
        $this->serviceDetailError = null;
        $this->serviceLiveUsage = null;
        $this->serviceUsageCheckedAt = null;
        $this->serviceUsageError = null;
        $this->serviceSubscriptionUrl = null;
        $this->serviceQrDataUri = null;
        $this->serviceConnectionError = null;
        $this->editingServiceLabel = false;
        $this->serviceLabelInput = '';
        $this->serviceLabelError = null;
        $this->serviceLabelNotice = null;
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
            self::logSafeFailure('wallet_payment', $exception);
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

    /**
     * Provider exceptions may include a secret URL or upstream response body.
     * Log only fixed operation keys and the class; never serialize the
     * exception, stack trace, user input, order ID or credentials.
     */
    private static function logSafeFailure(string $operation, Throwable $exception): void
    {
        Log::warning('denagate_live.operation_failed', [
            'operation' => $operation,
            'exception_type' => $exception::class,
        ]);
    }

    public function render(): View
    {
        $userId = $this->resellerId();

        // Resolve the existing Sales Bot's configured public username only.
        // A malformed/missing value must never become a dynamic external link.
        $configuredBot = config('telegram.sales.bot_username');
        $botUsername = is_string($configuredBot) ? ltrim(trim($configuredBot), '@') : '';
        $salesBotUrl = config('telegram.sales.enabled') === true
            && preg_match('/\\A[A-Za-z][A-Za-z0-9_]{4,31}\\z/D', $botUsername) === 1
                ? 'https://t.me/'.$botUsername
                : null;
        $topupBotUrl = $salesBotUrl !== null && config('billing.topups_enabled') === true
            ? $salesBotUrl.'?start=live_topup'
            : null;

        $data = [
            'name' => Auth::user()->name,
            'wallet' => null,
            'topupBotUrl' => $topupBotUrl,
            'salesBotUrl' => $salesBotUrl,
            'counts' => null,
            'services' => [],
            'labels' => [],
            'selectedService' => null,
            'serviceStatusCanResume' => false,
            'resellerServiceStatusEnabled' => false,
            'renewalAvailable' => false,
            'renewalOrder' => null,
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
                    $data['resellerServiceStatusEnabled'] = app(ManageResellerSubscriptionStatus::class)->enabled();
                    if ($data['resellerServiceStatusEnabled'] === true
                        && ($this->serviceLiveUsage['status'] ?? null) === 'disabled') {
                        try {
                            $data['serviceStatusCanResume'] = app(ManageResellerSubscriptionStatus::class)
                                ->canResume($userId, $detail->summary->orderId);
                        } catch (Throwable) {
                            $data['serviceStatusCanResume'] = false;
                        }
                    }
                    $data['renewalAvailable'] = app(PrepareRenewalDraft::class)->available(
                        SubscriptionViewer::web(), $detail->summary->orderId,
                    );
                    $data['renewalOrder'] = app(OrderReader::class)->latestRenewalForSource(
                        $userId, $detail->summary->orderId,
                    );
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
            self::logSafeFailure('dashboard_read', $exception);
            $data['readError'] = true;
        }

        return view('live.dashboard', $data);
    }
}
