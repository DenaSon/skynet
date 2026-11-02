<?php

declare(strict_types=1);

use App\Interfaces\Web\Live\Dashboard;
use App\Interfaces\Web\Live\DemoController;
use App\Interfaces\Web\Live\EnsureDemoViewer;
use App\Interfaces\Web\Live\EnsureLiveEnabled;
use App\Interfaces\Web\Live\EnsureLiveReseller;
use App\Interfaces\Web\Live\LiveAuthController;
use Illuminate\Support\Facades\Route;

// Register BEFORE the legacy catch-all '/' web route.
Route::domain(config('denagate_live.host'))
    ->middleware(EnsureLiveEnabled::class)
    ->name('denagate-live.')
    ->group(function (): void {
        Route::get('/', [LiveAuthController::class, 'entry'])->name('entry');
        Route::post('/auth/telegram', [LiveAuthController::class, 'login'])
            ->middleware('throttle:6,1')->name('telegram.login');
        // Preview access is a separate Telegram-signed member session, never a reseller role.
        Route::get('/demo', [DemoController::class, 'entry'])->name('demo.entry');
        Route::post('/demo/auth/telegram', [DemoController::class, 'login'])
            ->middleware('throttle:6,1')->name('demo.login');
        Route::middleware(EnsureDemoViewer::class)->group(function (): void {
            Route::get('/demo/preview', [DemoController::class, 'preview'])->name('demo.preview');
            Route::post('/demo/exit', [DemoController::class, 'exit'])->name('demo.exit');
        });

        Route::middleware(EnsureLiveReseller::class)->group(function (): void {
            Route::livewire('/dashboard', Dashboard::class)->name('dashboard');
            Route::post('/logout', [LiveAuthController::class, 'logout'])->name('logout');
        });
    });
