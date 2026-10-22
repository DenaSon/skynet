<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\AdminAccountingUi;
use App\Modules\Billing\Application\RecordBusinessEntry;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
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
    config()->set('super_admin.telegram_ids', ['996101', '996102']);
    config()->set('nutgram.token', '12345678:admin-token');
    config()->set('telegram.sales.token', '98765432:sales-token');
    config()->set('telegram.sales.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/admin/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'admin');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function acc01Root(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 996101]);

    return $user;
}

function acc01Bot(int $telegram, User $user): Nutgram
{
    TelegramAccount::query()->firstOrCreate(['user_id' => $user->id], ['telegram_user_id' => $telegram]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($telegram, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegram, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string,mixed> */
function acc01Payload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('stores only bookkeeping entries, idempotently and separately from customer ledger', function (): void {
    $root = acc01Root();
    $act = app(RecordBusinessEntry::class);
    $token = bin2hex(random_bytes(16));
    $first = $act->record($root->id, 'expense', 'server', 750_000, 'سرور آزمایشی', $token);
    $again = $act->record($root->id, 'expense', 'server', 750_000, 'سرور آزمایشی', $token);
    expect($again)->toBe($first)
        ->and(DB::table('accounting_entries')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    expect(fn () => $act->record($root->id, 'expense', 'server', 800_000, 'سرور آزمایشی', $token))
        ->toThrow(ValidationException::class);
    expect(fn () => $act->record($root->id, 'owner_draw', 'server', 100_000, null, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);
    expect(fn () => $act->record($root->id, 'expense', 'other', 90_000, null, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);

    $draw = $act->record($root->id, 'owner_draw', 'owner', 2_000_000, null, bin2hex(random_bytes(16)));
    expect($draw)->toBeGreaterThan($first)
        ->and($act->history($root->id)['total'])->toBe(2);
    Http::assertNothingSent();
});

it('preserves original entries and records a compensating correction at most once', function (): void {
    $root = acc01Root();
    $act = app(RecordBusinessEntry::class);
    $source = $act->record($root->id, 'expense', 'domain', 400_000, null, bin2hex(random_bytes(16)));
    $token = bin2hex(random_bytes(16));
    $reversal = $act->reverse($root->id, $source, $token);
    expect($act->reverse($root->id, $source, $token))->toBe($reversal)
        ->and(DB::table('accounting_entries')->count())->toBe(2)
        ->and((int) DB::table('accounting_entries')->where('id', $reversal)->value('reversal_of_id'))->toBe($source);
    expect(fn () => $act->reverse($root->id, $source, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);
    expect(fn () => $act->reverse($root->id, $reversal, bin2hex(random_bytes(16))))
        ->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('opens a private expense wizard with category, Persian amount, memo and a one-use confirmation', function (): void {
    $root = acc01Root();
    $bot = acc01Bot(996101, $root);
    $bot->hearCallbackQueryData('menu:admin:finance')->reply()->assertRaw(static function (Request $request): bool {
        $p = acc01Payload($request);

        return str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'acc:home');
    }, index: 1);
    $bot->hearCallbackQueryData('acc:home')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('acc:begin:expense')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('acc:category:server')->reply()->assertCalled('editMessageText');
    $bot->hearText('۲۵۰۰۰۰')->reply()->assertCalled('sendMessage');
    $bot->hearText('هزینه VPS')->reply()->assertRaw(static function (Request $request): bool {
        $p = acc01Payload($request);

        return str_contains($p['text'], '250,000 تومان')
            && str_contains($p['text'], 'هزینه VPS')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'acc:apply:')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), '"style":"success"');
    });

    $state = Cache::get('admin:accounting:draft:'.$root->id);
    expect($state['step'])->toBe('confirm')
        ->and(DB::table('accounting_entries')->count())->toBe(0);
    $bot->hearCallbackQueryData('acc:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(DB::table('accounting_entries')->count())->toBe(1)
        ->and(DB::table('accounting_entries')->value('amount_rials'))->toBe(2_500_000)
        ->and(DB::table('billing_postings')->count())->toBe(0);
    $bot->hearCallbackQueryData('acc:apply:'.$state['token'])->reply()->assertCalled('editMessageText');
    expect(DB::table('accounting_entries')->count())->toBe(1);

    $bot->hearCallbackQueryData('acc:history:1')->reply()->assertRaw(static function (Request $request): bool {
        $p = acc01Payload($request);

        return str_contains($p['text'], 'هزینه VPS')
            && str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), 'acc:reverse:prepare:1');
    }, index: 1);
    $bot->hearCallbackQueryData('acc:reverse:prepare:1')->reply()->assertRaw(static function (Request $request): bool {
        $p = acc01Payload($request);

        return str_contains(json_encode($p['reply_markup'], JSON_THROW_ON_ERROR), '"style":"success"');
    }, index: 1);
    $reverse = Cache::get('admin:accounting:draft:'.$root->id);
    $bot->hearCallbackQueryData('acc:reverse:apply:1:'.$reverse['token'])->reply()->assertCalled('editMessageText');
    expect(DB::table('accounting_entries')->count())->toBe(2);
    Http::assertNothingSent();
});

it('supports owner withdrawals and cancellation without any real payment or customer wallet debit', function (): void {
    $root = acc01Root();
    $bot = acc01Bot(996101, $root);
    $bot->hearCallbackQueryData('acc:begin:owner_draw')->reply()->assertCalled('editMessageText');
    $bot->hearText('1000000')->reply()->assertCalled('sendMessage');
    $bot->hearText('-')->reply()->assertCalled('sendMessage');
    $state = Cache::get('admin:accounting:draft:'.$root->id);
    expect($state['kind'])->toBe('owner_draw')
        ->and($state['memo'])->toBeNull();
    $bot->hearCallbackQueryData('acc:cancel')->reply()->assertCalled('editMessageText');
    expect(DB::table('accounting_entries')->count())->toBe(0)
        ->and(Cache::get('admin:accounting:draft:'.$root->id))->toBeNull();
    Http::assertNothingSent();
});

it('denies ordinary admin, revoked root and group before any bookkeeping write', function (): void {
    $root = acc01Root();
    $bot = acc01Bot(996101, $root);
    $ordinary = User::factory()->create();
    $ordinary->syncRoles(['admin']);
    $other = acc01Bot(996103, $ordinary);
    $other->hearCallbackQueryData('acc:home')->reply()->assertCalled('answerCallbackQuery');
    expect(fn () => app(RecordBusinessEntry::class)->record(
        $ordinary->id, 'expense', 'server', 500_000, null, bin2hex(random_bytes(16)),
    ))->toThrow(AuthorizationException::class);

    $root->syncRoles(['admin']);
    $bot->hearCallbackQueryData('acc:begin:expense')->reply()->assertCalled('answerCallbackQuery');
    expect(fn () => app(RecordBusinessEntry::class)->record(
        $root->id, 'expense', 'server', 500_000, null, bin2hex(random_bytes(16)),
    ))->toThrow(AuthorizationException::class);

    $bot->setCommonChat(Chat::make(-996101, ChatType::GROUP));
    $bot->hearCallbackQueryData('acc:home')->reply()->assertNoReply();
    expect(DB::table('accounting_entries')->count())->toBe(0);
    Http::assertNothingSent();
});

it('coordinates competing Admin text drafts and supports the inclusive 100-billion-toman amount limit', function (): void {
    $root = acc01Root();
    $bot = acc01Bot(996101, $root);
    // Existing Admin Node wizards are scoped by the runtime cache prefix.
    $nodeKey = TelegramBotContext::current()
        ->cacheKey('nw01:draft:'.$root->id);
    $financeKey = TelegramBotContext::current()
        ->cacheKey('telegram:finance:wallet:'.$root->id);
    Cache::put($nodeKey, ['stage' => 'password'], now()->addMinutes(10));
    Cache::put($financeKey, ['kind' => 'find'], now()->addMinutes(10));

    $bot->hearCallbackQueryData('acc:begin:owner_draw')->reply()->assertCalled('editMessageText');
    expect(Cache::has($nodeKey))->toBeFalse()
        ->and(Cache::has($financeKey))->toBeFalse();

    $bot->hearText('100000000000')->reply()->assertCalled('sendMessage');
    $state = Cache::get('admin:accounting:draft:'.$root->id);
    expect($state['amount_rials'])->toBe(1_000_000_000_000);

    $bot->hearCallbackQueryData('acc:begin:expense')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('acc:category:other')->reply()->assertCalled('editMessageText');

    // Another Admin wizard started after this accounting draft. The accounting
    // text router must yield the next amount to it instead of treating it as money.
    Cache::put($financeKey, ['kind' => 'find'], now()->addMinutes(10));
    expect(app(AdminAccountingUi::class)->text($bot, '12345'))->toBeFalse()
        ->and(Cache::has('admin:accounting:draft:'.$root->id))->toBeFalse();
});
