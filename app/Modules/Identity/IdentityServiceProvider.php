<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Presentation\Console\BootstrapAdmin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('identity.manage-users', fn (User $user): bool => $user->is_active && $user->isAdmin());

        RateLimiter::for('identity-login', function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(30)->by('ip|'.$request->ip()),
            ];
        });

        $this->loadRoutesFrom(__DIR__.'/Presentation/routes/web.php');
        $this->loadViewsFrom(__DIR__.'/Presentation/views', 'identity');

        if ($this->app->runningInConsole()) {
            $this->commands([BootstrapAdmin::class]);
        }
    }
}
