<?php

declare(strict_types=1);

namespace App\Interfaces\Web\Live;

use App\Modules\Identity\Application\ResolveSalesBuyer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Demo eligibility is independent of the real reseller's web guard. */
final readonly class EnsureDemoViewer
{
    public function __construct(
        private ResolveSalesBuyer $buyers,
        private ResolveTelegramActor $telegram,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->session()->get('denagate_demo.user_id');
        $telegramId = $request->session()->get('denagate_demo.telegram_id');

        if (! is_int($userId) || $userId < 1 || ! is_int($telegramId) || $telegramId < 1) {
            abort_if($request->hasHeader('X-Livewire'), 419);

            return redirect()->route('denagate-live.demo.entry');
        }

        try {
            $actor = $this->telegram->handle($telegramId);
            if ($actor === null || $actor->userId !== $userId || ! $this->buyers->isMember($userId)) {
                throw new AuthorizationException;
            }
        } catch (AuthorizationException) {
            $request->session()->forget(['denagate_demo.user_id', 'denagate_demo.telegram_id']);
            abort(403);
        }

        return $next($request);
    }
}
