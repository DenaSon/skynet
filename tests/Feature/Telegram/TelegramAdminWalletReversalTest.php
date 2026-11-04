<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['964801', '964802']);
    config()->set('billing.topups_enabled', true);
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function finReverseBot(User $actor, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function finReverseRequest(Request $request): array
{
    $body = FakeNutgram::getActualData($request);
    if (is_string($body['reply_markup'] ?? null)) {
        $body['reply_markup'] = json_decode($body['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $body;
}

function finReverseRich(Request $request): string
{
    $body = finReverseRequest($request);
    $rich = is_string($body['rich_message'] ?? null)
        ? json_decode($body['rich_message'], true, 512, JSON_THROW_ON_ERROR)
        : ($body['rich_message'] ?? []);

    return ($rich['is_rtl'] ?? null) === true ? (string) ($rich['html'] ?? '') : '';
}

it('offers a private root-only correction flow with escaped Rich preview and one-use final confirmation', function (): void {
    $root = User::factory()->create(['name' => 'Finance Root']);
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create(['name' => '<Buyer>']);
    $bot = finReverseBot($root, 964801);
    $topup = app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $buyer->id, 275_000, 'UI-27500-REF', 'Bank deposit',
    );
    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id)->reply()->assertRaw(
        static function (Request $request) use ($buyer): bool {
            $payload = finReverseRequest($request);
            $keyboard = json_encode($payload['reply_markup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return str_contains(finReverseRich($request), '&lt;Buyer&gt;')
                && str_contains($keyboard, 'finance:credit:begin:'.$buyer->id)
                && str_contains($keyboard, 'finance:reverse:list:'.$buyer->id.':1');
        }, index: 1,
    );

    $bot->hearCallbackQueryData('finance:reverse:list:'.$buyer->id.':1')->reply()->assertRaw(
        static fn (Request $req): bool => str_contains(finReverseRich($req), 'اصلاح تراکنش کیف پول')
            && str_contains(json_encode(finReverseRequest($req)['reply_markup'], JSON_THROW_ON_ERROR),
                'finance:reverse:prepare:'.$buyer->id.':'.$topup), index: 1,
    );
    $bot->hearCallbackQueryData('finance:reverse:prepare:'.$buyer->id.':'.$topup)->reply()
        ->assertCalled('editMessageText');
    $bot->hearText('تأیید اشتباه <رسید>')->reply()->assertRaw(
        static function (Request $request) use ($topup): bool {
            $html = finReverseRich($request);
            $keyboard = json_encode(finReverseRequest($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($html, '&lt;رسید&gt;')
                && str_contains($html, '27,500 تومان')
                && str_contains($keyboard, 'finance:reverse:apply:');
        },
    );

    $state = Cache::get('telegram:finance:wallet:'.$root->id);
    expect($state['kind'])->toBe('reverse_confirm')
        ->and(DB::table('billing_journals')->count())->toBe(1);
    $bot->hearCallbackQueryData('finance:reverse:apply:'.$state['token'])->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('finance:reverse:apply:'.$state['token'])->reply()
        ->assertCalled('editMessageText');

    expect(DB::table('billing_topups')->where('id', $topup)->value('status'))->toBe('voided')
        ->and(DB::table('billing_journals')->where('kind', 'topup_reversal')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(4);

    $bot->hearCallbackQueryData('finance:wallet:'.$buyer->id)->reply()->assertRaw(
        static fn (Request $req): bool => str_contains(finReverseRich($req), 'اصلاح:')
            && str_contains(finReverseRich($req), '&lt;رسید&gt;')
            && str_contains(finReverseRich($req), 'Finance Root'), index: 1,
    );
});

it('blocks consumed credit and rejects forged, revoked, or group-chat callbacks', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $buyer = User::factory()->create();
    $bot = finReverseBot($root, 964801);
    $topup = app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $buyer->id, 500_000, 'SPENT-UI-500', 'Original bank deposit',
    );
    $walletId = (int) DB::table('billing_wallets')->where('user_id', $buyer->id)->value('id');
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'purchase',
        'idempotency_key' => 'test:used-wallet', 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $walletId,
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 10_000],
        ['journal_id' => $journal, 'wallet_id' => null,
            'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => 10_000],
    ]);

    $bot->hearCallbackQueryData('finance:reverse:prepare:'.$buyer->id.':'.$topup)->reply()->assertRaw(
        static fn (Request $req): bool => str_contains(finReverseRich($req), 'نیازمند بررسی')
            && ! str_contains(json_encode(finReverseRequest($req)['reply_markup'], JSON_THROW_ON_ERROR),
                'finance:reverse:apply:'), index: 1,
    );

    $admin = User::factory()->admin()->create();
    $other = finReverseBot($admin, 964899);
    $other->hearCallbackQueryData('finance:reverse:prepare:'.$buyer->id.':'.$topup)
        ->reply()->assertCalled('answerCallbackQuery');

    config()->set('super_admin.telegram_ids', ['964802', '964803']);
    $bot->hearCallbackQueryData('finance:reverse:prepare:'.$buyer->id.':'.$topup)
        ->reply()->assertCalled('answerCallbackQuery');

    $bot->setCommonChat(Chat::make(-964801, ChatType::GROUP));
    $bot->hearCallbackQueryData('finance:reverse:list:'.$buyer->id.':1')->reply()->assertNoReply();
    expect(DB::table('billing_journals')->where('kind', 'topup_reversal')->count())->toBe(0);
});
