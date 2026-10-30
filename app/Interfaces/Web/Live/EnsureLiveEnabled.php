<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLiveEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('denagate_live.enabled') === true, 404);

        return $next($request);
    }
}
