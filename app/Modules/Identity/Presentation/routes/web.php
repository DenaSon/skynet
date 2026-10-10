<?php

use App\Modules\Identity\Presentation\Controllers\AccountController;
use App\Modules\Identity\Presentation\Controllers\AdminRoleController;
use App\Modules\Identity\Presentation\Controllers\AdminUserController;
use App\Modules\Identity\Presentation\Controllers\AuthenticatedSessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->middleware('guest')->name('login');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware(['guest', 'throttle:identity-login'])->name('identity.login');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware('auth')->name('logout');

    Route::middleware(['auth', 'identity.active'])->group(function (): void {
        Route::get('/account', [AccountController::class, 'show'])->name('identity.account');

        Route::prefix('admin')->name('identity.admin.')->group(function (): void {
            Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
            Route::get('/roles', [AdminRoleController::class, 'index'])->name('roles.index');
            Route::post('/roles', [AdminRoleController::class, 'store'])->name('roles.store');
        });
    });
});
