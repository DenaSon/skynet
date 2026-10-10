<?php

namespace App\Modules\Identity\Presentation\Controllers;

use App\Modules\Identity\Presentation\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AuthenticatedSessionController
{
    public function create(): View
    {
        return view('identity::login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        if (! Auth::guard('web')->attempt([
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'is_active' => true,
        ])) {
            throw ValidationException::withMessages([
                'email' => 'These credentials do not match an active account.',
            ]);
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
}
