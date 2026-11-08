<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Interfaces\Telegram\Support\SalesAnnouncementsUi;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use GuzzleHttp\Psr7\Request as NutgramRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.sales.bot_username', 'DenaGateSalesBot');
    config()->set('telegram.sales.support_username', null);
    Role::findOrCreate('member', 'web');
    Role::findOrCreate('reseller', 'web');
    Http::preventStrayRequests();
    Http::fake();

    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', 'sales');
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function ann02User(string $role = 'member'): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

function ann02Bot(User $user, int $telegramId = 976201): Nutgram
{
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function ann02Record(string $title, string $body, string $status = 'published', int $minutesAgo = 1): int
{
    return (int) DB::table('announcements')->insertGetId([
        'title' => $title,
        'body' => $body,
        'status' => $status,
        'published_at' => $status === 'published' ? now()->subMinutes($minutesAgo) : null,
        'created_by' => 1,
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function ann02Payload(NutgramRequest $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, 512, JSON_THROW_ON_ERROR);
    }

    return $data;
}

/** @return array<string, mixed> */
function ann02Rich(NutgramRequest $request): array
{
    $rich = ann02Payload($request)['rich_message'] ?? [];

    return is_string($rich) ? json_decode($rich, true, 512, JSON_THROW_ON_ERROR) : $rich;
}

it('preserves Sales first-row priority and exposes announcements on both home keyboards', function (): void {
    $buyer = ann02User();
    $bot = ann02Bot($buyer);
    $reply = app(ReplyMenuRenderer::class)->salesKeyboard(app(\App\Modules\Identity\Application\ResolveTelegramActor::class)->handle(976201));
    expect($reply->keyboard[0][0]->text)->toBe(ReplyMenuLabels::SALES_BUY)
        ->and($reply->keyboard[0][1]->text)->toBe(ReplyMenuLabels::SALES_SUBSCRIPTIONS)
        ->and(json_encode($reply, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        ->toContain(ReplyMenuLabels::SALES_ANNOUNCEMENTS);

    $bot->hearText('/start')->reply()->assertRaw(static function (NutgramRequest $request): bool {
        $payload = ann02Payload($request);
        $rich = ann02Rich($request);
        $reply = $payload['reply_markup']['keyboard'] ?? [];
        $labels = array_merge(...array_map(static fn (array $row): array => array_column($row, 'text'), $reply));

        return ($rich['is_rtl'] ?? false) === true
            && str_contains((string) ($rich['html'] ?? ''), 'data="sales:ann:list"')
            && $reply[0][0]['text'] === ReplyMenuLabels::SALES_BUY
            && $reply[0][1]['text'] === ReplyMenuLabels::SALES_SUBSCRIPTIONS
            && in_array(ReplyMenuLabels::SALES_ANNOUNCEMENTS, $labels, true);
    }, index: 0);
    Http::assertNothingSent();
});

it('shows a compact empty state, and the persistent text button opens it directly', function (): void {
    $bot = ann02Bot(ann02User());
    $bot->hearText(ReplyMenuLabels::SALES_ANNOUNCEMENTS)->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $rich = ann02Rich($request);
            $keyboard = json_encode(ann02Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains((string) ($rich['html'] ?? ''), 'هنوز اطلاعیه‌ای منتشر نشده است')
                && str_contains($keyboard, 'sales:home')
                && ! str_contains($keyboard, 'sales:ann:view:');
        }, index: 0,
    );
    Http::assertNothingSent();
});

it('lists exactly the newest three published notices with escaped titles, bounded summaries and real callbacks', function (): void {
    $bot = ann02Bot(ann02User());
    $old = ann02Record('قدیمی', 'متن قدیمی', minutesAgo: 60);
    $third = ann02Record('سوم', str_repeat('الف', 145)."\nادامه", minutesAgo: 30);
    $second = ann02Record('دوم <متن>', 'متن & <قابل نمایش>', minutesAgo: 20);
    $first = ann02Record('اول', 'آخرین خبر', minutesAgo: 1);
    ann02Record('پیش‌نویس محرمانه', 'نباید دیده شود', 'draft');
    DB::table('announcements')->where('id', $old)->update([
        'status' => 'draft', 'published_at' => null,
    ]);

    $bot->hearCallbackQueryData('sales:ann:list')->reply()->assertRaw(
        static function (NutgramRequest $request) use ($first, $second, $third, $old): bool {
            $rich = ann02Rich($request);
            $html = (string) ($rich['html'] ?? '');
            $keyboard = json_encode(ann02Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains($html, '<h3>📢 اطلاعیه‌ها</h3>')
                && str_contains($html, 'دوم &lt;متن&gt;')
                && str_contains($html, 'متن &amp; &lt;قابل نمایش&gt;')
                && ! str_contains($html, '<متن>')
                && ! str_contains($html, 'پیش‌نویس محرمانه')
                && ! str_contains($html, 'قدیمی')
                && mb_strpos($html, 'اول</h4>') < mb_strpos($html, 'دوم &lt;متن&gt;</h4>')
                && mb_strpos($html, 'دوم &lt;متن&gt;</h4>') < mb_strpos($html, 'سوم</h4>')
                && str_contains($html, '…')
                && str_contains($keyboard, 'sales:ann:view:'.$first)
                && str_contains($keyboard, 'sales:ann:view:'.$second)
                && str_contains($keyboard, 'sales:ann:view:'.$third)
                && ! str_contains($keyboard, 'sales:ann:view:'.$old)
                && substr_count($keyboard, 'sales:ann:view:') === 3;
        }, index: 1,
    );
    Http::assertNothingSent();
});

it('renders the complete escaped multiline detail and a route back to the list', function (): void {
    $bot = ann02Bot(ann02User());
    $id = ann02Record('اعلان <ویژه>', "سطر اول & متن\nسطر دوم <b>بدون HTML</b>");

    $bot->hearCallbackQueryData('sales:ann:view:'.$id)->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $rich = ann02Rich($request);
            $html = (string) ($rich['html'] ?? '');
            $keyboard = json_encode(ann02Payload($request)['reply_markup'] ?? [], JSON_THROW_ON_ERROR);

            return ($rich['is_rtl'] ?? false) === true
                && str_contains($html, 'اعلان &lt;ویژه&gt;')
                && str_contains($html, 'سطر اول &amp; متن<br>سطر دوم &lt;b&gt;بدون HTML&lt;/b&gt;')
                && ! str_contains($html, 'متن<br>سطر دوم <b>')
                && str_contains($keyboard, 'sales:ann:list')
                && str_contains($keyboard, 'sales:home');
        }, index: 1,
    );
    Http::assertNothingSent();
});

it('fails closed when a published notice is withdrawn or a callback ID is invalid', function (): void {
    $bot = ann02Bot(ann02User());
    $id = ann02Record('عنوان محرمانه', 'محتوای نباید نمایش داده شود');
    DB::table('announcements')->where('id', $id)->update(['status' => 'draft']);

    foreach (['sales:ann:view:'.$id, 'sales:ann:view:invalid', 'sales:ann:view:0001'] as $callback) {
        $bot->hearCallbackQueryData($callback)->reply()->assertRaw(
            static function (NutgramRequest $request): bool {
                $html = (string) (ann02Rich($request)['html'] ?? '');

                return str_contains($html, 'این اطلاعیه دیگر در دسترس نیست')
                    && ! str_contains($html, 'محتوای نباید نمایش داده شود')
                    && ! str_contains($html, 'عنوان محرمانه');
            }, index: 1,
        );
    }
    Http::assertNothingSent();
});

it('blocks group callbacks and inactive accounts from reading customer announcements', function (): void {
    $user = ann02User();
    $bot = ann02Bot($user);
    ann02Record('خبر خصوصی', 'متن مختص اعضای فعال');

    $bot->setCommonChat(Chat::make(-976201, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:ann:list')->reply()->assertNoReply();

    $bot->setCommonChat(Chat::make(976201, ChatType::PRIVATE));
    $user->forceFill(['is_active' => false])->save();
    $bot->hearCallbackQueryData('sales:ann:list')->reply()->assertRaw(
        static function (NutgramRequest $request): bool {
            $data = ann02Payload($request);
            $html = (string) (ann02Rich($request)['html'] ?? $data['text'] ?? '');

            return ! str_contains($html, 'متن مختص اعضای فعال');
        },
    );
    Http::assertNothingSent();
});

it('falls back from Rich to native HTML preserving the published snapshot and keyboard', function (): void {
    $user = ann02User();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 976201]);
    $id = ann02Record('خبر <مهم>', 'متن & جزئیات');
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('chat')->andReturn(Chat::make(976201, ChatType::PRIVATE));
    $bot->shouldReceive('user')->andReturn(TelegramUser::make(976201, false, 'Buyer'));
    $bot->shouldReceive('isCallbackQuery')->andReturn(false);
    $bot->shouldReceive('sendRichMessage')->once()->andReturnUsing(static function (...$arguments): void {
        foreach ($arguments as $argument) {
            if ($argument instanceof InputRichMessage) {
                expect($argument->is_rtl)->toBeTrue()
                    ->and($argument->html)->toContain('خبر &lt;مهم&gt;', 'متن &amp; جزئیات');
            }
        }
        throw new TelegramException('Bad Request: Rich unsupported', 400);
    });
    $bot->shouldReceive('sendMessage')->once()->andReturnUsing(static function (...$arguments) use ($id): null {
        expect($arguments[0])->toContain('خبر &lt;مهم&gt;', 'متن &amp; جزئیات')
            ->not->toContain('خبر <مهم>');
        expect($arguments)->toContain('HTML');
        $keyboard = null;
        foreach ($arguments as $argument) {
            if ($argument instanceof InlineKeyboardMarkup) {
                $keyboard = $argument;
            }
        }
        expect($keyboard)->toBeInstanceOf(InlineKeyboardMarkup::class)
            ->and(json_encode($keyboard, JSON_THROW_ON_ERROR))->toContain('sales:ann:view:'.$id, 'sales:home');

        return null;
    });

    app(SalesAnnouncementsUi::class)->browse($bot);
    Http::assertNothingSent();
});
