<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class EnsureLiveEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('denagate_live.enabled') === true, 404);

        // Runflare terminates public TLS before forwarding to Laravel over HTTP.
        // The live host must never emit an insecure login form, redirect or asset URL.
        // Do not trust arbitrary X-Forwarded-* headers or change global APP_URL.
        URL::forceScheme('https');

        try {
            return $next($request);
        } finally {
            // Prevent the forced scheme leaking across requests in long-lived runtimes.
            URL::forceScheme(null);
        }
    }
}
