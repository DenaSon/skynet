<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\SalesPanelRequests;
use App\Modules\Trial\Application\TrialAvailability;
use App\Settings\OperationalSettings;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['997901', '997902']);
    config()->set('nutgram.token', '12345:admin_test');
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function operationalBot(User $actor, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function operationalPayload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

function operationalToken(Request $request): ?string
{
    $buttons = json_encode(operationalPayload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

    return preg_match('/admin:ops:apply:([a-f0-9]{32})/', $buttons, $matches) === 1
        ? $matches[1] : null;
}

it('seeds exactly the original deployed config values and thereafter uses Settings rather than ENV', function (): void {
    $settings = app(OperationalSettings::class);
    expect($settings->panel_minimum_wallet_toman)->toBe((int) config('sales.panel_minimum_wallet_toman'))
        ->and($settings->trial_enabled)->toBe(config('trial.enabled'))
        ->and($settings->trial_daily_limit)->toBe(config('trial.daily_limit'))
        ->and(DB::table('settings')->where('group', 'operational')->count())->toBe(3);

    $settings->panel_minimum_wallet_toman = 550_000;
    $settings->trial_enabled = true;
    $settings->trial_daily_limit = 7;
    $settings->save();
    config()->set('sales.panel_minimum_wallet_toman', 1);
    config()->set('trial.enabled', false);
    config()->set('trial.daily_limit', 1);
    expect(SalesPanelRequests::minimumWalletBalanceRials())->toBe(5_500_000)
        ->and(OperationalSettings::trialIsEnabled())->toBeTrue()
        ->and(OperationalSettings::trialDailyLimit())->toBe(7);
});

it('fails closed if a required Settings key disappears after migration', function (): void {
    DB::table('settings')->where('group', 'operational')->where('name', 'trial_enabled')->delete();
    config()->set('trial.enabled', true);
    expect(fn () => OperationalSettings::trialIsEnabled())->toThrow(RuntimeException::class);
});

it('shows a Rich-first root-only settings menu and changes the panel minimum once', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);

    $bot->hearCallbackQueryData('admin:welcome:show')->reply()->assertRaw(
        static function (Request $request): bool {
            return str_contains(json_encode(operationalPayload($request)['reply_markup'], JSON_THROW_ON_ERROR),
                'admin:ops:show');
        }, index: 1,
    );

    $bot->hearCallbackQueryData('admin:ops:show')->reply()->assertRaw(
        static function (Request $request): bool {
            $body = operationalPayload($request);
            $rich = is_string($body['rich_message'] ?? null)
                ? json_decode($body['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($body['rich_message'] ?? []);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains($rich['html'] ?? '', 'حداقل اعتبار نمایندگی')
                && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'admin:ops:edit:panel')
                && ! str_contains(json_encode($body, JSON_THROW_ON_ERROR), 'admin:notice-topic');
        }, index: 1,
    );

    $bot->hearCallbackQueryData('admin:ops:edit:panel')->reply()->assertCalled('editMessageText');
    $bot->hearText('۵۵۰۰۰۰')->reply()->assertRaw(
        static fn (Request $request): bool => operationalToken($request) !== null,
    );
    $state = Cache::get('admin:ops:draft:'.$root->id);
    expect($state['old'])->toBe((int) config('sales.panel_minimum_wallet_toman'))
        ->and($state['new'])->toBe(550_000);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(app(OperationalSettings::class)->panel_minimum_wallet_toman)->toBe(550_000)
        ->and(SalesPanelRequests::minimumWalletBalanceRials())->toBe(5_500_000);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(DB::table('settings')->where('group', 'operational')->count())->toBe(3);
});

it('changes daily quota and trial admission without deleting earlier settings or making provider requests', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply()->assertCalled('editMessageText');
    $bot->hearText('۱')->reply();
    $state = Cache::get('admin:ops:draft:'.$root->id);
    expect($state['new'])->toBe(1);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply();
    expect(OperationalSettings::trialDailyLimit())->toBe(1);

    $bot->hearCallbackQueryData('admin:ops:trial:on')->reply()->assertRaw(
        static fn (Request $req): bool => operationalToken($req) !== null, index: 1,
    );
    $state = Cache::get('admin:ops:draft:'.$root->id);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply();
    expect(OperationalSettings::trialIsEnabled())->toBeTrue();

    $bot->hearCallbackQueryData('admin:ops:trial:off')->reply();
    $state = Cache::get('admin:ops:draft:'.$root->id);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply();
    expect(OperationalSettings::trialIsEnabled())->toBeFalse()
        ->and(TrialAvailability::ready())->toBeFalse()
        ->and(OperationalSettings::trialDailyLimit())->toBe(1);
    Http::assertNothingSent();
});

it('routes the operational amount after an unfinished accounting draft without recording money', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);
    $accountingKey = 'admin:accounting:draft:'.$root->id;
    $financeKey = TelegramBotContext::current()->cacheKey('telegram:finance:wallet:'.$root->id);
    $bankKey = TelegramBotContext::current()->cacheKey('telegram:bankreview:reference:'.$root->id);

    $bot->hearCallbackQueryData('acc:begin:owner_draw')->reply();
    expect(Cache::has($accountingKey))->toBeTrue();
    Cache::put($bankKey, ['claim' => 5, 'stage' => 'amount'], now()->addMinutes(10));

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    expect(Cache::has($accountingKey))->toBeFalse()
        ->and(Cache::has($financeKey))->toBeFalse()
        ->and(Cache::has($bankKey))->toBeFalse();
    $bot->hearText('17')->reply();
    $draft = Cache::get(TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$root->id));
    expect($draft['kind'])->toBe('limit')
        ->and($draft['new'])->toBe(17)
        ->and(DB::table('accounting_entries')->count())->toBe(0);

    $bot->hearCallbackQueryData('admin:ops:apply:'.$draft['token'])->reply();
    expect(OperationalSettings::trialDailyLimit())->toBe(17)
        ->and(DB::table('accounting_entries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('retires an old finance input before editing operational settings', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);
    $financeKey = TelegramBotContext::current()->cacheKey('telegram:finance:wallet:'.$root->id);
    $opsKey = TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$root->id);

    $bot->hearCallbackQueryData('finance:find-wallet')->reply();
    expect(Cache::get($financeKey)['kind'])->toBe('find');

    $bot->hearCallbackQueryData('admin:ops:edit:panel')->reply();
    expect(Cache::has($financeKey))->toBeFalse();
    $bot->hearText('750000')->reply();
    $draft = Cache::get($opsKey);
    expect($draft['step'])->toBe('confirm')
        ->and($draft['new'])->toBe(750_000);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$draft['token'])->reply();
    expect(app(OperationalSettings::class)->panel_minimum_wallet_toman)->toBe(750_000)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('invalidates old operational confirmations when switching to accounting and wallet forms', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);
    $opsKey = TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$root->id);
    $accountingKey = 'admin:accounting:draft:'.$root->id;
    $financeKey = TelegramBotContext::current()->cacheKey('telegram:finance:wallet:'.$root->id);
    $initialLimit = OperationalSettings::trialDailyLimit();

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    $bot->hearText('17')->reply();
    $oldToken = Cache::get($opsKey)['token'];
    $bot->hearCallbackQueryData('acc:begin:owner_draw')->reply();
    expect(Cache::has($opsKey))->toBeFalse();
    $bot->hearText('10000')->reply();
    expect(Cache::get($accountingKey)['step'])->toBe('memo');
    $bot->hearCallbackQueryData('admin:ops:apply:'.$oldToken)->reply();
    expect(OperationalSettings::trialDailyLimit())->toBe($initialLimit)
        ->and(DB::table('accounting_entries')->count())->toBe(0);

    $bot->hearCallbackQueryData('admin:ops:edit:limit')->reply();
    $bot->hearText('17')->reply();
    $oldToken = Cache::get($opsKey)['token'];
    $bot->hearCallbackQueryData('finance:find-wallet')->reply();
    expect(Cache::has($opsKey))->toBeFalse()
        ->and(Cache::get($financeKey)['kind'])->toBe('find');
    $bot->hearCallbackQueryData('admin:ops:apply:'.$oldToken)->reply();
    expect(OperationalSettings::trialDailyLimit())->toBe($initialLimit)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps welcome editing and operational settings drafts mutually exclusive', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);
    $opsKey = TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$root->id);
    $welcomeKey = 'admin:sales-welcome:draft:997901';

    $bot->hearCallbackQueryData('admin:welcome:edit:member')->reply();
    expect(Cache::has($welcomeKey))->toBeTrue();

    $bot->hearCallbackQueryData('admin:ops:edit:panel')->reply();
    expect(Cache::has($welcomeKey))->toBeFalse()
        ->and(Cache::has($opsKey))->toBeTrue();

    $bot->hearCallbackQueryData('admin:welcome:edit:member')->reply();
    expect(Cache::has($opsKey))->toBeFalse()
        ->and(Cache::has($welcomeKey))->toBeTrue();
    Http::assertNothingSent();
});

it('denies ordinary admins and revoked super admins, and cancels stale or altered confirmations', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = operationalBot($root, 997901);

    $bot->hearCallbackQueryData('admin:ops:edit:panel')->reply();
    $bot->hearText('650000')->reply();
    $state = Cache::get('admin:ops:draft:'.$root->id);
    $settings = app(OperationalSettings::class);
    $settings->panel_minimum_wallet_toman = 700_000;
    $settings->save();
    $bot->hearCallbackQueryData('admin:ops:apply:'.$state['token'])->reply();
    expect(SalesPanelRequests::minimumWalletBalanceRials())->toBe(7_000_000);

    $bot->hearCallbackQueryData('admin:ops:edit:panel')->reply();
    $bot->hearText('650000')->reply();
    $second = Cache::get('admin:ops:draft:'.$root->id);
    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('admin:ops:apply:'.$second['token'])->reply();
    expect(SalesPanelRequests::minimumWalletBalanceRials())->toBe(7_000_000);

    $ordinary = User::factory()->admin()->create();
    $other = operationalBot($ordinary, 997903);
    $other->hearCallbackQueryData('admin:ops:show')->reply();
    expect(app(OperationalSettings::class)->panel_minimum_wallet_toman)->toBe(700_000);

    $bot->setCommonChat(Chat::make(-997901, ChatType::GROUP));
    $bot->hearCallbackQueryData('admin:ops:trial:on')->reply()->assertNoReply();
    Http::assertNothingSent();
});
