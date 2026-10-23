<?php

declare(strict_types=1);

use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\RecordBusinessEntry;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tehran')->utc());
    config()->set('super_admin.telegram_ids', ['998201', '998202']);
    Http::preventStrayRequests();
});

function acc02Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 998201]);

    return $root;
}

function acc02Purchase(User $root, int $amount, string $method, Carbon $when): void
{
    $orderId = DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $root->id, 'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'acc02', 'product_name' => 'Accounting report fixture',
        'duration_days' => 30, 'traffic_gb' => 20,
        'public_price_rials' => $amount, 'discount_percent' => 0,
        'discount_rials' => 0, 'total_price_rials' => $amount,
        'status' => 'fulfilled', 'created_at' => $when, 'updated_at' => $when,
    ]);
    $journalId = DB::table('billing_journals')->insertGetId([
        'kind' => 'purchase', 'idempotency_key' => bin2hex(random_bytes(16)), 'created_at' => $when,
    ]);

    if ($method === 'wallet') {
        DB::table('sales_order_settlements')->insert([
            'sales_order_id' => $orderId, 'billing_journal_id' => $journalId,
            'method' => 'wallet', 'amount_rials' => $amount, 'settled_at' => $when,
        ]);

        return;
    }

    $claimId = DB::table('sales_bank_payment_claims')->insertGetId([
        'sales_order_id' => $orderId, 'buyer_user_id' => $root->id,
        'photo_encrypted' => 'fixture-only', 'photo_fingerprint' => hash('sha256', (string) $orderId),
        'telegram_message_key' => 'acc02-'.$orderId, 'status' => 'approved',
        'reviewed_by' => $root->id, 'reviewed_at' => $when,
        'created_at' => $when, 'updated_at' => $when,
    ]);
    DB::table('sales_bank_settlements')->insert([
        'sales_order_id' => $orderId, 'payment_claim_id' => $claimId,
        'billing_journal_id' => $journalId, 'verified_by' => $root->id,
        'payment_reference' => 'ACC02-'.$orderId,
        'amount_rials' => $amount, 'settled_at' => $when,
    ]);
}

/** @return array{root:User,reversal:int} */
function acc02Fixture(): array
{
    $root = acc02Root();
    $now = now();
    acc02Purchase($root, 1_000_000, 'bank', $now);
    acc02Purchase($root, 2_000_000, 'wallet', $now->copy()->subDays(2));
    acc02Purchase($root, 3_000_000, 'wallet', $now->copy()->subDays(10));
    // Inside the last 30 calendar days, but outside the current Tehran month.
    acc02Purchase($root, 1_000_000, 'bank',
        Carbon::parse('2026-09-28 18:00', 'Asia/Tehran')->utc());

    DB::table('billing_journals')->insert([
        'kind' => 'topup', 'idempotency_key' => 'acc02-topup', 'created_at' => now(),
    ]);

    $act = app(RecordBusinessEntry::class);
    $expenseNow = $act->record($root->id, 'expense', 'server', 800_000, null, bin2hex(random_bytes(16)));
    $expenseEarlier = $act->record($root->id, 'expense', 'domain', 700_000, null, bin2hex(random_bytes(16)));
    $drawNow = $act->record($root->id, 'owner_draw', 'owner', 400_000, null, bin2hex(random_bytes(16)));
    $drawEarlier = $act->record($root->id, 'owner_draw', 'owner', 600_000, null, bin2hex(random_bytes(16)));

    DB::table('accounting_entries')->where('id', $expenseEarlier)->update([
        'created_at' => $now->copy()->subDays(10),
    ]);
    DB::table('accounting_entries')->where('id', $drawEarlier)->update([
        'created_at' => $now->copy()->subDays(10),
    ]);
    $oldExpense = $act->record($root->id, 'expense', 'other', 300_000, null, bin2hex(random_bytes(16)));
    DB::table('accounting_entries')->where('id', $oldExpense)->update([
        'created_at' => $now->copy()->subDays(40),
    ]);
    $reversal = $act->reverse($root->id, $expenseEarlier, bin2hex(random_bytes(16)));

    expect(DB::table('accounting_entries')->where('id', $expenseNow)->value('reversal_of_id'))->toBeNull()
        ->and(DB::table('accounting_entries')->where('id', $drawNow)->value('reversal_of_id'))->toBeNull();

    return compact('root', 'reversal');
}

function acc02Bot(int $telegramId): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function acc02Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('reports 7, 30 and Persian-calendar-month periods from verified settlements and signed correction entries', function (): void {
    $data = acc02Fixture();
    $reports = app(ReadAdminAccountingReport::class);
    $seven = $reports->handle($data['root']->id, '7');
    $thirty = $reports->handle($data['root']->id, '30');
    $month = $reports->handle($data['root']->id, 'month');

    expect($seven['salesRials'])->toBe(3_000_000)
        ->and($seven['expensesRials'])->toBe(100_000)
        ->and($seven['drawsRials'])->toBe(400_000)
        ->and($seven['profitRials'])->toBe(2_900_000)
        ->and($seven['afterDrawsRials'])->toBe(2_500_000)
        ->and($seven['expenseEntries'])->toBe(1)
        ->and($seven['drawEntries'])->toBe(1)
        ->and($thirty['salesRials'])->toBe(7_000_000)
        ->and($thirty['expensesRials'])->toBe(800_000)
        ->and($thirty['drawsRials'])->toBe(1_000_000)
        ->and($thirty['profitRials'])->toBe(6_200_000)
        ->and($thirty['afterDrawsRials'])->toBe(5_200_000)
        ->and($month['salesRials'])->toBe(7_000_000)
        ->and($month['expensesRials'])->toBe(800_000)
        ->and($month['profitRials'])->toBe(6_200_000)
        ->and($month['afterDrawsRials'])->toBe(5_200_000)
        ->and($month['start'])->toBe('2026-09-23')
        ->and($month['end'])->toBe('2026-10-22');

    expect(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('accounting_entries')->count())->toBe(6)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(2)
        ->and(DB::table('sales_order_settlements')->count())->toBe(2);
    Http::assertNothingSent();
});

it('returns zero with no ledger activity and refuses foreign roles and invalid ranges', function (): void {
    $root = acc02Root();
    $report = app(ReadAdminAccountingReport::class)->handle($root->id, 'month');
    expect($report['salesRials'])->toBe(0)
        ->and($report['expensesRials'])->toBe(0)
        ->and($report['drawsRials'])->toBe(0)
        ->and($report['profitRials'])->toBe(0)
        ->and($report['afterDrawsRials'])->toBe(0);

    $ordinary = User::factory()->create();
    $ordinary->syncRoles(['admin']);
    expect(fn () => app(ReadAdminAccountingReport::class)->handle($ordinary->id, 'month'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReadAdminAccountingReport::class)->handle($root->id, 'year'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('navigates the three Finance reports only in a private trusted Admin chat', function (): void {
    acc02Fixture();
    $bot = acc02Bot(998201);
    $bot->hearCallbackQueryData('acc:home')->reply()->assertRaw(static function (NutgramRequest $req): bool {
        $body = acc02Payload($req);

        return str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'acc:report:month');
    }, index: 1);
    $bot->hearCallbackQueryData('acc:report:7')->reply()->assertRaw(static function (NutgramRequest $req): bool {
        $body = acc02Payload($req);

        return str_contains($body['text'], 'گزارش مالی · ۷ روز اخیر')
            && str_contains($body['text'], '۱۴۰۵/')
            && ! str_contains($body['text'], '2026-10-')
            && str_contains($body['text'], '290,000 تومان')
            && str_contains($body['text'], 'برداشت خالص مالک')
            && str_contains($body['text'], 'سود قطعی یا موجودی نقدی نیست')
            && str_contains(json_encode($body['reply_markup'], JSON_THROW_ON_ERROR), 'acc:report:month');
    }, index: 1);
    $bot->hearCallbackQueryData('acc:report:30')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('acc:report:month')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('acc:report:year')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('rejects ordinary admins, a revoked Super Admin and group reports before DB financial disclosure', function (): void {
    $root = acc02Root();
    $bot = acc02Bot(998201);
    $other = User::factory()->create();
    $other->syncRoles(['admin']);
    TelegramAccount::query()->create(['user_id' => $other->id, 'telegram_user_id' => 998203]);

    $ordinaryBot = acc02Bot(998203);
    $ordinaryBot->hearCallbackQueryData('acc:report:month')->reply()
        ->assertCalled('answerCallbackQuery');

    $root->syncRoles(['admin']);
    $bot->hearCallbackQueryData('acc:report:7')->reply()
        ->assertCalled('answerCallbackQuery');

    $bot->setCommonChat(Chat::make(-998201, ChatType::GROUP));
    $bot->hearCallbackQueryData('acc:report:30')->reply()->assertNoReply();
    Http::assertNothingSent();
});

it('starts a new Persian month at Tehran midnight, not the Gregorian month boundary', function (): void {
    $root = acc02Root();
    // 2026-10-23 00:10 in Tehran is 1405/08/01.
    $this->travelTo(Carbon::parse('2026-10-23 00:10:00', 'Asia/Tehran')->utc());
    acc02Purchase($root, 2_000_000, 'bank', Carbon::parse('2026-10-22 23:59:59', 'Asia/Tehran')->utc());
    acc02Purchase($root, 3_000_000, 'wallet', Carbon::parse('2026-10-23 00:00:00', 'Asia/Tehran')->utc());

    $action = app(ReadAdminAccountingReport::class);
    $month = $action->handle($root->id, 'month');
    $seven = $action->handle($root->id, '7');

    expect($month['start'])->toBe('2026-10-23')
        ->and($month['end'])->toBe('2026-10-23')
        ->and($month['salesRials'])->toBe(3_000_000)
        ->and($seven['salesRials'])->toBe(5_000_000)
        ->and(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('sales_order_settlements')->count())->toBe(1);
    Http::assertNothingSent();
});

it('uses first Farvardin as the accounting month boundary through Nowruz', function (): void {
    $root = acc02Root();
    $this->travelTo(Carbon::parse('2026-03-21 00:20:00', 'Asia/Tehran')->utc());
    acc02Purchase($root, 4_000_000, 'bank', Carbon::parse('2026-03-20 23:59:00', 'Asia/Tehran')->utc());
    acc02Purchase($root, 5_000_000, 'wallet', Carbon::parse('2026-03-21 00:00:00', 'Asia/Tehran')->utc());
    $month = app(ReadAdminAccountingReport::class)->handle($root->id, 'month');

    expect($month['start'])->toBe('2026-03-21')
        ->and($month['end'])->toBe('2026-03-21')
        ->and($month['salesRials'])->toBe(5_000_000);
    Http::assertNothingSent();
});
