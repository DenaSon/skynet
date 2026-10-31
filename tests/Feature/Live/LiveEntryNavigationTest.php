<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Web\Live\Dashboard;
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
