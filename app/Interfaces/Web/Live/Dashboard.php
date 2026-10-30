<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Sales\Application\ManageResellerServiceLabels;
use App\Modules\Sales\Application\ReadResellerSalesReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
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

    private const SECTIONS = ['overview', 'services', 'credit', 'reports'];

    public function showSection(string $section): void
    {
        if (in_array($section, self::SECTIONS, true)) {
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

    public function render(): View
    {
        $userId = Auth::id();
        if (! is_int($userId) || ! app(ResolveSalesBuyer::class)->isReseller($userId)) {
            throw new AuthorizationException;
        }

        $data = [
            'name' => Auth::user()->name,
            'wallet' => null,
            'counts' => null,
            'services' => [],
            'labels' => [],
            'nextServiceCursor' => null,
            'report' => null,
            'readError' => false,
        ];

        try {
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
