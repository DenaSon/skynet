<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\ReadCrmProfile;
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
final class Profile extends Component
{
    use WithPagination;

    #[Locked]
    public int $subjectId;

    #[Locked]
    public bool $reseller = false;

    public string $tab = 'overview';

    public string $period = 'all';

    public string $from = '';

    public string $until = '';

    public string $search = '';

    public string $status = 'all';

    public string $phone = 'all';

    public string $purchase = 'all';

    public bool $groupContacts = false;

    /** @var array<string, string> */
    public array $sortBy = ['column' => 'order_id', 'direction' => 'desc'];

    public function mount(int $id): void
    {
        $this->subjectId = $id;
        $this->reseller = request()->routeIs('crm.resellers.show');
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

    public function updatedTab(): void
    {
        $this->search = '';
        $this->status = 'all';
        $this->phone = 'all';
        $this->purchase = 'all';
        $this->groupContacts = false;
        $this->sortBy = ['column' => 'order_id', 'direction' => 'desc'];
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'from', 'until', 'search', 'status', 'phone', 'purchase', 'groupContacts', 'sortBy']);
        $this->resetPage();
    }

    public function render(): View
    {
        $data = null;
        $error = null;
        try {
            $data = app(ReadCrmProfile::class)->handle((int) Auth::guard('web')->id(), $this->subjectId, $this->reseller, [
                'tab' => $this->tab,
                'period' => $this->period,
                'from' => $this->period === 'custom' ? ($this->from ?: null) : null,
                'until' => $this->period === 'custom' ? ($this->until ?: null) : null,
                'search' => $this->search,
                'status' => $this->status,
                'phone' => $this->phone,
                'purchase' => $this->purchase,
                'groupContacts' => $this->groupContacts,
                'sort' => $this->sortBy['column'] ?? 'order_id',
                'direction' => $this->sortBy['direction'] ?? 'desc',
                'page' => $this->getPage(),
            ]);
        } catch (ValidationException) {
            $error = 'فیلتر یا بازه زمانی معتبر نیست.';
        } catch (QueryException) {
            Log::warning('CRM profile read failed');
            $error = 'دریافت اطلاعات ممکن نشد؛ دوباره تلاش کنید.';
        }

        return view('crm.profile', ['data' => $data, 'error' => $error]);
    }
}
