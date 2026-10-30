<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
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

    private const SECTIONS = ['overview', 'services', 'credit', 'reports', 'shop'];

    public function showSection(string $section): void
    {
        if (in_array($section, self::SECTIONS, true)) {
            if ($this->section !== $section) {
                $this->resetPlanSelection();
            }
            $this->section = $section;
            $this->serviceCursor = null;
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
        if ($cursor > 0 && $cursor <= 999_999_999_999_999) {
            $this->serviceCursor = $cursor;
        }
    }

    public function firstServices(): void
    {
        $this->serviceCursor = null;
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

    public function backToPlans(): void
    {
        $this->resellerId();
        $this->resetPlanSelection();
    }

    private function resetPlanSelection(): void
    {
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
        ];

        try {
            if ($this->section === 'shop' && $this->createdOrderId !== null) {
                $data['createdOrder'] = app(OrderReader::class)->findPlan($userId, $this->createdOrderId);
                $data['createdCustomerName'] = app(ManageResellerServiceLabels::class)
                    ->pendingName($userId, $this->createdOrderId);
            } elseif ($this->section === 'shop') {
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
                if ($this->section === 'services') {
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
