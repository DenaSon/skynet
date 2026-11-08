<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManageAnnouncements;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    config()->set('super_admin.telegram_ids', ['994801', '994899']);
    Http::preventStrayRequests();
});

function annUi01Bot(User $user, int $telegramId): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/admin.php');
    require base_path('app/Interfaces/Telegram/routes/legacy_text.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Admin'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

/** @return array<string, mixed> */
function annUi01Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('exposes only the root control-panel entry and rejects a delegated operator callback', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annUi01Bot($root, 994801);

    $bot->hearCallbackQueryData('menu:admin:control')->reply()
        ->assertRaw(static fn (NutgramRequest $request): bool => str_contains(
            json_encode(annUi01Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR),
            'admin:ann:list:1',
        ), index: 1);

    $operator = User::factory()->create();
    $operatorBot = annUi01Bot($operator, 994805);
    $operatorBot->hearCallbackQueryData('admin:ann:new')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
    expect(DB::table('announcements')->count())->toBe(0);
    Http::assertNothingSent();
});

it('creates a draft through the two-step text wizard and rejects a replayed save', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annUi01Bot($root, 994801);

    $bot->hearCallbackQueryData('admin:ann:new')->reply();
    expect(Cache::get('admin:ann:draft:'.$root->id)['step'])->toBe('title');

    $bot->hearText('سرور جدید')->reply();
    expect(Cache::get('admin:ann:draft:'.$root->id)['step'])->toBe('body');

    $bot->hearText("توضیح خط اول\nتوضیح خط دوم")->reply();
    $draft = Cache::get('admin:ann:draft:'.$root->id);
    expect($draft['step'])->toBe('preview')
        ->and($draft['body'])->toBe("توضیح خط اول\nتوضیح خط دوم");
    $callback = 'admin:ann:save:'.$draft['token'];

    $bot->hearCallbackQueryData($callback)->reply();
    $bot->hearCallbackQueryData($callback)->reply();
    expect(DB::table('announcements')->count())->toBe(1)
        ->and(DB::table('announcements')->value('status'))->toBe('draft')
        ->and(Cache::has('admin:ann:draft:'.$root->id))->toBeFalse();
    Http::assertNothingSent();
});

it('publishes only after one-use confirmation and blocks a stale unpublish', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annUi01Bot($root, 994801);
    $id = app(ManageAnnouncements::class)->create($root->id, 'خبر تست', 'متن تست');

    $bot->hearCallbackQueryData('admin:ann:prepare:'.$id.':publish')->reply();
    $pending = Cache::get('admin:ann:transition:'.$root->id);
    expect($pending['action'])->toBe('publish');
    $bot->hearCallbackQueryData('admin:ann:apply:'.$pending['token'])->reply();
    $bot->hearCallbackQueryData('admin:ann:apply:'.$pending['token'])->reply();
    expect(DB::table('announcements')->where('id', $id)->value('status'))->toBe('published')
        ->and(DB::table('announcements')->where('id', $id)->value('revision'))->toBe(2);

    $bot->hearCallbackQueryData('admin:ann:prepare:'.$id.':unpublish')->reply();
    $stale = Cache::get('admin:ann:transition:'.$root->id);
    app(ManageAnnouncements::class)->update($root->id, $id, 2, 'خبر اصلاحی', 'متن اصلاحی');
    $bot->hearCallbackQueryData('admin:ann:apply:'.$stale['token'])->reply();
    expect(DB::table('announcements')->where('id', $id)->value('status'))->toBe('published');
    Http::assertNothingSent();
});

it('keeps published content after editing and denies actions after root role revocation', function (): void {
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    $bot = annUi01Bot($root, 994801);
    $id = app(ManageAnnouncements::class)->create($root->id, 'عنوان', 'توضیح', true);

    $bot->hearCallbackQueryData('admin:ann:edit:'.$id.':body')->reply();
    $bot->hearText('متن ویرایش شده')->reply();
    $draft = Cache::get('admin:ann:draft:'.$root->id);
    $root->syncRoles(['member']);
    $bot->hearCallbackQueryData('admin:ann:save:'.$draft['token'])->reply();
    expect(DB::table('announcements')->where('id', $id)->value('body'))->toBe('توضیح')
        ->and(DB::table('announcements')->where('id', $id)->value('status'))->toBe('published');
    Http::assertNothingSent();
});
