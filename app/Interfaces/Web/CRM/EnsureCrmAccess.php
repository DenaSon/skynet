<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCrmAccess
{
    public function __construct(
        private AssertCatalogManagementAccess $assertCatalogManagementAccess,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actorUserId = Auth::guard('web')->id();

        if (! is_numeric($actorUserId) || (int) $actorUserId < 1) {
            session()->put('url.intended', route('crm.dashboard'));

            return redirect()->route('login');
        }

        // Re-load the actor and re-check the full SuperAdminTrust contract on every request.
        $this->assertCatalogManagementAccess->handle((int) $actorUserId);

        return $next($request);
    }
}
