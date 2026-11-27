<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCrmScheme
{
    public function handle(Request $request, Closure $next): Response
    {
        if (strcasecmp($request->getHost(), (string) config('denagate_crm.host')) !== 0) {
            return $next($request);
        }

        $previousSessionSecure = config('session.secure');
        $previousSessionDomain = config('session.domain');

        // Runflare terminates public TLS before forwarding to Laravel.
        // Keep this policy host-scoped; do not change APP_URL or global proxy trust.
        config([
            'session.secure' => true,
            'session.domain' => null,
        ]);
        URL::forceScheme('https');

        try {
            $forwardedProtoHeader = (string) $request->headers->get('X-Forwarded-Proto', '');
            $forwardedProto = strtolower(trim(explode(',', $forwardedProtoHeader)[0] ?? ''));

            if ($forwardedProto === 'http' && ! $request->isSecure()) {
                $response = redirect()->secure(
                    $request->getRequestUri(),
                    $request->isMethodSafe() ? 301 : 308,
                );
            } else {
                $response = $next($request);
            }

            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('Referrer-Policy', 'no-referrer');
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            if ($forwardedProto === 'https' || $request->isSecure()) {
                $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
            }

            return $response;
        } finally {
            // Prevent URL/session configuration leaking to another host or request.
            URL::forceScheme(null);
            config([
                'session.secure' => $previousSessionSecure,
                'session.domain' => $previousSessionDomain,
            ]);
        }
    }
}
