<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use App\Modules\Trial\Application\ReserveFreeTrial;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;

trait FreeTrialFixtures
{
    public array $trialRemote = [];

    public int $trialProviderStatus = 200;

    public int $trialNextRemoteId = 811;

    protected function configureFreeTrial(): void
    {
        config()->set([
            'trial.enabled' => true, 'trial.phone_hmac_key' => str_repeat('fixture-only-', 4),
            'trial.daily_limit' => 20,
            'trial.internal_squad_uuid' => '550e8400-e29b-41d4-a716-446655440005',
            'trial.verified_squad_uuid' => '550e8400-e29b-41d4-a716-446655440005',
            'services.remnawave.writes_enabled' => true,
            'services.remnawave.url' => 'https://trial-panel.test',
            'services.remnawave.token' => 'fixture-only-provider-token',
            'telegram.sales.enabled' => true, 'telegram.sales.token' => '12345678:trial-sales',
            'nutgram.token' => '87654321:trial-admin', 'telegram_notifications.enabled' => true,
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://trial-panel.test/api/users')) {
                if ($this->trialProviderStatus !== 200) {
                    return Http::response([], $this->trialProviderStatus);
                }
                if ($request->method() === 'POST') {
                    $this->trialRemote = [
                        ...$request->data(), 'id' => $this->trialNextRemoteId++, 'shortUuid' => 'private-trial',
                        'subscriptionUrl' => 'https://sub.example.test/private-trial',
                        'userTraffic' => ['usedTrafficBytes' => 0],
                        'activeInternalSquads' => [['uuid' => config('trial.internal_squad_uuid')]],
                    ];
                }

                return Http::response(['response' => $this->trialRemote], 200);
            }

            return Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200);
        });
    }

    protected function trialMember(int $telegramId = 9210001): User
    {
        Role::findOrCreate('member', 'web');
        $user = User::factory()->create();
        $user->syncRoles(['member']);
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

        return $user;
    }

    protected function activateFreeTrial(User $user, int $telegramId = 9210001): int
    {
        $id = app(ReserveFreeTrial::class)->handle($user->id, $telegramId, $telegramId, '+989123456789');
        app(ProvisionFreeTrial::class)->handle($id);

        return $id;
    }
}
