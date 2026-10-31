<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Interfaces\Web\Live\Dashboard;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('denagate_live.enabled', true);
    config()->set('denagate_live.url', 'https://live.denagate.ir');
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.bot_username', 'denagatebot');
    config()->set('telegram.sales.token', '123456:live-six-a');
    config()->set('nutgram.token', '789123:admin');
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
    Http::preventStrayRequests();
    Http::fake();
});

function live6aUser(int $telegramId, string $role = 'reseller'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function live6aBot(int $telegramId): Nutgram
{
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Sales Reseller'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function live6aRichContains(Request $request, string $needle): bool
{
    $data = FakeNutgram::getActualData($request);
    $rich = is_string($data['rich_message'] ?? null)
        ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($data['rich_message'] ?? []);

    return str_contains((string) ($rich['html'] ?? ''), $needle);
}

/** @return list<list<array<string, mixed>>> */
function live6aReplyRows(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    $markup = is_string($data['reply_markup'] ?? null)
        ? json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR)
        : ($data['reply_markup'] ?? []);

    return $markup['keyboard'] ?? [];
}

function live6aNoFinancialWrites(): void
{
    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('provisioning_operations')->count())->toBe(0);
}

it('discovers Live for authorized resellers in home and account while preserving existing buttons', function (): void {
    live6aUser(997001);
    $bot = live6aBot(997001);

    $bot->hearText('/start')->reply()
        ->assertCalled('sendRichMessage', 1)
        ->assertRaw(static fn (Request $request): bool => live6aRichContains($request, 'type="web_app"')
            && live6aRichContains($request, 'url="https://live.denagate.ir"')
            && live6aRichContains($request, 'sales:wallet')
            && live6aRichContains($request, 'sales:buy')
            && live6aRichContains($request, 'sales:report:30'), index: 0);

    $bot->hearText('/account')->reply()
        ->assertCalled('sendRichMessage', 1);
    $bot->hearCallbackQueryData('sales:live')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendMessage', 1);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('forbids member and revoked reseller even through forged old Live callbacks', function (): void {
    $member = live6aUser(997002, 'member');
    $bot = live6aBot(997002);

    $bot->hearText('/start')->reply()->assertRaw(
        static fn (Request $request): bool => ! live6aRichContains($request, 'type="web_app"'), index: 0,
    );

    $bot->hearCallbackQueryData('sales:live')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendMessage', 1);

    $member->assignRole('reseller');
    $bot->hearText('/start')->reply()->assertRaw(
        static fn (Request $request): bool => live6aRichContains($request, 'type="web_app"'), index: 0,
    );
    $member->removeRole('reseller');
    $bot->hearCallbackQueryData('sales:live')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendMessage', 1);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('hides the Live entry under its existing deployment gate and denies stale callbacks', function (): void {
    live6aUser(997003);
    $bot = live6aBot(997003);
    config()->set('denagate_live.enabled', false);

    $bot->hearText('/start')->reply()->assertRaw(
        static fn (Request $request): bool => ! live6aRichContains($request, 'type="web_app"'), index: 0,
    );
    $bot->hearCallbackQueryData('sales:live')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendMessage', 1);
    $bot->hearText('/live')->reply()->assertCalled('sendMessage', 1);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('adds one final one-tap app button for resellers without moving existing sales buttons', function (): void {
    live6aUser(997007);
    $actor = app(ResolveTelegramActor::class)->handle(997007);

    $keyboard = app(ReplyMenuRenderer::class)->salesKeyboard($actor);
    $rows = json_decode(json_encode($keyboard, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['keyboard'];
    $lastRow = $rows[count($rows) - 1];

    expect($keyboard->is_persistent)->toBeTrue()
        ->and($rows[0][0]['text'])->toBe(ReplyMenuLabels::SALES_BUY)
        ->and($rows[0][1]['text'])->toBe(ReplyMenuLabels::SALES_SUBSCRIPTIONS)
        ->and($rows[1][0]['text'])->toBe(ReplyMenuLabels::SALES_WALLET)
        ->and($rows[1][1]['text'])->toBe(ReplyMenuLabels::SALES_REPORTS)
        ->and(count($lastRow))->toBe(1)
        ->and($lastRow[0]['text'])->toBe(ReplyMenuLabels::SALES_APP)
        ->and($lastRow[0]['web_app']['url'])->toBe('https://live.denagate.ir')
        ->and(array_key_exists('callback_data', $lastRow[0]))->toBeFalse();

    $bot = live6aBot(997007);
    $bot->hearText('/start')->reply()
        ->assertCalled('sendRichMessage', 1)
        ->assertRaw(static function (Request $request): bool {
            $rows = live6aReplyRows($request);
            $lastRow = $rows[count($rows) - 1] ?? [];

            return count($lastRow) === 1
                && ($lastRow[0]['text'] ?? null) === ReplyMenuLabels::SALES_APP
                && ($lastRow[0]['web_app']['url'] ?? null) === 'https://live.denagate.ir'
                && live6aRichContains($request, 'type="web_app"')
                && live6aRichContains($request, 'url="https://live.denagate.ir"')
                && ! live6aRichContains($request, 'data="sales:live"');
        }, index: 0);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('keeps the native app shortcut private, reseller-only and feature-gated', function (): void {
    live6aUser(997008, 'member');
    $member = app(ResolveTelegramActor::class)->handle(997008);
    $memberRows = json_decode(json_encode(app(ReplyMenuRenderer::class)->salesKeyboard($member), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['keyboard'];
    expect(json_encode($memberRows, JSON_THROW_ON_ERROR))->not->toContain(ReplyMenuLabels::SALES_APP, 'live.denagate.ir');

    live6aUser(997009);
    $reseller = app(ResolveTelegramActor::class)->handle(997009);
    config()->set('denagate_live.enabled', false);
    $hiddenRows = json_decode(json_encode(app(ReplyMenuRenderer::class)->salesKeyboard($reseller), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR)['keyboard'];
    expect(json_encode($hiddenRows, JSON_THROW_ON_ERROR))->not->toContain(ReplyMenuLabels::SALES_APP, 'live.denagate.ir');

    config()->set('denagate_live.enabled', true);
    $bot = live6aBot(997008);
    $bot->hearText('/start')->reply()
        ->assertRaw(static fn (Request $request): bool => ! live6aRichContains($request, 'type="web_app"'), index: 0);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('uses a direct Web App button in the account view and preserves the legacy callback', function (): void {
    live6aUser(997010);
    $bot = live6aBot(997010);

    $bot->hearText('/account')->reply()
        ->assertCalled('sendRichMessage', 1)
        ->assertRaw(static function (Request $request): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $rows = $markup['inline_keyboard'] ?? [];
            $buttons = array_merge(...$rows);
            $apps = array_values(array_filter(
                $buttons,
                static fn (array $button): bool => ($button['text'] ?? null) === ReplyMenuLabels::SALES_APP,
            ));
            $last = $rows[count($rows) - 1] ?? [];

            return count($apps) === 1
                && ($apps[0]['web_app']['url'] ?? null) === 'https://live.denagate.ir'
                && ! array_key_exists('callback_data', $apps[0])
                && ($last[0]['callback_data'] ?? null) === 'sales:account:simple';
        }, index: 0);

    $bot->hearCallbackQueryData('sales:live')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('sendMessage', 1);

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('keeps exact member-specific return and topup links separate with no user-controlled URLs', function (): void {
    $reseller = live6aUser(997004);
    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);

    Livewire::test(Dashboard::class)
        ->assertSee('بازگشت به بات فروش')
        ->assertSee('href="https://t.me/denagatebot"', false)
        ->assertDontSee('start=live_topup')
        ->call('showSection', 'services')
        ->assertSee('بازگشت به بات فروش')
        ->call('showSection', 'credit')
        ->assertSee('بازگشت به بات فروش')
        ->assertSee('href="https://t.me/denagatebot?start=live_topup"', false)
        ->call('showSection', 'reports')
        ->assertSee('بازگشت به بات فروش');

    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('omits all return URLs when the configured Sales Bot username or gate is unsafe', function (): void {
    $reseller = live6aUser(997005);
    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);

    foreach (['', 'bad user', 'https://other.example/bot', 'bot?start=attack'] as $invalid) {
        config()->set('telegram.sales.bot_username', $invalid);
        Livewire::test(Dashboard::class)
            ->assertDontSee('بازگشت به بات فروش')
            ->assertDontSee('href="https://t.me/', false);
    }
    config()->set('telegram.sales.bot_username', 'denagatebot');
    config()->set('telegram.sales.enabled', false);
    Livewire::test(Dashboard::class)->assertDontSee('بازگشت به بات فروش');
    live6aNoFinancialWrites();
    Http::assertNothingSent();
});

it('preserves the explicit reseller-only Livewire RBAC gate after revocation', function (): void {
    $reseller = live6aUser(997006);
    $this->actingAs($reseller)->withSession(['denagate_live.user_id' => $reseller->id]);
    Livewire::test(Dashboard::class)->assertSee('بازگشت به بات فروش');
    $reseller->syncRoles(['member']);
    $this->get('https://live.denagate.ir/dashboard')->assertForbidden();
    live6aNoFinancialWrites();
    Http::assertNothingSent();
});
