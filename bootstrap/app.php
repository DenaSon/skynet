<?php

use App\Modules\Identity\Presentation\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['identity.active' => EnsureActiveUser::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response): Response {
            $request = request();
            $status = $response->getStatusCode();
            if ($request->getHost() !== config('denagate_live.host')
                || config('denagate_live.enabled') !== true
                || ! in_array($status, [401, 403, 419, 422, 429, 503], true)) {
                return $response;
            }

            $headers = [
                'Cache-Control' => 'no-store, private',
                'Referrer-Policy' => 'no-referrer',
                'X-Content-Type-Options' => 'nosniff',
            ];
            if ($response->headers->has('Retry-After')) {
                $headers['Retry-After'] = (string) $response->headers->get('Retry-After');
            }
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return response()->json(['message' => 'امکان ادامه نیست. پنل را دوباره از بات فروش باز کنید.'], $status, $headers);
            }

            return response()->view('live.recovery', ['statusCode' => $status], $status, $headers);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
