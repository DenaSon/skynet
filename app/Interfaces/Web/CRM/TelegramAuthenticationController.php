<?php

declare(strict_types=1);

namespace App\Interfaces\Web\CRM;

use App\Modules\Identity\Application\AssertCatalogManagementAccess;
use App\Modules\Identity\Application\RecordIdentityAudit;
use App\Modules\Identity\Application\TelegramOidcAuthentication;
use App\Modules\Identity\Application\TelegramSecondFactor;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Identity\Infrastructure\Telegram\TelegramOidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final readonly class TelegramAuthenticationController
{
    public function __construct(
        private AssertCatalogManagementAccess $assertCatalogManagementAccess,
        private TelegramOidcAuthentication $telegramOidcAuthentication,
        private TelegramOidcClient $telegramOidcClient,
        private TelegramSecondFactor $telegramSecondFactor,
        private RecordIdentityAudit $recordIdentityAudit,
    ) {}

    public function challenge(): View
    {
        $this->trustedActor();

        abort_unless($this->telegramOidcClient->isConfigured(), 503);

        return view('crm.telegram-challenge');
    }

    public function start(Request $request): RedirectResponse
    {
        $actor = $this->trustedActor();

        abort_unless($this->telegramOidcClient->isConfigured(), 503);

        $redirectUri = route('crm.telegram.callback');
        $authorization = $this->telegramOidcAuthentication->begin(
            $request->session(),
            (int) $actor->getKey(),
            $redirectUri,
        );

        return redirect()->away($authorization->authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $actor = $this->trustedActor();
        $redirectUri = route('crm.telegram.callback');
        $state = $request->query('state');
        $code = $request->query('code');

        try {
            $identity = $this->telegramOidcAuthentication->complete(
                $request->session(),
                (int) $actor->getKey(),
                is_string($state) ? $state : null,
                $request->query('error') === null && is_string($code) ? $code : null,
                $redirectUri,
            );
        } catch (\Throwable $exception) {
            report($exception);

            return $this->retry();
        }

        if ($identity === null) {
            return $this->retry();
        }

        // Re-check the current account and its Telegram link after the external redirect.
        $this->assertCatalogManagementAccess->handle((int) $actor->getKey());
        $freshActor = User::query()->with('telegramAccount')->find((int) $actor->getKey());

        if ($freshActor === null
            || $freshActor->telegramAccount === null
            || ! hash_equals((string) $freshActor->telegramAccount->telegram_user_id, $identity->telegramUserId)) {
            return $this->retry();
        }

        $this->recordIdentityAudit->handle(
            $freshActor,
            'telegram_oidc.verified',
            'user',
            (int) $freshActor->getKey(),
            ['client' => 'crm', 'factor' => 'telegram'],
        );

        $request->session()->regenerate();
        $this->telegramSecondFactor->markVerifiedFor($request->session(), (int) $freshActor->getKey(), $identity->telegramUserId);

        return redirect()->intended(route('crm.dashboard'));
    }

    private function trustedActor(): User
    {
        $actorUserId = Auth::guard('web')->id();

        abort_unless(is_numeric($actorUserId) && (int) $actorUserId > 0, 403);

        $this->assertCatalogManagementAccess->handle((int) $actorUserId);

        return User::query()->with('telegramAccount')->findOrFail((int) $actorUserId);
    }

    private function retry(): RedirectResponse
    {
        return redirect()->route('crm.telegram.challenge')->with(
            'telegram_auth_error',
            'تأیید تلگرام انجام نشد. دوباره تلاش کنید.',
        );
    }
}
