<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\BillingReviewTokens;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;
use Tests\Support\PanelWalletFunding;

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

it('reuploads a private Sales top-up photo to Admin using the existing protected bridge', function (): void {
    $root = fin01Admin();
    $buyer = User::factory()->create();
    $bot = fin01Bot(987601, $root);
    $id = app(RequestTopup::class)->handle($buyer->id, 720000, 'fin01:admin:topup4');
    app(SubmitTopupPhoto::class)->handle($buyer->id, $id, 991004, 'secure-sales-file-id', 'secure-sales-photo-id', 126);

    $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGNgYGAAAAAEAAH2FzhVAAAAAElFTkSuQmCC', true);
    expect($image)->toBeString();
    // The default test fake is catch-all; replace it before asserting the actual media bridge.
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        'https://api.telegram.org/bot12345678:sales-secret/getFile' => Http::response([
            'ok' => true, 'result' => ['file_path' => 'photos/topup.png', 'file_size' => strlen($image)],
        ]),
        'https://api.telegram.org/file/bot12345678:sales-secret/photos/topup.png' => Http::response($image, 200, ['Content-Type' => 'image/png']),
        'https://api.telegram.org/bot87654321:admin-secret/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 1001]]),
    ]);

    $bot->hearCallbackQueryData('finance:topup:photo:'.$id)->reply()->assertCalled('answerCallbackQuery');
    Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/bot12345678:sales-secret/getFile'));
    Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/bot87654321:admin-secret/sendPhoto'));
    expect(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('submitted')
        ->and(DB::table('billing_postings')->count())->toBe(0);
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

it('summarizes the complete wallet ledger rather than just one page and flags negative balances', function (): void {
    $root = fin01Admin();
    $bot = fin01Bot(987601, $root);
    $users = User::factory()->count(10)->create();
    $walletIds = [];
    foreach ($users as $user) {
        $walletIds[] = (int) DB::table('billing_wallets')->insertGetId([
            'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    // The oldest funded wallet must now rank first despite its older ID.
    $users[0]->forceFill(['is_active' => false])->save();
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletIds[0], 'kind' => 'topup',
        'idempotency_key' => 'fin-stat-01-fixture', 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $walletIds[0],
            'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => 1_500_000],
        ['journal_id' => $journal, 'wallet_id' => $walletIds[0],
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 250_000],
        ['journal_id' => $journal, 'wallet_id' => $walletIds[1],
            'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => 350_000],
        ['journal_id' => $journal, 'wallet_id' => $walletIds[1],
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 500_000],
        // Different accounting account must never inflate customer wallet totals.
        ['journal_id' => $journal, 'wallet_id' => null,
            'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => 9_000_000],
    ]);
    DB::table('billing_topups')->insert([
        ['wallet_id' => $walletIds[2], 'request_key' => 'finstat-submitted',
            'amount_rials' => 8_000_000, 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now()],
        ['wallet_id' => $walletIds[3], 'request_key' => 'finstat-pending',
            'amount_rials' => 7_000_000, 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now()],
    ]);

    $summary = app(ReadAdminWalletSummary::class)->handle($root->id);
    expect($summary)->toBe([
        'balanceRials' => 1_100_000,
        'memberBalanceRials' => 1_100_000,
        'resellerBalanceRials' => 0,
        'otherBalanceRials' => 0,
        'walletsTotal' => 10,
        'walletsPositive' => 1,
        'walletsNegative' => 1,
        'pendingTopups' => 1,
    ]);

    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertRaw(static function (Request $req): bool {
        $data = fin01Data($req);

        return str_contains($data['text'], 'مجموع مانده کیف پول‌ها: 110,000 تومان')
            && str_contains($data['text'], 'کیف پول‌ها: 10 | دارای موجودی: 1')
            && str_contains($data['text'], 'شارژهای در انتظار تأیید: 1')
            && str_contains($data['text'], 'کیف پول با مانده منفی: 1')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:wallets:1');
    }, index: 1);

    foreach ([1, 2] as $page) {
        $bot->hearCallbackQueryData('finance:wallets:'.$page)->reply()
            ->assertRaw(static function (Request $req): bool {
                $data = fin01Data($req);

                return str_contains($data['text'], 'مجموع مانده: <b>110,000 تومان</b>')
                    && str_contains($data['text'], 'کل کیف پول‌ها: 10')
                    && str_contains($data['text'], 'دارای موجودی: 1')
                    && str_contains($data['text'], 'مانده منفی نیازمند بررسی: 1')
                    && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'menu:admin:finance');
            }, index: 1);
    }

    expect(DB::table('billing_postings')->count())->toBe(5)
        ->and(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('billing_topups')->count())->toBe(2);
    Http::assertNothingSent();
});

it('prioritizes funded older wallets across 19 rows and splits reseller and member totals', function (): void {
    $root = fin01Admin();
    $bot = fin01Bot(987601, $root);
    Role::findOrCreate('reseller', 'web');
    $users = User::factory()->count(19)->create();

    foreach ($users as $user) {
        app(ViewWallet::class)->handle($user->id);
    }
    $users[0]->syncRoles(['reseller']);
    $users[0]->forceFill(['is_active' => false])->save();
    $users[3]->syncRoles(['member', 'reseller']);
    $users[4]->syncRoles(['admin']);
    PanelWalletFunding::credit($users[0]->id, 7_500_000);
    PanelWalletFunding::credit($users[1]->id, 525_000);

    $summary = app(ReadAdminWalletSummary::class)->handle($root->id);
    expect($summary)->toBe([
        'balanceRials' => 8_025_000,
        'memberBalanceRials' => 525_000,
        'resellerBalanceRials' => 7_500_000,
        'otherBalanceRials' => 0,
        'walletsTotal' => 19,
        'walletsPositive' => 2,
        'walletsNegative' => 0,
        'pendingTopups' => 0,
    ]);

    $reader = app(\\App\\Modules\\Billing\\Application\\BrowseCustomerWallets::class);
    $first = $reader->page($root->id, 1);
    $second = $reader->page($root->id, 2);
    $third = $reader->page($root->id, 3);

    expect($first)->toHaveCount(9)
        ->and($second)->toHaveCount(9)
        ->and($third)->toHaveCount(3)
        ->and($first[0]['id'])->toBe($users[0]->id)
        ->and($first[0]['account_type'])->toBe('reseller')
        ->and($first[1]['id'])->toBe($users[1]->id)
        ->and($first[1]['account_type'])->toBe('member')
        ->and($first[2]['balance_rials'])->toBe(0)
        ->and($reader->detail($root->id, $users[0]->id)['account_type'])->toBe('reseller')
        ->and($reader->detail($root->id, $users[4]->id)['account_type'])->toBe('other');

    $visible = array_merge(array_slice($first, 0, 8), array_slice($second, 0, 8), $third);
    expect(array_column($visible, 'id'))->toHaveCount(19)
        ->and(count(array_unique(array_column($visible, 'id'))))->toBe(19)
        ->and(array_column($visible, 'balance_rials')[0])->toBe(7_500_000);

    $bot->hearCallbackQueryData('finance:wallets:1')->reply()->assertRaw(static function (Request $req) use ($users): bool {
        $data = fin01Data($req);
        $text = $data['text'];

        return str_contains($text, 'مجموع مانده: <b>802,500 تومان</b>')
            && str_contains($text, '👤 کاربران عادی: 52,500 تومان')
            && str_contains($text, '🧩 پنل‌های فروش: 750,000 تومان')
            && str_contains($text, '🧩 پنل فروش · #'.$users[0]->id)
            && str_contains($text, '👤 عادی · #'.$users[1]->id)
            && strpos($text, '#'.$users[0]->id.' ·') < strpos($text, '#'.$users[1]->id.' ·')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'finance:wallets:2');
    }, index: 1);
    $bot->hearCallbackQueryData('finance:wallet:'.$users[0]->id)->reply()->assertRaw(static function (Request $req): bool {
        $data = fin01Data($req);

        return str_contains($data['text'], 'نوع حساب: 🧩 پنل فروش')
            && str_contains($data['text'], '750,000 تومان');
    }, index: 1);

    expect(DB::table('billing_journals')->count())->toBe(2);
    Http::assertNothingSent();
});

it('keeps negative and unrelated-role wallet balances in their own group without double counting', function (): void {
    $root = fin01Admin();
    Role::findOrCreate('reseller', 'web');
    $member = User::factory()->create();
    $staff = User::factory()->admin()->create();
    $dual = User::factory()->create();
    $dual->syncRoles(['member', 'reseller']);

    PanelWalletFunding::credit($dual->id, 1_000_000);
    PanelWalletFunding::credit($staff->id, 250_000);
    PanelWalletFunding::spend($member->id, 100_000);

    $summary = app(ReadAdminWalletSummary::class)->handle($root->id);
    expect($summary['balanceRials'])->toBe(1_150_000)
        ->and($summary['memberBalanceRials'])->toBe(-100_000)
        ->and($summary['resellerBalanceRials'])->toBe(1_000_000)
        ->and($summary['otherBalanceRials'])->toBe(250_000)
        ->and($summary['walletsTotal'])->toBe(3)
        ->and($summary['walletsNegative'])->toBe(1);

    $rows = array_slice(app(\\App\\Modules\\Billing\\Application\\BrowseCustomerWallets::class)->page($root->id, 1), 0, 3);
    expect(array_column($rows, 'id'))->toBe([$dual->id, $staff->id, $member->id])
        ->and(array_column($rows, 'account_type'))->toBe(['reseller', 'other', 'member'])
        ->and(array_column($rows, 'balance_rials'))->toBe([1_000_000, 250_000, -100_000]);
});

it('shows zero when no wallets exist and denies revoked or ordinary Admin actors', function (): void {
    $root = fin01Admin();
    $bot = fin01Bot(987601, $root);
    expect(app(ReadAdminWalletSummary::class)->handle($root->id))->toBe([
        'balanceRials' => 0,
        'memberBalanceRials' => 0,
        'resellerBalanceRials' => 0,
        'otherBalanceRials' => 0,
        'walletsTotal' => 0,
        'walletsPositive' => 0,
        'walletsNegative' => 0,
        'pendingTopups' => 0,
    ]);
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertRaw(static function (Request $req): bool {
        $data = fin01Data($req);

        return str_contains($data['text'], 'مجموع مانده کیف پول‌ها: 0 تومان');
    }, index: 1);

    $ordinary = User::factory()->create();
    $ordinary->syncRoles(['admin']);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($ordinary->id))
        ->toThrow(AuthorizationException::class);

    $root->syncRoles(['admin']);
    expect(fn () => app(ReadAdminWalletSummary::class)->handle($root->id))
        ->toThrow(AuthorizationException::class);
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertCalled('answerCallbackQuery');
    $bot->hearCallbackQueryData('finance:wallets:1')->reply()->assertCalled('answerCallbackQuery');

    $bot->setCommonChat(Chat::make(-987601, ChatType::GROUP));
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertNoReply();
    Http::assertNothingSent();
});
