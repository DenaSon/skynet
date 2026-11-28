<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Identity\Application\SuperAdminTrust;
use App\Modules\Identity\Application\TelegramSecondFactor;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Telegram\TelegramOidcClient;
use App\Modules\Identity\Presentation\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AuthenticatedSessionController
{
    public function __construct(
        private readonly SuperAdminTrust $superAdminTrust,
        private readonly TelegramOidcClient $telegramOidcClient,
        private readonly TelegramSecondFactor $telegramSecondFactor,
    ) {}

    public function create(): View
    {
        return view('identity::login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $isCrmHost = strcasecmp($request->getHost(), (string) config('denagate_crm.host')) === 0;

        if ($isCrmHost && ! $this->telegramOidcClient->isConfigured()) {
            abort(503);
        }

        if (Auth::guard('web')->attempt([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_active' => true,
        ]) === false) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match an active account.',
            ]);
        }

        if ($isCrmHost) {
            $user = Auth::guard('web')->user();

            if (! $user instanceof User) {
                $this->invalidateLogin($request);
                abort(403);
            }

            $user->loadMissing('telegramAccount');

            if (! $this->superAdminTrust->eligible($user)) {
                $this->invalidateLogin($request);

                throw ValidationException::withMessages([
                    'email' => 'These credentials do not match an active account.',
                ]);
            }

            $request->session()->regenerate();
            $this->telegramSecondFactor->clear($request->session());
            $request->session()->forget('identity.telegram_oidc_transaction');

            return redirect()->route('crm.telegram.challenge');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('identity.account', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function invalidateLogin(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
