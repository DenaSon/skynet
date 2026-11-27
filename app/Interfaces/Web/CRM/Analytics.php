<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\ReadCrmAnalytics;
use App\Support\PersianCalendar;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.crm')]
final class Analytics extends Component
{
    public string $period = '30';

    public string $from = '';

    public string $until = '';

    public string $channel = 'all';

    public string $reseller = '';

    /** @var array<string, array<string, mixed>> */
    #[Locked]
    public array $charts = [];

    public function hydrate(AssertCatalogManagementAccess $access): void
    {
        abort_unless(request()->getHost() === config('denagate_crm.host'), 404);
        $access->handle((int) Auth::guard('web')->id());
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'from', 'until', 'channel', 'reseller']);
    }

    public function updatedChannel(): void
    {
        if (! in_array($this->channel, ['all', 'reseller'], true)) {
            $this->reseller = '';
        }
    }

    public function selectReseller(int $id): void
    {
        // The authorized Action validates the ID and current commercial role.
        $this->channel = 'reseller';
        $this->reseller = (string) $id;
    }

    public function render(): View
    {
        $this->charts = [];
        try {
            $data = app(ReadCrmAnalytics::class)->handle((int) Auth::guard('web')->id(), [
                'period' => $this->period,
                'from' => $this->period === 'custom' ? ($this->from ?: null) : null,
                'until' => $this->period === 'custom' ? ($this->until ?: null) : null,
                'channel' => $this->channel,
                'reseller' => $this->reseller === '' ? null : $this->reseller,
            ]);
        } catch (ValidationException) {
            return view('crm.analytics', ['data' => null, 'readFailed' => false]);
        } catch (QueryException) {
            Log::warning('CRM analytics read failed');

            return view('crm.analytics', ['data' => null, 'readFailed' => true]);
        }

        $this->setCharts($data);

        return view('crm.analytics', ['data' => $data]);
    }

    /** @param  array<string, mixed>  $data */
    private function setCharts(array $data): void
    {
        $trend = $data['trend'];
        $labels = array_map(static function (array $point): string {
            $start = PersianCalendar::date($point['start']);
            $end = PersianCalendar::date($point['end']);

            return $start === $end ? $start : $start.' – '.$end;
        }, $trend);

        $money = static fn (array $values): array => array_map(static fn ($value) => $value === null ? null : $value / 10, $values);
        $line = static fn (string $label, array $values, string $color): array => [
            'label' => $label, 'data' => $values, 'borderColor' => $color, 'backgroundColor' => $color,
            'borderWidth' => 2, 'pointRadius' => 2, 'tension' => 0.2, 'spanGaps' => false,
        ];
        $bar = static fn (string $label, array $values, string $color): array => [
            'label' => $label, 'data' => $values, 'backgroundColor' => $color, 'borderRadius' => 4,
        ];

        $financial = [$line('فروش تسویه‌شده (تومان)', $money(array_column($trend, 'salesRials')), '#0F766E')];
        if ($data['cashRials'] !== null) {
            $financial[] = $line('واریزی بانکی (تومان)', $money(array_column($trend, 'cashRials')), '#2563EB');
        }
        $this->charts['financial'] = $this->chart('line', $labels, $financial);
        $this->charts['selected'] = $this->chart('line', $labels, [
            $line('خرید تسویه‌شده (تومان)', $money(array_column($trend, 'selectedRials')), '#0F766E'),
        ]);
        $this->charts['registrations'] = $this->chart('bar', $labels, [
            $bar('مشتریان مستقیم', array_column($trend, 'directRegistrations'), '#60A5FA'),
            $bar('نمایندگان', array_column($trend, 'resellerRegistrations'), '#A78BFA'),
        ], true);
        $this->charts['buyers'] = $this->chart('bar', $labels, [
            $bar('خریداران یکتا در هر نقطه', array_column($trend, 'buyers'), '#14B8A6'),
        ], true);
        if (array_sum($data['channels']) > 0) {
            $this->charts['channels'] = $this->chart('doughnut', ['مستقیم', 'نمایندگی', 'تاریخی نامشخص'], [[
                'label' => 'تومان', 'data' => $money(array_values($data['channels'])),
                'backgroundColor' => ['#60A5FA', '#A78BFA', '#94A3B8'],
            ]]);
        }
        if ($data['newPurchases'] + $data['renewals'] > 0) {
            $this->charts['renewals'] = $this->chart('doughnut', ['خرید جدید', 'تمدید'], [[
                'label' => 'سفارش', 'data' => [$data['newPurchases'], $data['renewals']],
                'backgroundColor' => ['#14B8A6', '#818CF8'],
            ]], true);
        }
        if ($data['top']->isNotEmpty()) {
            $this->charts['resellers'] = $this->chart('bar',
                $data['top']->map(static fn (object $row): string => $row->name.' #'.$row->id)->all(),
                [$bar('خرید تسویه‌شده (تومان)', $money($data['top']->pluck('revenue')->map(static fn ($value): int => (int) $value)->all()), '#A78BFA')],
                horizontal: true,
            );
        }
        if ($data['products']->isNotEmpty()) {
            $this->charts['products'] = $this->chart('bar',
                $data['products']->pluck('product_name')->all(),
                [$bar('تعداد سفارش معتبر', $data['products']->pluck('purchases')->map(static fn ($value): int => (int) $value)->all(), '#60A5FA')],
                true,
                true,
            );
        }
    }

    /**
     * @param  list<string>  $labels
     * @param  list<array<string, mixed>>  $datasets
     * @return array<string, mixed>
     */
    private function chart(string $type, array $labels, array $datasets, bool $integers = false, bool $horizontal = false): array
    {
        $options = [
            'responsive' => true,
            'maintainAspectRatio' => false,
            'animation' => false,
            'plugins' => [
                'legend' => ['rtl' => true, 'textDirection' => 'rtl', 'position' => 'bottom'],
                'tooltip' => ['rtl' => true, 'textDirection' => 'rtl'],
            ],
        ];
        if ($type !== 'doughnut') {
            $options['interaction'] = ['mode' => 'index', 'intersect' => false];
            $options['scales'] = [
                'x' => ['grid' => ['display' => false], 'ticks' => ['maxRotation' => 0, 'autoSkip' => true]],
                'y' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['color' => '#E5EAF0']],
            ];
            if ($horizontal) {
                $options['indexAxis'] = 'y';
                $options['scales']['x']['beginAtZero'] = true;
            }
            if ($integers) {
                $options['scales'][$horizontal ? 'x' : 'y']['ticks']['precision'] = 0;
            }
        }

        return ['type' => $type, 'data' => ['labels' => $labels, 'datasets' => $datasets], 'options' => $options];
    }
}
