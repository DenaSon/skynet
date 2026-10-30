<?php

declare(strict_types=1);

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
        Route::middleware(EnsureLiveReseller::class)->group(function (): void {
            Route::get('/dashboard', [LiveAuthController::class, 'dashboard'])->name('dashboard');
            Route::post('/logout', [LiveAuthController::class, 'logout'])->name('logout');
        });
    });
