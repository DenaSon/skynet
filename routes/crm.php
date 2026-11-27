<?php

declare(strict_types=1);

use App\Interfaces\Web\CRM\Analytics;
use App\Interfaces\Web\CRM\EnsureCrmAccess;
use App\Interfaces\Web\CRM\Profile;
use App\Interfaces\Web\CRM\Shell;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('denagate_crm.host'))
    ->name('crm.')
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('crm.dashboard'))->name('home');

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
