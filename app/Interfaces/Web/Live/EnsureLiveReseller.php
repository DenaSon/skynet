<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureLiveReseller
{
    public function __construct(private ResolveSalesBuyer $buyers) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = Auth::guard('web')->id();
        if (! is_int($userId) || $userId !== $request->session()->get('denagate_live.user_id')) {
            return redirect()->route('denagate-live.entry');
        }

        try {
            $this->buyers->isReseller($userId);
        } catch (AuthorizationException) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403);
        }

        return $next($request);
    }
}
