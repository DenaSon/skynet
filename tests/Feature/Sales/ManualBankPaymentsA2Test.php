<?php

use App\Interfaces\Telegram\Support\PaymentDecisionTokens;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ConfigureRate;
use App\Modules\Sales\Application\CreatePendingOrder;
use App\Modules\Sales\Application\ManualBankPayments;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function a2Setup(): void
{
    app(ConfigureProduct::class)->handle('a2-vpn', 'اشتراک خصوصی');
    app(ConfigureRate::class)->handle('a2-vpn', 30, 100_000, 20_000);
    config()->set('super_admin.telegram_ids', ['990101', '990102']);
}

function a2Account(int $telegramId, string $role = 'member'): User
{
    if ($role !== 'member') {
        Role::findOrCreate($role, 'web');
    }

    $user = User::factory()->create();
    $user->syncRoles([$role]);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);

    return $user;
}

function a2Bot(int $id): Nutgram
{
    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($id, false, 'Tester'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

function a2Order(User $buyer): int
{
    $quote = app(CalculateQuote::class)->handle($buyer->id, 'a2-vpn', 30, 10);

    return app(CreatePendingOrder::class)->handle($buyer->id, $quote, bin2hex(random_bytes(16)))->id;
}

function a2Submit(int $id, int $orderId, int $messageId = 100): int
{
    return app(ManualBankPayments::class)->submit(
        SubscriptionViewer::telegram($id),
        $orderId,
        'telegram-file-'.$messageId,
        'unique-file-'.$messageId,
        $messageId,
    );
}

it('is disabled by default and leaves every order pending', function (): void {
    a2Setup();
    $buyer = a2Account(990110);
    $order = a2Order($buyer);

    expect(config('sales.manual_bank_review_enabled'))->toBeFalse()
        ->and(fn () => a2Submit(990110, $order))->toThrow(AuthorizationException::class);

    $bot = a2Bot(990110);
    $bot->hearCallbackQueryData('shop:pay:'.$order)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_payment_claims')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
});

it('stores encrypted receipt IDs, enforces ownership and accepts a single pending claim', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990111);
    a2Account(990112);
    $order = a2Order($buyer);
    $claim = a2Submit(990111, $order, 100);

    expect($claim)->toBeGreaterThan(0)
        ->and(a2Submit(990111, $order, 100))->toBe($claim)
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('photo_encrypted'))
        ->not->toContain('telegram-file-100');

    expect(fn () => a2Submit(990111, $order, 101))->toThrow(ValidationException::class)
        ->and(fn () => a2Submit(990112, $order, 102))->toThrow(ValidationException::class)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0);
});

it('requires trusted super-admin and a verified bank reference, then books balanced accounting and one outbox', function (): void {
    // Legacy explicit bank reference mode remains available when one-click is disabled.
    config()->set('sales.one_click_bank_review_enabled', false);
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990113);
    $root = a2Account(990101, 'super-admin');
    $other = a2Account(990114, 'admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990113, $order);

    Http::preventStrayRequests();
    Http::fake();

    expect(fn () => app(ManualBankPayments::class)->detail($other->id, $claim))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManualBankPayments::class)->review($root->id, $claim, true, null))->toThrow(ValidationException::class);

    $result = app(ManualBankPayments::class)->review($root->id, $claim, true, 'BANKA2X001');
    $amount = (int) DB::table('sales_orders')->where('id', $order)->value('total_price_rials');

    expect($result->approved)->toBeTrue()
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('paid')
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('state'))->toBe('pending')
        ->and(DB::table('billing_bank_reference_claims')->count())->toBe(1)
        ->and(DB::table('sales_bank_settlements')->where('sales_order_id', $order)->value('amount_rials'))->toBe($amount)
        ->and(DB::table('billing_postings')->where('account_code', 'cash_clearing')->where('side', 'debit')->sum('amount_rials'))->toBe($amount)
        ->and(DB::table('billing_postings')->where('account_code', 'order_liability')->where('side', 'credit')->sum('amount_rials'))->toBe($amount)
        ->and(DB::table('sales_payment_review_audits')->where('decision', 'approved')->count())->toBe(1);

    expect(fn () => app(ManualBankPayments::class)->review($root->id, $claim, true, 'BANKA2X001'))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('rejects receipt without marking order paid and permits a new genuine image', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990115);
    $root = a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990115, $order);

    $result = app(ManualBankPayments::class)->review($root->id, $claim, false);

    expect($result->approved)->toBeFalse()
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0)
        ->and(a2Submit(990115, $order, 101))->toBeGreaterThan($claim);

    expect(fn () => a2Submit(990115, $order, 100))->toThrow(ValidationException::class);
});

it('cannot credit the same bank transfer to another order or a reseller topup', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990116);
    $root = a2Account(990101, 'super-admin');
    $first = a2Order($buyer);
    $second = a2Order($buyer);
    $claimA = a2Submit(990116, $first, 100);
    $claimB = a2Submit(990116, $second, 101);

    app(ManualBankPayments::class)->review($root->id, $claimA, true, 'DUPA2TRANSFER');

    expect(fn () => app(ManualBankPayments::class)->review($root->id, $claimB, true, 'DUPA2TRANSFER'))
        ->toThrow(QueryException::class);

    Role::findOrCreate('reseller', 'web');
    $buyer->assignRole('reseller');
    $topup = app(RequestTopup::class)->handle($buyer->id, 1_000_000, 'a2:topup:test:1');
    app(SubmitTopupReference::class)->handle($buyer->id, $topup, 'DUPA2TRANSFER');
    expect(fn () => app(ReviewTopup::class)->handle($root->id, $topup, true, 'DUPA2TRANSFER'))
        ->toThrow(QueryException::class);

    expect(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('billing_verified_receipts')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $second)->value('status'))->toBe('pending_payment');
});

it('does not treat an existing wallet verified receipt as new direct bank income', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990117, 'reseller');
    $root = a2Account(990101, 'super-admin');
    $topup = app(RequestTopup::class)->handle($buyer->id, 1_000_000, 'a2:topup:test:2');
    app(SubmitTopupReference::class)->handle($buyer->id, $topup, 'BANKOLD123');
    app(ReviewTopup::class)->handle($root->id, $topup, true, 'BANKOLD123');

    $order = a2Order($buyer);
    $claim = a2Submit(990117, $order);

    expect(fn () => app(ManualBankPayments::class)->review($root->id, $claim, true, 'BANKOLD123'))
        ->toThrow(ValidationException::class)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);
});

it('denies revoked root, expired actor tokens and forged private chat identities', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990118);
    $root = a2Account(990101, 'super-admin');
    $other = a2Account(990102, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990118, $order);

    $token = app(PaymentDecisionTokens::class)->issue($root->id, $claim, true, 'BANK999888', null);
    expect(app(PaymentDecisionTokens::class)->consume($other->id, $token))->toBeNull();
    $this->travel(4)->minutes();
    expect(app(PaymentDecisionTokens::class)->consume($root->id, $token))->toBeNull();

    $root->forceFill(['is_active' => false])->save();
    expect(fn () => app(ManualBankPayments::class)->review($root->id, $claim, true, 'BANK999888'))
        ->toThrow(AuthorizationException::class);

    $bot = a2Bot(990118);
    $bot->setCommonChat(Chat::make(-990118, ChatType::GROUP));
    $bot->hearCallbackQueryData('shop:pay:'.$order)->reply()->assertNoReply();
    $bot->hearMessage(['message_id' => 500, 'photo' => [['file_id' => 'a2-file-abc', 'file_unique_id' => 'a2-uniq-abc', 'width' => 600, 'height' => 600]]])->reply()->assertNoReply();
});

it('handles the private Nutgram photo submission and root action without sending provider traffic', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990119);
    a2Account(990101, 'super-admin');
    a2Account(990102, 'super-admin');
    $order = a2Order($buyer);
    $bot = a2Bot(990119);
    Http::preventStrayRequests();
    Http::fake();

    $bot->hearCallbackQueryData('shop:pay:'.$order)->reply()->assertCalled('editMessageText');

    $bot->hearMessage(['message_id' => 501, 'photo' => [
        ['file_id' => 'photo-file-a2', 'file_unique_id' => 'photo-unique-a2', 'width' => 600, 'height' => 600],
    ]])->reply()->assertCalled('sendPhoto', 2); // Both trusted roots retain legacy alerts while delivery is OFF.

    expect(DB::table('sales_bank_payment_claims')->count())->toBe(1)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');

    $root = a2Bot(990101);
    $root->hearText('/payments')->reply()->assertCalled('sendMessage');
    $claim = (int) DB::table('sales_bank_payment_claims')->value('id');
    $root->hearCallbackQueryData('bank:show:'.$claim)->reply()->assertCalled('sendPhoto');
    // The legacy typed-command review path is retired. If automatic
    // provisioning is not ready, the final button must not settle money.
    $root->hearCallbackQueryData('bank:decide:'.$claim.':a')->reply()->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('submitted');
    Http::assertNothingSent();
});

it('stores direct bank payments in the same Billing journal and postings tables as wallet payments', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990123);
    $root = a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990123, $order, 123);
    app(ManualBankPayments::class)->review($root->id, $claim, true, 'B0BANKSHARED');

    $settlement = DB::table('sales_bank_settlements')->where('sales_order_id', $order)->first();
    $journal = DB::table('billing_journals')->where('id', $settlement->billing_journal_id)->first();

    expect(Schema::hasTable('sales_bank_postings'))->toBeFalse()
        ->and(Schema::hasTable('billing_direct_bank_postings'))->toBeFalse()
        ->and($journal->wallet_id)->toBeNull()
        ->and($journal->kind)->toBe('direct_bank_sale')
        ->and($journal->idempotency_key)->toBe('direct-bank:order:'.$order)
        ->and(DB::table('billing_postings')->where('journal_id', $journal->id)->count())->toBe(2)
        ->and(DB::table('billing_bank_reference_claims')->where('payment_reference', 'B0BANKSHARED')->value('source_type'))->toBe('sales_order');

    $debit = DB::table('billing_postings')->where('journal_id', $journal->id)->where('side', 'debit')->sum('amount_rials');
    $credit = DB::table('billing_postings')->where('journal_id', $journal->id)->where('side', 'credit')->sum('amount_rials');
    expect((int) $debit)->toBe((int) $credit);
});

it('rolls back both Billing ledger and Sales settlement if a downstream outbox insertion fails', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990124);
    $root = a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990124, $order, 124);

    DB::table('sales_fulfillment_outbox')->insert([
        'sales_order_id' => $order,
        'event_key' => 'sales-order:'.$order.':create',
        'state' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(ManualBankPayments::class)->review($root->id, $claim, true, 'B0ROLLBACK01'))
        ->toThrow(QueryException::class);

    expect(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('submitted')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_bank_reference_claims')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('sales_payment_review_audits')->count())->toBe(0);
});

it('blocks reuse of a reference reserved for a direct sale by a different wallet topup', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990125, 'reseller');
    $root = a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990125, $order, 125);

    app(ManualBankPayments::class)->review($root->id, $claim, true, 'B0SAMEBANKREF');

    $topup = app(RequestTopup::class)->handle($buyer->id, 1_000_000, 'b0:duplicate:topup');
    app(SubmitTopupReference::class)->handle($buyer->id, $topup, 'B0SAMEBANKREF');

    expect(fn () => app(ReviewTopup::class)->handle($root->id, $topup, true, 'B0SAMEBANKREF'))
        ->toThrow(QueryException::class);

    expect(DB::table('billing_verified_receipts')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'topup')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'direct_bank_sale')->count())->toBe(1);
});

it('acknowledges financial receipt-list callbacks once and preserves pending records', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);

    $buyer = a2Account(990131);
    a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990131, $order);

    $rootBot = a2Bot(990101);
    $rootBot->hearCallbackQueryData('bank:list')->reply()
        ->assertCalled('answerCallbackQuery', 1)
        ->assertCalled('editMessageText', 1);

    expect(DB::table('sales_bank_payment_claims')->where('id', $claim)->value('status'))->toBe('submitted')
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment')
        ->and(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $rootBot->hearText('/payments')->reply()->assertCalled('sendMessage');
});

it('requires the two-stage Admin bank confirmation and cannot settle before final approval', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('sales.one_click_bank_review_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    Queue::fake();
    Http::preventStrayRequests();
    Http::fake();

    $buyer = a2Account(990141);
    $root = a2Account(990101, 'super-admin');
    a2Account(990102, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990141, $order, 141);

    $admin = a2Bot(990101);
    $admin->hearCallbackQueryData('bank:decide:'.$claim.':a')->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');

    $admin->hearText('invalid?')->reply()->assertCalled('sendMessage');
    expect(DB::table('sales_bank_settlements')->count())->toBe(0);

    $token = null;
    $admin->hearText('VERIFIED-BANK-141')->reply()->assertRaw(
        static function (Request $request) use (&$token): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = $data['reply_markup'] ?? null;
            if (is_string($keyboard)) {
                $keyboard = json_decode($keyboard, true, flags: JSON_THROW_ON_ERROR);
            }
            $callback = $keyboard['inline_keyboard'][0][0]['callback_data'] ?? null;
            if (! is_string($callback) || ! str_starts_with($callback, 'bank:apply:')) {
                return false;
            }
            $token = substr($callback, strlen('bank:apply:'));

            return str_contains((string) ($data['text'] ?? ''), 'VERIFIED-BANK-141')
                && str_contains((string) ($data['text'] ?? ''), 'تأیید نهایی')
                && strlen($token) === 32;
        },
    );
    expect(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('billing_bank_reference_claims')->count())->toBe(0);

    $other = a2Bot(990102);
    $other->hearCallbackQueryData('bank:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_settlements')->count())->toBe(0);

    // a2Bot uses the shared Nutgram service; restore root-one identity.
    $admin = a2Bot(990101);
    $admin->hearCallbackQueryData('bank:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_settlements')->where('payment_reference', 'VERIFIED-BANK-141')->count())->toBe(1)
        ->and(DB::table('billing_bank_reference_claims')->where('payment_reference', 'VERIFIED-BANK-141')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('paid')
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(1)
        ->and(DB::table('sales_payment_review_audits')->where('decision', 'approved')->count())->toBe(1);

    $admin->hearCallbackQueryData('bank:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_settlements')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps the final bank approval token expiring without approving payment', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    config()->set('sales.one_click_bank_review_enabled', true);
    config()->set('sales.auto_fulfillment_enabled', true);
    config()->set('services.remnawave.writes_enabled', true);
    config()->set('services.remnawave.squad_bindings_live_verified', true);
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    $buyer = a2Account(990142);
    $root = a2Account(990101, 'super-admin');
    $order = a2Order($buyer);
    $claim = a2Submit(990142, $order, 142);
    $token = app(PaymentDecisionTokens::class)->issue($root->id, $claim, true, 'BANKEXPIRY142', null);

    $bot = a2Bot(990101);
    $this->travel(4)->minutes();
    $bot->hearCallbackQueryData('bank:apply:'.$token)->reply()->assertCalled('editMessageText');
    expect(DB::table('sales_bank_settlements')->count())->toBe(0)
        ->and(DB::table('sales_orders')->where('id', $order)->value('status'))->toBe('pending_payment');
});

it('paginates purchase receipts newest-first without hiding the thirteenth and later requests', function (): void {
    a2Setup();
    config()->set('sales.manual_bank_review_enabled', true);
    $buyer = a2Account(990143);
    $root = a2Account(990101, 'super-admin');
    $ids = [];
    for ($i = 1; $i <= 19; $i++) {
        $order = a2Order($buyer);
        $ids[] = a2Submit(990143, $order, 200 + $i);
    }
    $action = app(ManualBankPayments::class);
    $page1 = $action->pending($root->id, 9, 0);
    $page2 = $action->pending($root->id, 9, 8);
    $page3 = $action->pending($root->id, 9, 16);

    expect(count($page1))->toBe(9)
        ->and(count($page2))->toBe(9)
        ->and(count($page3))->toBe(3)
        ->and((int) $page1[0]->id)->toBe($ids[18])
        ->and((int) $page2[0]->id)->toBe($ids[10])
        ->and((int) $page3[0]->id)->toBe($ids[2])
        ->and((int) $page3[2]->id)->toBe($ids[0]);

    $bot = a2Bot(990101);
    $bot->hearCallbackQueryData('bank:list:1')->reply()
        ->assertCalled('answerCallbackQuery', 1)->assertCalled('editMessageText', 1);
    $bot->hearCallbackQueryData('bank:list:2')->reply()
        ->assertCalled('answerCallbackQuery', 1)->assertCalled('editMessageText', 1);
    $bot->hearCallbackQueryData('bank:list:3')->reply()
        ->assertCalled('answerCallbackQuery', 1)->assertCalled('editMessageText', 1);
    expect(DB::table('sales_bank_payment_claims')->where('status', 'submitted')->count())->toBe(19)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});
