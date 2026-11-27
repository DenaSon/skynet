<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\ReadCrmDirectory;
use Illuminate\Support\Facades\Auth;
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

    public function render(): View
    {
        try {
            $data = app(ReadCrmDirectory::class)->handle((int) Auth::guard('web')->id(), $this->section, [
                'period' => $this->period,
                'from' => $this->from ?: null,
                'until' => $this->until ?: null,
                'registeredFrom' => $this->registeredFrom ?: null,
                'registeredUntil' => $this->registeredUntil ?: null,
                'search' => $this->search,
                'status' => $this->status,
                'telegram' => $this->telegram,
                'purchase' => $this->purchase,
                'phone' => $this->phone,
                'reseller' => $this->reseller ?: null,
                'product' => $this->product,
                'orderStatus' => $this->orderStatus,
                'sort' => $this->sortBy['column'] ?? '',
                'direction' => $this->sortBy['direction'] ?? '',
                'page' => $this->getPage(),
            ]);
        } catch (ValidationException) {
            return view('crm.shell', ['data' => null]);
        }

        return view('crm.shell', ['data' => $data]);
    }
}
