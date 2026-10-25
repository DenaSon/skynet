<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['995001', '995002']);
    Http::preventStrayRequests();
    Http::fake();
});

/** @param list<string> $permissions */
function a2ReadBot(int $telegramId, array $permissions): Nutgram
{
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $role = Role::findOrCreate('scoped-'.$telegramId, 'web');
    $role->syncPermissions($permissions);
    $user->assignRole($role);

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Operator'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function a2ReadPayload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('shows only the operational report and never finance or provider-write shortcuts', function (): void {
    $bot = a2ReadBot(995020, ['reports.view']);
    $bot->hearCallbackQueryData('rbac2:reports')->reply()->assertRaw(static function (NutgramRequest $request): bool {
        $data = a2ReadPayload($request);
        $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains((string) $data['text'], 'گزارش‌های عملیاتی')
            && ! str_contains((string) $data['text'], 'مجموع مانده')
            && ! str_contains((string) $data['text'], 'فروش امروز')
            && str_contains($buttons, 'rbac2:reports')
            && ! str_contains($buttons, 'node1:')
            && ! str_contains($buttons, 'finance:');
    }, index: 1);
    Http::assertNothingSent();
});

it('renders a readonly wallet report without approval or account mutation buttons', function (): void {
    $bot = a2ReadBot(995021, ['finance.view']);
    $bot->hearCallbackQueryData('rbac2:finance:1')->reply()->assertRaw(static function (NutgramRequest $request): bool {
        $data = a2ReadPayload($request);
        $buttons = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);

        return str_contains((string) $data['text'], 'گزارش کیف پول‌ها')
            && str_contains($buttons, 'rbac2:finance:1')
            && ! str_contains($buttons, 'finance:topup:decision')
            && ! str_contains($buttons, 'finance:wallet:')
            && ! str_contains($buttons, 'bank:decide:');
    }, index: 1);
    Http::assertNothingSent();
});

it('denies a forged readonly report callback after the role is revoked', function (): void {
    $bot = a2ReadBot(995022, ['reports.view']);
    $account = TelegramAccount::query()->where('telegram_user_id', 995022)->firstOrFail();
    $account->user->removeRole('scoped-995022');

    $bot->hearCallbackQueryData('rbac2:reports')->reply()->assertRaw(static function (NutgramRequest $request): bool {
        $data = a2ReadPayload($request);

        return str_contains((string) $data['text'], 'دسترسی');
    }, index: 1);
    Http::assertNothingSent();
});
