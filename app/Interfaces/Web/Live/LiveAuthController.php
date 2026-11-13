<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final readonly class LiveAuthController
{
    public function entry(Request $request): View|RedirectResponse
    {
        if (in_array($request->query('notice'), ['401', '403', '419', '422', '429', '503'], true)) {
            return view('live.recovery', ['statusCode' => (int) $request->query('notice')]);
        }

        if (is_int(Auth::id()) && Auth::id() === $request->session()->get('denagate_live.user_id')) {
            return redirect()->route('denagate-live.dashboard');
        }

        return view('live.entry');
    }

    public function login(
        Request $request,
        ValidateTelegramInitData $validator,
        ResolveTelegramActor $telegram,
        ResolveSalesBuyer $buyers,
    ): View|RedirectResponse {
        // Reject malformed input without flashing raw Telegram credentials into session.
        $raw = $request->input('init_data');
        abort_unless(is_string($raw) && $raw !== '' && strlen($raw) <= 8192, 422);
        $token = config('telegram.sales.token');
        if (config('telegram.sales.enabled') !== true
            || ! is_string($token) || $token === ''
            || $token === config('nutgram.token')) {
            abort(503);
        }

        $telegramId = $validator->userId($raw, $token);
        abort_if($telegramId === null, 403);

        $actor = $telegram->handle($telegramId);
        abort_if($actor === null, 403);
        if (! $buyers->isReseller($actor->userId)) {
            // Only a verified, active ordinary member sees the reseller introduction.
            // Invalid signatures, unknown identities and inactive/operator accounts stay denied.
            abort_unless($buyers->isMember($actor->userId), 403);

            return view('live.reseller-intro');
        }

        // No new user or permission is created; reuse the verified Telegram binding.
        $user = User::query()->findOrFail($actor->userId);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('denagate_live.user_id', $actor->userId);

        return redirect()->route('denagate-live.dashboard');
    }

    public function dashboard(): View
    {
        return view('live.dashboard', ['name' => Auth::user()?->name]);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('denagate-live.entry');
    }
}
