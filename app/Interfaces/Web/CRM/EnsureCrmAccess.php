<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\TelegramSecondFactor;
use App\Modules\Identity\Infrastructure\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureCrmAccess
{
    public function __construct(
        private AssertCatalogManagementAccess $assertCatalogManagementAccess,
        private TelegramSecondFactor $telegramSecondFactor,
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

        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $user->load('telegramAccount');
        $telegramUserId = $user->telegramAccount?->telegram_user_id;

        if (! is_numeric($telegramUserId)
            || ! $this->telegramSecondFactor->isVerifiedFor($request->session(), (int) $actorUserId, (string) $telegramUserId)) {
            return redirect()->route('crm.telegram.challenge');
        }

        return $next($request);
    }
}
