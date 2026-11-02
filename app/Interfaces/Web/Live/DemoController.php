<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Isolated, read-only reseller preview. Never authenticates a real reseller session.
 */
final readonly class DemoController
{
    public function entry(): View
    {
        return view('live.entry', ['demo' => true]);
    }

    public function login(
        Request $request,
        ValidateTelegramInitData $validator,
        ResolveTelegramActor $telegram,
        ResolveSalesBuyer $buyers,
    ): RedirectResponse {
        $raw = $request->input('init_data');
        abort_unless(is_string($raw) && $raw !== '' && strlen($raw) <= 8192, 422);

        $token = config('telegram.sales.token');
        abort_unless(
            config('telegram.sales.enabled') === true
            && is_string($token) && $token !== ''
            && $token !== config('nutgram.token'),
            503,
        );

        $telegramId = $validator->userId($raw, $token);
        abort_if($telegramId === null, 403);

        $actor = $telegram->handle($telegramId);
        abort_if($actor === null || ! $buyers->isMember($actor->userId), 403);

        // Keep Demo proof separate from the real reseller's authenticated web guard.
        $request->session()->regenerate();
        $request->session()->put('denagate_demo.user_id', $actor->userId);
        $request->session()->put('denagate_demo.telegram_id', $telegramId);

        return redirect()->route('denagate-live.demo.preview');
    }

    public function preview(): View
    {
        // All operational examples are static presentation fixtures. No Sales,
        // Billing, customer, ledger or Provider reads are performed.
        return view('live.demo-preview');
    }

    public function exit(Request $request): RedirectResponse
    {
        $request->session()->forget(['denagate_demo.user_id', 'denagate_demo.telegram_id']);
        $request->session()->regenerateToken();

        return redirect()->route('denagate-live.demo.entry', ['closed' => '1']);
    }
}
