<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
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
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('billing.topups_enabled', true);
    config()->set('billing.payment_card_number', '6037991234567890');
    config()->set('billing.payment_instructions', 'به نام <Finance & Team>');
    config()->set('sales.manual_bank_review_enabled', true);
    Http::preventStrayRequests();
    Http::fake();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

/** Make a real Sales-only Nutgram test bot bound to a real user. */
function wallet02Bot(int $telegramId, User $user): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Wallet Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

it('offers private step-by-step wallet recharge and records photo without any credit', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985101, $buyer);
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply()->assertCalled('editMessageText');
    $bot->hearText('۵۰۰۰۰')->reply()->assertCalled('sendMessage');

    $topup = DB::table('billing_topups')->first();
    expect($topup)->not->toBeNull()
        ->and((int) $topup->amount_rials)->toBe(500000)
        ->and($topup->status)->toBe('pending')
        ->and(DB::table('billing_journals')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$topup->id)->reply()->assertCalled('editMessageText');
    $bot->hearMessage(['message_id' => 101, 'photo' => [
        ['file_id' => 'wallet-photo-file', 'file_unique_id' => 'wallet-unique-photo', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText("<b>🕘 رسید شارژ #{$topup->id} ثبت شد</b>\n\nوضعیت: <b>در انتظار بررسی</b>\nاعتبار پس از تأیید واریز توسط مدیر اضافه می‌شود. تا آن زمان موجودی تغییری نمی‌کند.");

    expect(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_topups')->value('status'))->toBe('submitted')
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertCalled('editMessageText');
    Http::assertNothingSent();
});

it('keeps top-up input distinct from purchase payment and does not accept foreign or expired photo', function (): void {
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $bot = wallet02Bot(985102, $buyer);
    $foreign = wallet02Bot(985103, $stranger);

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply()->assertCalled('editMessageText');
    $bot->hearText('50000')->reply()->assertCalled('sendMessage');

    $foreign->hearMessage(['message_id' => 200, 'photo' => [
        ['file_id' => 'foreign-wallet-photo', 'file_unique_id' => 'foreign-wallet-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('billing_topup_photos')->count())->toBe(0);

    $bot->hearText('/cancel')->reply()->assertCalled('sendRichMessage', 1);
    $bot->hearMessage(['message_id' => 201, 'photo' => [
        ['file_id' => 'cancelled-photo-id', 'file_unique_id' => 'cancelled-photo-uid', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:new')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply()->assertCalled('editMessageText');
    $bot->hearText('50000')->reply()->assertCalled('sendMessage');
    $this->travel(11)->minutes();
    $id = (int) DB::table('billing_topups')->orderByDesc('id')->value('id');
    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$id)->reply()->assertCalled('editMessageText');
    $bot->hearMessage(['message_id' => 202, 'photo' => [
        ['file_id' => 'expired-photo-id', 'file_unique_id' => 'expired-photo-uid', 'width' => 400, 'height' => 400],
    ]])->reply()->assertNoReply();
    expect(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps wallet photo submission closed if top-up flag is off', function (): void {
    $user = User::factory()->create();
    $bot = wallet02Bot(985104, $user);
    config()->set('billing.topups_enabled', false);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(Cache::get(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$user->id)))->toBeNull();
    Http::assertNothingSent();
});

it('shows preset amounts, requires the receipt button, and keeps duplicate taps on one request', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985105, $buyer);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:1000000')->reply()->assertCalled('editMessageText');

    $topup = DB::table('billing_topups')->sole();
    expect((int) $topup->amount_rials)->toBe(10000000)->and($topup->status)->toBe('pending');

    $bot->hearMessage(['message_id' => 302, 'photo' => [
        ['file_id' => 'quick-before-button', 'file_unique_id' => 'quick-before-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText('برای ارسال عکس ابتدا دکمه «📷 ارسال رسید شارژ» را بزنید.');
    expect(DB::table('billing_topup_photos')->count())->toBe(0);

    $bot->hearCallbackQueryData('sales:wallet:topup:quick:1000000')->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->count())->toBe(1);

    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$topup->id)->reply()->assertCalled('editMessageText');
    $bot->hearMessage(['message_id' => 303, 'photo' => [
        ['file_id' => 'quick-after-button', 'file_unique_id' => 'quick-after-id', 'width' => 400, 'height' => 400],
    ]])->reply()->assertCalled('sendMessage');
    expect(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_topups')->value('status'))->toBe('submitted')
        ->and(DB::table('billing_journals')->count())->toBe(0);
});

it('supports the 750 thousand preset and caps custom amounts at 10 million tomans', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985106, $buyer);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:750000')->reply()->assertCalled('editMessageText');
    expect((int) DB::table('billing_topups')->value('amount_rials'))->toBe(7500000);

    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:new')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply()->assertCalled('editMessageText');
    $bot->hearText('۱۰۰۰۰۰۰۱')->reply()->assertReplyText('مبلغ شارژ باید بین ۱۰ هزار تا ۱۰ میلیون تومان باشد.');
    expect(DB::table('billing_topups')->count())->toBe(1);
    $bot->hearText('۱۰۰۰۰۰۰۰')->reply()->assertCalled('sendMessage');
    expect((int) DB::table('billing_topups')->orderByDesc('id')->value('amount_rials'))->toBe(100000000);
});

it('clears a cancelled topup input without creating a financial request', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985107, $buyer);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:custom')->reply();
    $key = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyer->id);
    expect(Cache::has($key))->toBeTrue();
    $bot->hearCallbackQueryData('sales:wallet:topup:cancel')->reply()->assertCalled('editMessageText');
    expect(Cache::has($key))->toBeFalse();
    $bot->hearText('500000')->reply();
    expect(DB::table('billing_topups')->count())->toBe(0);
});

it('resumes an owned pending topup from durable SQL without making a second financial request', function (): void {
    $buyer = User::factory()->create();
    $other = User::factory()->create();
    $bot = wallet02Bot(985108, $buyer);
    $foreign = wallet02Bot(985109, $other);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:750000')->reply();
    $id = (int) DB::table('billing_topups')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyer->id);

    Cache::forget($key);
    $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request) use ($id): bool {
        $data = FakeNutgram::getActualData($request);
        $rich = is_string($data['rich_message'] ?? null)
            ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
            : ($data['rich_message'] ?? []);

        return str_contains((string) ($rich['html'] ?? ''), 'data="sales:wallet:topup:resume:'.$id.'"')
            && str_contains((string) ($rich['html'] ?? ''), 'style="success"');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertRaw(static function (Request $request) use ($id): bool {
        $keyboard = json_encode(FakeNutgram::getActualData($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

        return str_contains($keyboard, 'sales:wallet:topup:resume:'.$id)
            && str_contains($keyboard, 'sales:wallet:topup:new')
            && ! str_contains($keyboard, 'sales:wallet:topup:quick:');
    }, index: 1);
    expect(DB::table('billing_topups')->count())->toBe(1);

    $foreign->hearCallbackQueryData('sales:wallet:topup:resume:'.$id)->reply()->assertCalled('editMessageText');
    expect(Cache::has(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$other->id)))->toBeFalse();

    $bot->hearCallbackQueryData('sales:wallet:topup:resume:'.$id)->reply()->assertCalled('editMessageText');
    expect(Cache::get($key))->toBe(['step' => 'payment', 'topup_id' => $id, 'amount_toman' => 750000]);
    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$id)->reply();
    $bot->hearMessage(['message_id' => 108, 'photo' => [
        ['file_id' => 'resumed-pending-photo', 'file_unique_id' => 'resumed-unique-photo', 'width' => 400, 'height' => 400],
    ]])->reply()->assertCalled('sendMessage');

    expect(DB::table('billing_topups')->count())->toBe(1)
        ->and(DB::table('billing_topups')->value('status'))->toBe('submitted')
        ->and(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    $bot->hearCallbackQueryData('sales:wallet:topup:resume:'.$id)->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topup_photos')->count())->toBe(1);
    Http::assertNothingSent();
});

it('disarms topup intake on back and cancel and only creates a second request after explicit new', function (): void {
    $buyer = User::factory()->create();
    $bot = wallet02Bot(985110, $buyer);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:1000000')->reply();
    $id = (int) DB::table('billing_topups')->value('id');
    $key = TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyer->id);

    $bot->hearCallbackQueryData('sales:wallet:topup:receipt:'.$id)->reply();
    $bot->hearCallbackQueryData('sales:wallet:topup:back:'.$id)->reply()->assertCalled('editMessageText');
    expect(Cache::get($key)['step'])->toBe('payment');
    $bot->hearMessage(['message_id' => 111, 'photo' => [
        ['file_id' => 'back-topup-photo', 'file_unique_id' => 'back-topup-unique', 'width' => 400, 'height' => 400],
    ]])->reply()->assertReplyText('برای ارسال عکس ابتدا دکمه «📷 ارسال رسید شارژ» را بزنید.');
    $bot->hearText('/cancel')->reply()->assertCalled('sendRichMessage', 1);
    expect(Cache::has($key))->toBeFalse()
        ->and(DB::table('billing_topups')->count())->toBe(1);
    $bot->hearCallbackQueryData('sales:wallet:topup')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:1000000')->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->count())->toBe(1);

    $bot->hearCallbackQueryData('sales:wallet:topup:new')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:wallet:topup:quick:750000')->reply()->assertCalled('editMessageText');
    expect(DB::table('billing_topups')->count())->toBe(2)
        ->and(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('shows green in-content topup amounts, a single green add-credit action and a usable native fallback for members and resellers', function (): void {
    foreach ([false, true] as $reseller) {
        $buyer = User::factory()->create();
        if ($reseller) {
            Role::findOrCreate('reseller', 'web');
            $buyer->syncRoles(['reseller']);
        }
        $bot = wallet02Bot($reseller ? 985184 : 985183, $buyer);
        $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
        $bot->hearCallbackQueryData('sales:wallet:topup:quick:750000')->reply();
        $first = (int) DB::table('billing_topups')->where('wallet_id', DB::table('billing_wallets')->where('user_id', $buyer->id)->value('id'))->value('id');
        $bot->hearCallbackQueryData('sales:wallet:topup')->reply();
        $bot->hearCallbackQueryData('sales:wallet:topup:new')->reply();
        $bot->hearCallbackQueryData('sales:wallet:topup:quick:1000000')->reply();
        $second = (int) DB::table('billing_topups')->where('wallet_id', DB::table('billing_wallets')->where('user_id', $buyer->id)->value('id'))->orderByDesc('id')->value('id');

        $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request) use ($first, $second): bool {
            $data = FakeNutgram::getActualData($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');
            $keyboard = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $buttons = $keyboard['inline_keyboard'] ?? [];

            return str_contains($html, '📷 تکمیل شارژ · 750,000 تومان')
                && str_contains($html, '📷 تکمیل شارژ · 1,000,000 تومان')
                && str_contains($html, 'style="success" data="sales:wallet:topup:resume:'.$first.'"')
                && str_contains($html, 'style="success" data="sales:wallet:topup:resume:'.$second.'"')
                && str_contains($html, 'در انتظار ارسال رسید')
                && ! str_contains($html, 'درخواست شارژ در انتظار بررسی')
                && ($buttons[count($buttons) - 1][0]['callback_data'] ?? null) === 'sales:wallet:topup'
                && ($buttons[count($buttons) - 1][0]['style'] ?? null) === 'success'
                && ! str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'sales:wallet:topup:resume:')
                && ! str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'sales:wallet:simple')
                && ! str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'sales:account')
                && ! str_contains(json_encode($buttons, JSON_THROW_ON_ERROR), 'sales:home');
        }, index: 1);

        $bot->hearCallbackQueryData('sales:wallet:simple')->reply()->assertRaw(static function (Request $request) use ($first, $second): bool {
            $data = FakeNutgram::getActualData($request);
            $keyboard = is_string($data['reply_markup'] ?? null)
                ? json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['reply_markup'] ?? []);
            $buttons = $keyboard['inline_keyboard'] ?? [];
            $text = (string) ($data['text'] ?? '');

            return ! isset($data['rich_message'])
                && str_contains($text, 'در انتظار ارسال رسید')
                && str_contains($buttons[0][0]['text'], '1,000,000 تومان')
                && $buttons[0][0]['callback_data'] === 'sales:wallet:topup:resume:'.$second
                && str_contains($buttons[1][0]['text'], '750,000 تومان')
                && $buttons[1][0]['callback_data'] === 'sales:wallet:topup:resume:'.$first
                && $buttons[count($buttons) - 1][0]['callback_data'] === 'sales:wallet:topup'
                && $buttons[count($buttons) - 1][0]['style'] === 'success';
        }, index: 1);

        DB::table('billing_topups')->where('id', $first)->update(['status' => 'submitted']);
        $bot->hearCallbackQueryData('sales:wallet')->reply()->assertRaw(static function (Request $request) use ($first, $second): bool {
            $data = FakeNutgram::getActualData($request);
            $rich = is_string($data['rich_message'] ?? null)
                ? json_decode($data['rich_message'], true, flags: JSON_THROW_ON_ERROR)
                : ($data['rich_message'] ?? []);
            $html = (string) ($rich['html'] ?? '');

            return str_contains($html, '🧾 سوابق شارژ')
                && str_contains($html, '750,000 تومان · در انتظار بررسی')
                && ! str_contains($html, 'data="sales:wallet:topup:resume:'.$first.'"')
                && str_contains($html, 'data="sales:wallet:topup:resume:'.$second.'"');
        }, index: 1);
    }

    expect(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});
