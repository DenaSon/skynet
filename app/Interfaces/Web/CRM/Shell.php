<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\ReadCrmDirectory;
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
        $this->reset(['from', 'until', 'registeredFrom', 'registeredUntil', 'search', 'status', 'telegram', 'purchase', 'phone', 'groupContacts', 'reseller', 'product', 'orderStatus']);
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

        return view('crm.shell', ['data' => $data]);
    }
}
