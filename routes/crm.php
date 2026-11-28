<?php

declare(strict_types=1);

use App\Interfaces\Web\CRM\Analytics;
use App\Interfaces\Web\CRM\EnsureCrmAccess;
use App\Interfaces\Web\CRM\Profile;
use App\Interfaces\Web\CRM\Shell;
use App\Interfaces\Web\CRM\TelegramAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('denagate_crm.host'))
    ->name('crm.')
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('crm.dashboard'))->name('home');

        Route::middleware(['auth', 'identity.active'])->group(function (): void {
            Route::get('/auth/telegram', [TelegramAuthenticationController::class, 'challenge'])
                ->name('telegram.challenge');
            Route::post('/auth/telegram', [TelegramAuthenticationController::class, 'start'])
                ->middleware('throttle:identity-telegram-oidc')
                ->name('telegram.start');
            Route::get('/auth/telegram/callback', [TelegramAuthenticationController::class, 'callback'])
                ->middleware('throttle:identity-telegram-oidc')
                ->name('telegram.callback');
        });

        Route::middleware(EnsureCrmAccess::class)->group(function (): void {
            Route::livewire('/admin/crm', Shell::class)->name('dashboard');
            Route::livewire('/admin/crm/analytics', Analytics::class)->name('analytics');
            Route::livewire('/admin/crm/customers', Shell::class)->name('customers');
            Route::livewire('/admin/crm/resellers', Shell::class)->name('resellers');
            Route::livewire('/admin/crm/reseller-customers', Shell::class)->name('contacts');
            Route::livewire('/admin/crm/customers/{id}', Profile::class)->whereNumber('id')->name('customers.show');
            Route::livewire('/admin/crm/resellers/{id}', Profile::class)->whereNumber('id')->name('resellers.show');
        });
    });
