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
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

final readonly class TelegramAuthenticationController
{
    private const TRANSACTION_KEY = 'identity.telegram_oidc_transaction';

    private const CONSUMED_STATE_PREFIX = 'identity:telegram-oidc:consumed:';

    public function __construct(
        private AssertCatalogManagementAccess $assertCatalogManagementAccess,
        private TelegramOidcAuthentication $telegramOidcAuthentication,
        private TelegramOidcClient $telegramOidcClient,
        private TelegramSecondFactor $telegramSecondFactor,
        private RecordIdentityAudit $recordIdentityAudit,
    ) {}

    public function challenge(Request $request): View
    {
        $this->trustedActor($request);

        abort_unless($this->telegramOidcClient->isConfigured(), 503);

        return view('crm.telegram-challenge');
    }

    public function start(Request $request): RedirectResponse
    {
        $actor = $this->trustedActor($request);

        abort_unless($this->telegramOidcClient->isConfigured(), 503);

        $redirectUri = route('crm.telegram.callback');
        $authorization = $this->telegramOidcAuthentication->begin($redirectUri);

        $request->session()->put(self::TRANSACTION_KEY, [
            'state' => $authorization->state,
            'nonce' => $authorization->nonce,
            'code_verifier' => $authorization->codeVerifier,
            'redirect_uri' => $redirectUri,
            'user_id' => (int) $actor->getKey(),
            'created_at' => now()->timestamp,
        ]);

        return redirect()->away($authorization->authorizationUrl);
    }

    public function callback(Request $request): RedirectResponse
    {
        $actor = $this->trustedActor($request);
        $transaction = $request->session()->pull(self::TRANSACTION_KEY);
        $state = $request->query('state');
        $redirectUri = route('crm.telegram.callback');

        if (! is_array($transaction)
            || ! is_string($state)
            || ! is_string($transaction['state'] ?? null)
            || ! hash_equals($transaction['state'], $state)
            || ($transaction['user_id'] ?? null) !== (int) $actor->getKey()
            || ($transaction['redirect_uri'] ?? null) !== $redirectUri
            || ! is_int($transaction['created_at'] ?? null)
            || $transaction['created_at'] > now()->timestamp
            || $transaction['created_at'] < now()->subMinutes(10)->timestamp) {
            return $this->retry();
        }

        $consumed = Cache::add(
            self::CONSUMED_STATE_PREFIX.hash('sha256', $state),
            true,
            now()->addMinutes(10),
        );

        if (! $consumed || $request->query('error') !== null) {
            return $this->retry();
        }

        $code = $request->query('code');
        $codeVerifier = $transaction['code_verifier'] ?? null;
        $nonce = $transaction['nonce'] ?? null;

        if (! is_string($code)
            || $code === ''
            || strlen($code) > 4096
            || ! is_string($codeVerifier)
            || preg_match('/^[A-Za-z0-9._~-]{43,128}$/D', $codeVerifier) !== 1
            || ! is_string($nonce)
            || preg_match('/^[a-f0-9]{64}$/D', $nonce) !== 1) {
            return $this->retry();
        }

        try {
            $identity = $this->telegramOidcAuthentication->complete(
                $code,
                $redirectUri,
                $codeVerifier,
                $nonce,
            );
        } catch (\Throwable $exception) {
            report($exception);

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
        $this->telegramSecondFactor->markVerifiedFor($request->session(), (int) $freshActor->getKey());

        return redirect()->intended(route('crm.dashboard'));
    }

    private function trustedActor(Request $request): User
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
