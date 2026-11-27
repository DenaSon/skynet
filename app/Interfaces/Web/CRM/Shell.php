<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\ReadCrmDirectory;
use App\Support\PersianCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.crm')]
final class Shell extends Component
{
    use WithPagination;

    #[Locked]
    public string $section = 'dashboard';

    /** @var array<string, mixed> */
    #[Locked]
    public array $salesChart = [];

    /** @var array<string, mixed> */
    #[Locked]
    public array $registrationsChart = [];

    public string $period = '30';

    public string $from = '';

    public string $until = '';

    public string $registeredFrom = '';

    public string $registeredUntil = '';

    public string $search = '';

    public string $status = 'all';

    public string $telegram = 'all';

    public string $purchase = 'all';

    public string $phone = 'all';

    public bool $groupContacts = false;

    public string $reseller = '';

    public string $product = '';

    public string $orderStatus = 'all';

    public string $segment = 'off';

    public string $advancedMode = 'and';

    /** @var list<array{field:string,operator:string,value:string}> */
    public array $advancedConditions = [];

    public function addAdvancedCondition(): void
    {
        if ($this->section === 'customers' && count($this->advancedConditions) < 5) {
            $this->advancedConditions[] = ['field' => 'orders', 'operator' => 'gte', 'value' => '1'];
            $this->resetPage();
        }
    }

    public function removeAdvancedCondition(int $index): void
    {
        if ($this->section === 'customers' && isset($this->advancedConditions[$index])) {
            array_splice($this->advancedConditions, $index, 1);
            $this->resetPage();
        }
    }

    public function updatedAdvancedConditions(mixed $value, ?string $key = null): void
    {
        if ($key === null || ! preg_match('/^(\\d+)\\.field$/', $key, $matches)) {
            return;
        }
        $index = (int) $matches[1];
        if (! isset($this->advancedConditions[$index])) {
            return;
        }
        $this->advancedConditions[$index]['operator'] = 'eq';
        $this->advancedConditions[$index]['value'] = $value === 'last_seen' ? '' : '1';
    }

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'id', 'direction' => 'desc'];

    public function mount(): void
    {
        $this->section = match (request()->route()?->getName()) {
            'crm.customers' => 'customers',
            'crm.resellers' => 'resellers',
            'crm.contacts' => 'contacts',
            default => 'dashboard',
        };
        if ($this->section !== 'dashboard') {
            $this->period = 'all';
        }
        if ($this->section === 'resellers') {
            $this->sortBy = ['column' => 'revenue_rials', 'direction' => 'desc'];
        } elseif ($this->section === 'contacts') {
            $this->sortBy = ['column' => 'order_id', 'direction' => 'desc'];
        }
    }

    public function hydrate(AssertCatalogManagementAccess $access): void
    {
        abort_unless(request()->getHost() === config('denagate_crm.host'), 404);
        $access->handle((int) Auth::guard('web')->id());
    }

    public function updating(string $property): void
    {
        if (! str_starts_with($property, 'paginators')) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['from', 'until', 'registeredFrom', 'registeredUntil', 'search', 'status', 'telegram', 'purchase', 'phone', 'groupContacts', 'reseller', 'product', 'orderStatus', 'advancedMode', 'advancedConditions', 'segment']);
        $this->period = $this->section === 'dashboard' ? '30' : 'all';
        $this->sortBy = match ($this->section) {
            'resellers' => ['column' => 'revenue_rials', 'direction' => 'desc'],
            'contacts' => ['column' => 'order_id', 'direction' => 'desc'],
            default => ['column' => 'id', 'direction' => 'desc'],
        };
        $this->resetPage();
    }

    public function render(): View
    {
        $this->salesChart = [];
        $this->registrationsChart = [];
        try {
            $data = app(ReadCrmDirectory::class)->handle((int) Auth::guard('web')->id(), $this->section, [
                'period' => $this->period,
                'from' => $this->period === 'custom' ? ($this->from ?: null) : null,
                'until' => $this->period === 'custom' ? ($this->until ?: null) : null,
                'registeredFrom' => $this->registeredFrom ?: null,
                'registeredUntil' => $this->registeredUntil ?: null,
                'search' => $this->search,
                'status' => $this->status,
                'telegram' => $this->telegram,
                'purchase' => $this->purchase,
                'phone' => $this->phone,
                'groupContacts' => $this->groupContacts,
                'reseller' => $this->reseller ?: null,
                'product' => $this->product,
                'orderStatus' => $this->orderStatus,
                'segment' => $this->segment,
                'advancedMode' => $this->advancedMode,
                'advancedConditions' => $this->advancedConditions,
                'sort' => $this->sortBy['column'] ?? '',
                'direction' => $this->sortBy['direction'] ?? '',
                'page' => $this->getPage(),
            ]);
        } catch (ValidationException) {
            return view('crm.shell', ['data' => null, 'readFailed' => false]);
        } catch (QueryException) {
            // Do not log SQL bindings containing customer search terms or contacts.
            Log::warning('CRM read failed', ['section' => $this->section]);

            return view('crm.shell', ['data' => null, 'readFailed' => true]);
        }

        if ($this->section === 'dashboard') {
            $this->setTrendCharts($data['trend']);
        }

        return view('crm.shell', ['data' => $data]);
    }

    /** @param  list<array{date:string,revenueRials:int,registrations:int,directRegistrations:int,resellerRegistrations:int}> $trend */
    private function setTrendCharts(array $trend): void
    {
        if ($trend === []) {
            return;
        }

        $labels = array_map(static fn (array $point): string => PersianCalendar::date($point['date']), $trend);
        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'animation' => false,
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => [
                'legend' => ['rtl' => true, 'textDirection' => 'rtl', 'position' => 'bottom'],
                'tooltip' => ['rtl' => true, 'textDirection' => 'rtl'],
            ],
            'scales' => [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxRotation' => 0, 'autoSkip' => true]],
                'y' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['color' => '#E5EAF0']],
            ],
        ];

        $salesOptions = $options;
        $salesOptions['plugins']['legend']['display'] = false;
        $this->salesChart = [
            'type' => 'line',
            'data' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'خرید تسویه‌شده (تومان)',
                    'data' => array_map(static fn (array $point): float => $point['revenueRials'] / 10, $trend),
                    'borderColor' => '#0F766E',
                    'backgroundColor' => 'rgba(20, 184, 166, 0.14)',
                    'fill' => true,
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'tension' => 0.25,
                ]],
            ],
            'options' => $salesOptions,
        ];

        $options['scales']['y']['ticks'] = ['precision' => 0];
        $this->registrationsChart = [
            'type' => 'bar',
            'data' => [
                'labels' => $labels,
                'datasets' => [
                    ['label' => 'مشتریان مستقیم', 'data' => array_column($trend, 'directRegistrations'), 'backgroundColor' => '#60A5FA', 'borderRadius' => 4],
                    ['label' => 'نمایندگان', 'data' => array_column($trend, 'resellerRegistrations'), 'backgroundColor' => '#A78BFA', 'borderRadius' => 4],
                ],
            ],
            'options' => $options,
        ];
    }
}
