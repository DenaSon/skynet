<?php

declare(strict_types=1);

use App\Interfaces\Web\CRM\EnsureCrmAccess;
use App\Interfaces\Web\CRM\Shell;
use Illuminate\Support\Facades\Route;

Route::domain((string) config('denagate_crm.host'))
    ->name('crm.')
    ->group(function (): void {
        Route::get('/', fn () => redirect()->route('crm.dashboard'))->name('home');

        Route::middleware(EnsureCrmAccess::class)->group(function (): void {
            Route::livewire('/admin/crm', Shell::class)->name('dashboard');
        });
    });
