<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\BrowseUsers;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['953001', '953002']);
});

function rankingRoot(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 953001]);

    return $user;
}

function rankingBot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->firstOrCreate(
        ['user_id' => $user->id], ['telegram_user_id' => $telegramId],
    );
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** Balanced fixture postings use the same wallet-liability account as production. */
function rankingFund(User $user, int $rials): void
{
    app(ViewWallet::class)->handle($user->id);
    $walletId = (int) DB::table('billing_wallets')->where('user_id', $user->id)->value('id');
    $journal = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'topup',
        'idempotency_key' => 'ranking:credit:'.$user->id, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $walletId, 'account_code' => 'wallet_liability', 'side' => 'credit', 'amount_rials' => $rials],
        ['journal_id' => $journal, 'wallet_id' => $walletId, 'account_code' => 'cash', 'side' => 'debit', 'amount_rials' => $rials],
    ]);
}

/** A paid order alone must not count as a settled purchase. */
function rankingOrder(User $buyer, int $rials, string $status): int
{
    return DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'ranking-test', 'product_name' => 'Ranking test',
        'duration_days' => 30, 'traffic_gb' => 10,
        'public_price_rials' => $rials, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => $rials,
        'status' => $status, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rankingJournal(string $key): int
{
    return DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => $key, 'created_at' => now(),
    ]);
}

function rankingWalletSettlement(int $order, int $rials): void
{
    DB::table('sales_order_settlements')->insert([
        'sales_order_id' => $order, 'billing_journal_id' => rankingJournal('ranking:wallet:'.$order),
        'method' => 'wallet', 'amount_rials' => $rials, 'settled_at' => now(),
    ]);
}

function rankingBankSettlement(User $buyer, User $root, int $order, int $rials): void
{
    $claim = DB::table('sales_bank_payment_claims')->insertGetId([
        'sales_order_id' => $order, 'buyer_user_id' => $buyer->id,
        'photo_encrypted' => 'fixture', 'photo_fingerprint' => hash('sha256', 'rank-photo-'.$order),
        'telegram_message_key' => 'ranking:message:'.$order, 'status' => 'approved',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('sales_bank_settlements')->insert([
        'sales_order_id' => $order, 'payment_claim_id' => $claim,
        'billing_journal_id' => rankingJournal('ranking:bank:'.$order),
        'verified_by' => $root->id, 'payment_reference' => 'RANK-BANK-'.$order,
        'amount_rials' => $rials, 'settled_at' => now(),
    ]);
}

it('sorts complete wallet ledger before pagination, treats missing wallets as zero and keeps stable ties', function (): void {
    $root = rankingRoot();
    $older = User::factory()->create(['name' => 'Older funded']);
    $newer = User::factory()->create(['name' => 'Newer funded']);
    User::factory()->count(22)->create();
    rankingFund($older, 9_000_000);
    rankingFund($newer, 4_000_000);

    $directory = app(BrowseUsers::class);
    $recent = $directory->page($root->id);
    $ranked = $directory->page($root->id, mode: 'aw');
    $secondPage = $directory->page($root->id, page: 2, mode: 'aw');

    expect($recent->users[0]->id)->not->toBe($older->id)
        ->and($ranked->users)->toHaveCount(20)
        ->and($ranked->users[0]->id)->toBe($older->id)
        ->and($ranked->users[0]->directoryMetricRials)->toBe(9_000_000)
        ->and($ranked->users[1]->id)->toBe($newer->id)
        ->and($ranked->users[1]->directoryMetricRials)->toBe(4_000_000)
        ->and($ranked->users[2]->directoryMetricRials)->toBe(0)
        ->and($ranked->users[2]->id)->toBeGreaterThan($older->id)
        ->and($ranked->total)->toBe(25)
        ->and($secondPage->users)->toHaveCount(5)
        ->and($secondPage->users[0]->directoryMetricRials)->toBe(0)
        ->and($secondPage->users[0]->id)->toBeGreaterThan($secondPage->users[1]->id)
        ->and($secondPage->users[0]->id)->not->toBe($ranked->users[19]->id);
});

it('ranks only verified bank/wallet settlements and excludes refunded or unpaid amounts', function (): void {
    $root = rankingRoot();
    $leader = User::factory()->create();
    $runner = User::factory()->create();

    rankingWalletSettlement(rankingOrder($leader, 2_000_000, 'fulfilled'), 2_000_000);
    rankingBankSettlement($leader, $root, rankingOrder($leader, 500_000, 'paid'), 500_000);
    rankingWalletSettlement(rankingOrder($leader, 8_000_000, 'refunded'), 8_000_000);
    rankingOrder($runner, 99_000_000, 'paid'); // No verified settlement.
    rankingWalletSettlement(rankingOrder($runner, 1_500_000, 'fulfilled'), 1_500_000);

    $page = app(BrowseUsers::class)->page($root->id, mode: 'ap');
    expect($page->users[0]->id)->toBe($leader->id)
        ->and($page->users[0]->directoryMetricRials)->toBe(2_500_000)
        ->and($page->users[1]->id)->toBe($runner->id)
        ->and($page->users[1]->directoryMetricRials)->toBe(1_500_000);
});

it('keeps member and reseller filters distinct and preserves sorted navigation to user profile', function (): void {
    $root = rankingRoot();
    $member = User::factory()->create(['name' => 'Member ranking']);
    Role::findOrCreate('reseller', 'web');
    $reseller = User::factory()->create(['name' => 'Reseller ranking']);
    $reseller->syncRoles(['member', 'reseller']);
    $admin = User::factory()->admin()->create();

    rankingFund($member, 3_000_000);
    rankingFund($reseller, 5_000_000);

    $directory = app(BrowseUsers::class);
    $ordinary = $directory->page($root->id, mode: 'mw');
    $resellers = $directory->page($root->id, mode: 'rw');
    expect(collect($ordinary->users)->pluck('id')->all())->toContain($member->id)
        ->not->toContain($reseller->id, $admin->id)
        ->and($resellers->users[0]->id)->toBe($reseller->id);

    $bot = rankingBot(953001, $root);
    $bot->hearCallbackQueryData('users:filtered:mw:1')->reply()->assertRaw(
        static function (Request $request) use ($member): bool {
            $data = FakeNutgram::getActualData($request);
            $markup = json_encode($data['reply_markup'], JSON_THROW_ON_ERROR);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains((string) ($rich['html'] ?? ''), 'بیشترین موجودی')
                && str_contains($markup, 'users:view:'.$member->id.':1:mw')
                && str_contains($markup, 'users:sort:mw')
                && str_contains($markup, 'users:filtered:rw:1');
        }, index: 0,
    );

    $bot->hearCallbackQueryData('users:view:'.$member->id.':1:mw')->reply()->assertRaw(
        static function (Request $request) use ($member): bool {
            $markup = json_encode(FakeNutgram::getActualData($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($markup, 'users:profile:'.$member->id.':1:mw')
                && str_contains($markup, 'users:confirm:'.$member->id.':1:mw')
                && str_contains($markup, 'users:filtered:mw:1');
        }, index: 1,
    );
    $bot->hearCallbackQueryData('users:profile:'.$member->id.':1:mw')->reply()
        ->assertCalled('editMessageText');
});

it('denies financial ranking and forged sorted detail callbacks after trusted-root loss', function (): void {
    $root = rankingRoot();
    $regularAdmin = User::factory()->admin()->create();
    $member = User::factory()->create();
    $rootBot = rankingBot(953001, $root);

    expect(fn () => app(BrowseUsers::class)->page($regularAdmin->id, mode: 'ap'))
        ->toThrow(AuthorizationException::class);
    $rootBot->hearCallbackQueryData('users:filtered:ap:1')->reply()->assertCalled('editMessageText');
    config()->set('super_admin.telegram_ids', ['953002', '953003']);

    $rootBot->hearCallbackQueryData('users:filtered:ap:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $rootBot->hearCallbackQueryData('users:view:'.$member->id.':1:ap')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);
    $rootBot->hearCallbackQueryData('users:profile:'.$member->id.':1:ap')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.'])
        ->assertCalled('editMessageText', 0);

    $adminBot = rankingBot(953004, $regularAdmin);
    $adminBot->hearCallbackQueryData('users:page:1')->reply()->assertCalled('editMessageText');
    $adminBot->hearCallbackQueryData('users:sort:a')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});

it('offers independent filter and direction controls while preserving authorized user navigation', function (): void {
    $root = rankingRoot();
    $target = User::factory()->create(['name' => 'Follow-up target', 'is_active' => false]);
    $bot = rankingBot(953001, $root);

    $bot->hearCallbackQueryData('users:sort:aw')->reply()->assertRaw(
        static function (Request $request): bool {
            $markup = json_encode(FakeNutgram::getActualData($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($markup, 'users:filtered:awu:1')
                && str_contains($markup, 'users:filtered:aw:1');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:filters:awu')->reply()->assertRaw(
        static function (Request $request): bool {
            $markup = json_encode(FakeNutgram::getActualData($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($markup, 'users:filtered:awub:1')
                && str_contains($markup, 'users:filtered:awuq:1')
                && str_contains($markup, 'users:filtered:awu:1');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:filtered:ai:1')->reply()->assertRaw(
        static function (Request $request) use ($target): bool {
            $markup = json_encode(FakeNutgram::getActualData($request)['reply_markup'], JSON_THROW_ON_ERROR);

            return str_contains($markup, 'users:view:'.$target->id.':1:ai')
                && str_contains($markup, 'users:filters:ai')
                && str_contains($markup, 'users:sort:ai');
        },
        index: 1,
    );

    $bot->hearCallbackQueryData('users:view:'.$target->id.':1:ai')->reply()
        ->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('users:profile:'.$target->id.':1:ai')->reply()
        ->assertCalled('editMessageText');
});

it('refuses forged filter controls when the admin lacks trusted-root permissions', function (): void {
    $regular = User::factory()->admin()->create();
    $bot = rankingBot(953004, $regular);

    $bot->hearCallbackQueryData('users:filters:a')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    $bot->hearCallbackQueryData('users:filtered:ab:1')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});
