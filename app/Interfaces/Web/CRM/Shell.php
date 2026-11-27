<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.crm')]
final class Shell extends Component
{
    public function hydrate(AssertCatalogManagementAccess $access): void
    {
        // Livewire resolves persistent middleware using the update request's host.
        // Never accept a CRM snapshot on a different host or without a trusted actor.
        abort_unless(request()->getHost() === config('denagate_crm.host'), 404);

        $access->handle((int) Auth::guard('web')->id());
    }

    public function render(): View
    {
        return view('crm.shell');
    }
}
