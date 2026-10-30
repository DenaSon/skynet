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
use App\Modules\Sales\Application\PlanSummary;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
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
        $this->resellerId();
        $this->quoteRequested = true;
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
        ];

        try {
            if ($this->section === 'shop') {
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
                                    $data['planQuote'] = app(CalculatePlanQuote::class)->handle(
                                        $userId, $selected->id, $days, $gb,
                                    );
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
