<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\BillingReviewTokens;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
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
    config()->set('super_admin.telegram_ids', ['987601', '987602']);
    config()->set('billing.topups_enabled', true);
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('nutgram.token', '87654321:admin-secret');
    config()->set('telegram.sales.token', '12345678:sales-secret');
    config()->set('telegram.sales.enabled', true);
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::ADMIN);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function fin01Bot(int $id, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $id]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Admin'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function fin01Admin(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);

    return $root;
}

/** @return array<string, mixed> */
function fin01Data(Request $req): array
{
    $data = FakeNutgram::getActualData($req);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('navigates the Admin Finance customer wallet read and bounded lookup with no write controls', function (): void {
    $root = fin01Admin();
    $customer = User::factory()->create(['name' => '<Buyer>']);
    $bot = fin01Bot(987601, $root);
    app(ViewWallet::class)->handle($customer->id);

    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertRaw(static function (Request $request): bool {
        $data = fin01Data($request);
        return str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:wallets:1');
    }, index: 1);
    $bot->hearCallbackQueryData('finance:wallets:1')->reply()->assertRaw(static function (Request $request) use ($customer): bool {
        $data = fin01Data($request);
        return str_contains($data['text'], '&lt;Buyer&gt;')
            && str_contains($data['text'], '0 تومان')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:wallet:'.$customer->id);
    }, index: 1);
    $bot->hearCallbackQueryData('finance:wallet:'.$customer->id)->reply()->assertRaw(static function (Request $request): bool {
        $data = fin01Data($request);
        return str_contains($data['text'], 'موجودی: <b>0 تومان</b>')
            && str_contains($data['text'], 'گردش‌های اخیر')
            && ! str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'adjust');
    }, index: 1);
    $bot->hearCallbackQueryData('finance:find-wallet')->reply()->assertCalled('editMessageText');
    $bot->hearText((string) $customer->id)->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_topups')->count())->toBe(0);
});

it('requires the real bank reference and one-use confirmation for a Sales photo topup', function (): void {
    $root = fin01Admin();
    $customer = User::factory()->create();
    $bot = fin01Bot(987601, $root);
    $id = app(RequestTopup::class)->handle($customer->id, 800000, 'fin01:admin:topup1');
    app(SubmitTopupPhoto::class)->handle($customer->id, $id, 991001, 'customer-file-id', 'customer-unique-id', 123);

    $bot->hearCallbackQueryData('finance:topups:1')->reply()->assertRaw(static function (Request $request) use ($id): bool {
        $data = fin01Data($request);
        return str_contains($data['text'], '80,000 تومان')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:topup:photo:'.$id);
    }, index: 1);
    $bot->hearCallbackQueryData('finance:topup:decision:'.$id.':a')->reply()->assertCalled('editMessageText');
    $bot->hearText('BAD')->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_journals')->count())->toBe(0);
    $bot->hearText('BANKVERIFIED9876')->reply()->assertRaw(static function (Request $request): bool {
        $data = fin01Data($request);
        return str_contains($data['text'], 'BANKVERIFIED9876')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:topup:apply:');
    });
    expect(DB::table('billing_journals')->count())->toBe(0);
    $token = app(BillingReviewTokens::class)->issue($root->id, $id, true, 'BANKVERIFIED9876');
    $bot->hearCallbackQueryData('finance:topup:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(app(ViewWallet::class)->handle($customer->id)->balanceRials)->toBe(800000)
        ->and(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2);
    $bot->hearCallbackQueryData('finance:topup:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_journals')->count())->toBe(1);
});

it('rejects a topup without credit and denies revoked, non-root or nonprivate actors', function (): void {
    $root = fin01Admin();
    $buyer = User::factory()->create();
    $id = app(RequestTopup::class)->handle($buyer->id, 900000, 'fin01:admin:topup2');
    app(SubmitTopupPhoto::class)->handle($buyer->id, $id, 991002, 'customer-file-id2', 'customer-unique-id2', 124);
    $bot = fin01Bot(987601, $root);
    $bot->hearCallbackQueryData('finance:topup:decision:'.$id.':r')->reply()->assertCalled('editMessageText');
    $token = app(BillingReviewTokens::class)->issue($root->id, $id, false, 'PHOTO-REJECT');
    $bot->hearCallbackQueryData('finance:topup:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('rejected')
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $root->syncRoles(['admin']);
    $bot->hearCallbackQueryData('finance:wallets:1')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('finance:topups:1')->reply()->assertCalled('answerCallbackQuery');

    $bot->setCommonChat(Chat::make(-55555, ChatType::GROUP));
    $bot->hearCallbackQueryData('finance:wallets:1')->reply()->assertNoReply();
    $bot->hearCallbackQueryData('finance:topup:decision:'.$id.':a')->reply()->assertNoReply();
    expect(DB::table('billing_journals')->count())->toBe(0);
});

it('does not expose a photo when Sales-to-Admin secure transfer is unavailable', function (): void {
    $root = fin01Admin();
    $buyer = User::factory()->create();
    $bot = fin01Bot(987601, $root);
    $id = app(RequestTopup::class)->handle($buyer->id, 950000, 'fin01:admin:topup3');
    app(SubmitTopupPhoto::class)->handle($buyer->id, $id, 991003, 'sales-file-123', 'sales-unique-id', 125);

    // The default fake blocks Telegram getFile. The UI must not invent a successful image preview.
    $bot->hearCallbackQueryData('finance:topup:photo:'.$id)->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('submitted')
        ->and(DB::table('billing_journals')->count())->toBe(0);
});
