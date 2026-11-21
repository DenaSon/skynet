<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\AdminFinanceWalletUi;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['982101', '982102']);
    config()->set('nutgram.token', '12345:admin-nav-test');
    config()->set('telegram.sales.enabled', false);
    Http::preventStrayRequests();
    Http::fake();
});

function backNavRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);

    return $root;
}

function backNavBot(User $actor, int $telegramId = 982101): Nutgram
{
    TelegramAccount::query()->firstOrCreate([
        'user_id' => $actor->id,
    ], ['telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function backNavPayload(NutgramRequest $request): array
{
    $result = FakeNutgram::getActualData($request);
    if (is_string($result['reply_markup'] ?? null)) {
        $result['reply_markup'] = json_decode($result['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $result;
}

function backNavContains(NutgramRequest $request, string $target): bool
{
    return str_contains(
        json_encode(backNavPayload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
        '"callback_data":"'.$target.'"',
    );
}

it('adds a catalog back action without removing existing root destinations', function (): void {
    $root = backNavRoot();
    $bot = backNavBot($root);

    foreach (['menu:admin:products', 'menu:admin:plans', 'full:list', 'full:completed'] as $page) {
        $bot->hearCallbackQueryData($page)->reply()
            ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'menu:admin:sales')
                    && backNavContains($r, 'menu:admin'), index: 1);
    }

    $bot->hearCallbackQueryData('menu:admin:sales')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'menu:admin:control')
                && backNavContains($r, 'menu:admin:products')
                && backNavContains($r, 'menu:admin:plans'), index: 1);

    // Admin home is already the root destination; no self-referencing Home control.
    $bot->hearCallbackQueryData('menu:admin')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'menu:admin:finance')
                && ! backNavContains($r, 'menu:home'), index: 1);

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('returns wallet details to the exact source user-profile page and retains legacy shortcuts', function (): void {
    $root = backNavRoot();
    $buyer = User::factory()->create();
    app(ViewWallet::class)->handle($buyer->id);
    $bot = backNavBot($root);

    $bot->hearCallbackQueryData('users:profile:'.$buyer->id.':3:mw')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'finance:wallet:'.$buyer->id.':p:3:mw'), index: 1);

    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id.':p:3:mw')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'users:profile:'.$buyer->id.':3:mw')
                && backNavContains($r, 'finance:wallets:1')
                && backNavContains($r, 'finance:topups:1')
                && backNavContains($r, 'menu:admin:finance'), index: 1);

    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id)->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'finance:wallets:1')
                && backNavContains($r, 'users:profile:'.$buyer->id.':1:a'), index: 1);

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves the source wallet-list page without trusting malformed callback navigation', function (): void {
    $root = backNavRoot();
    $bot = backNavBot($root);
    $walletOwners = [];
    for ($i = 0; $i < 10; $i++) {
        $buyer = User::factory()->create();
        app(ViewWallet::class)->handle($buyer->id);
        $walletOwners[] = $buyer->id;
    }
    $chosen = $walletOwners[0];

    $bot->hearCallbackQueryData('finance:wallets:2')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => str_contains(json_encode(backNavPayload($r)['reply_markup'], JSON_THROW_ON_ERROR), ':l:2:a')
                && backNavContains($r, 'menu:admin:finance'), index: 1);

    $bot->hearCallbackQueryData('finance:wallet:'.$chosen.':l:2:a')->reply()
        ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'finance:wallets:2')
                && backNavContains($r, 'users:profile:'.$chosen.':1:a'), index: 1);

    foreach ([
        'finance:wallet:'.$chosen.':l:0:a',
        'finance:wallet:'.$chosen.':l:999999:a',
        'finance:wallet:'.$chosen.':p:1:invalid',
        'finance:wallet:'.$chosen.':redirect:2:a',
    ] as $forged) {
        $bot->hearCallbackQueryData($forged)->reply()
            ->assertRaw(static fn (NutgramRequest $r): bool => backNavContains($r, 'finance:wallets:1')
                    && ! backNavContains($r, 'finance:wallets:999999'), index: 1);
    }

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps contextual wallet details root-only and private after authorization changes', function (): void {
    $root = backNavRoot();
    $buyer = User::factory()->create();
    app(ViewWallet::class)->handle($buyer->id);
    $bot = backNavBot($root);

    $bot->setCommonChat(Chat::make(-982101, ChatType::GROUP));
    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id.':p:1:a')->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(982101, ChatType::PRIVATE));
    $root->syncRoles(['admin']);
    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id.':p:1:a')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('preserves the same contextual back destinations in the native wallet fallback', function (): void {
    $root = backNavRoot();
    $buyer = User::factory()->create(['name' => 'مشتری <امن> & من']);
    app(ViewWallet::class)->handle($buyer->id);
    // ResolveTelegramActor reads the already linked operator from this verified ID.
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 982101]);
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(982101, false, 'Root'));
    $bot->shouldReceive('chat')->andReturn(Chat::make(982101, ChatType::PRIVATE));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andThrow(
        new TelegramException('Bad Request: Rich unavailable', 400),
    );
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(
        static function (...$args) use ($buyer): null {
            $text = (string) ($args[0] ?? '');
            $keyboard = null;
            foreach ($args as $arg) {
                if ($arg instanceof InlineKeyboardMarkup) {
                    $keyboard = $arg;
                }
            }
            $buttons = json_encode($keyboard, JSON_THROW_ON_ERROR);

            expect($text)->toContain('جزئیات کیف پول', 'مشتری &lt;امن&gt; &amp; من')
                ->and($buttons)->toContain(
                    'users:profile:'.$buyer->id.':4:a',
                    'finance:wallets:1',
                    'finance:topups:1',
                    'menu:admin:finance',
                );

            return null;
        },
    );

    app(AdminFinanceWalletUi::class)->detail($bot, (string) $buyer->id, 'p', '4', 'a');
    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});
