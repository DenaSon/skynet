<?php

use App\Interfaces\Telegram\Support\PendingShopVolume;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ConfigureProduct;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['955001', '955019']);
    config()->set('telegram.hybrid_menu_enabled', true);
    app(ConfigureProduct::class)->handle('vpn-access', 'VPN Access');
    Role::findOrCreate('reseller', 'web');
});

function b15Bot(int $telegramId, ?User $user = null): Nutgram
{
    if ($user !== null) {
        TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => $telegramId]);
    }

    $bot = app(Nutgram::class);
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));

    return $bot;
}

function b15Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 955001]);

    return $root;
}

function b15Preset(
    User $root, string $slug, string $audience = 'both', string $kind = 'hybrid',
    bool $featured = false, int $priceRials = 1_000_000, int $discount = 10,
): int {
    $plan = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', $slug, 'Plan '.$slug, $kind, 'preset', $audience,
        $kind === 'time' ? null : 30,
        30, $kind === 'time' ? null : 1,
        $kind === 'time' ? null : 100, 15, 90, 10, $featured,
    ));

    $pricing = app(ManagePlanPricing::class);
    $revision = $pricing->configure(
        $root->id, $plan->id, 1, 0,
        new PlanPricingDefinition('fixed', $priceRials, 0, 0, 0, $discount, 100_000),
    );
    $token = $pricing->requestChange($root->id, $plan->id, 1, $revision, 'activate');
    $pricing->confirmChange($root->id, $token);

    $plans = app(ManagePlans::class);
    $token = $plans->requestChange($root->id, $plan->id, 1, 'activate');
    $plans->confirmChange($root->id, $token);

    return $plan->id;
}

it('presents no unpriced or draft plans in either Telegram entry point', function (): void {
    $root = b15Root();
    $user = User::factory()->create();
    $bot = b15Bot(955010, $user);

    app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'draft-only', 'Draft', 'hybrid', 'preset', 'public',
        20, 30, 20, 20, 30, 30,
    ));

    expect(app(ListQuotedPresets::class)->handle($user->id))->toBe([]);

    $bot->hearText('/catalog')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData('menu:plan-catalog')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('editMessageText');

    expect(DB::table('sales_orders')->count())->toBe(0);
});

it('uses actual published buyer quotes including one reseller discount and unlimited time', function (): void {
    $root = b15Root();
    $public = b15Preset($root, 'public-30', 'both', 'hybrid', true, 1_000_000, 10);
    $exclusive = b15Preset($root, 'reseller-30', 'reseller');
    $unlimited = b15Preset($root, 'unlimited-30', 'public', 'time');

    $member = User::factory()->create();
    $reseller = User::factory()->create();
    $reseller->assignRole('reseller');
    $catalog = app(ListQuotedPresets::class);

    expect(collect($catalog->handle($member->id))->pluck('plan.id')->all())->toBe([$public, $unlimited])
        ->and($catalog->find($member->id, $public)->quote->totalPriceRials)->toBe(1_000_000)
        ->and($catalog->find($reseller->id, $public)->quote->totalPriceRials)->toBe(900_000)
        ->and($catalog->find($member->id, $unlimited)->quote->trafficGb)->toBeNull()
        ->and(collect($catalog->handle($reseller->id))->pluck('plan.id')->all())->toContain($exclusive);

    expect(fn () => $catalog->find($member->id, $exclusive))->toThrow(ValidationException::class);

    $bot = b15Bot(955011, $reseller);
    $bot->hearText('/catalog')->reply()->assertCalled('sendMessage');
    $bot->hearCallbackQueryData("catalog:detail:{$public}:1")->reply()
        ->assertCalled('editMessageText');

    $reseller->syncRoles(['member']);
    expect($catalog->find($reseller->id, $public)->quote->totalPriceRials)->toBe(1_000_000);
    $bot->hearCallbackQueryData("catalog:detail:{$exclusive}:1")->reply()
        ->assertCalled('editMessageText');
});

it('excludes stale, inactive and below-minimum tariffs and immediately reflects withdrawal', function (): void {
    $root = b15Root();
    $id = b15Preset($root, 'short-lived', 'both');
    $user = User::factory()->create();
    $bot = b15Bot(955012, $user);
    $catalog = app(ListQuotedPresets::class);

    expect($catalog->handle($user->id))->toHaveCount(1);

    $action = app(ManagePlanPricing::class);
    $token = $action->requestChange($root->id, $id, 2, 2, 'deactivate');
    $action->confirmChange($root->id, $token);

    expect($catalog->handle($user->id))->toBe([]);
    $bot->hearCallbackQueryData("catalog:detail:{$id}:1")->reply()->assertCalled('editMessageText');

    $price = $action->configure(
        $root->id, $id, 2, 3,
        new PlanPricingDefinition('formula', null, 200_000, 20_000, 10_000, 10, 100_000),
    );
    $token = $action->requestChange($root->id, $id, 2, $price, 'activate');
    $action->confirmChange($root->id, $token);
    expect($catalog->handle($user->id))->toHaveCount(1);

    $original = app(ManagePlans::class)->find($root->id, $id);
    $change = new PlanDefinition(
        'vpn-access', $original->slug, 'Changed Plan', 'hybrid', 'preset', 'both',
        30, 30, 1, 100, 15, 90,
    );
    app(ManagePlans::class)->update($root->id, $id, 2, $change);
    expect($catalog->handle($user->id))->toBe([]);

    $bot->hearCallbackQueryData("catalog:detail:{$id}:1")->reply()->assertCalled('editMessageText');
});

it('excludes builder plans and does not forward callbacks to legacy checkout', function (): void {
    $root = b15Root();
    $buyer = User::factory()->create();
    $bot = b15Bot(955013, $buyer);
    Http::preventStrayRequests();
    Http::fake();

    $builder = app(ManagePlans::class)->create($root->id, new PlanDefinition(
        'vpn-access', 'reseller-builder', 'Builder', 'hybrid', 'builder', 'reseller',
        null, null, 1, 100, 15, 90,
    ));
    $pricing = app(ManagePlanPricing::class);
    $rev = $pricing->configure($root->id, $builder->id, 1, 0,
        new PlanPricingDefinition('formula', null, 100_000, 10_000, 10_000, 5, 100_000));
    $token = $pricing->requestChange($root->id, $builder->id, 1, $rev, 'activate');
    $pricing->confirmChange($root->id, $token);
    $token = app(ManagePlans::class)->requestChange($root->id, $builder->id, 1, 'activate');
    app(ManagePlans::class)->confirmChange($root->id, $token);

    $buyer->assignRole('reseller');
    expect(app(ListQuotedPresets::class)->handle($buyer->id))->toBe([]);

    $bot->hearCallbackQueryData("catalog:detail:{$builder->id}:1")->reply()->assertCalled('editMessageText');
    $bot->hearText('/buy')->reply()->assertCalled('sendMessage');

    expect(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('provisioning_subscriptions')->count())->toBe(0);
    Http::assertNothingSent();
});

it('enforces private chat, linked active identity and safe forged callback IDs', function (): void {
    $root = b15Root();
    $id = b15Preset($root, 'public-available');
    $member = User::factory()->create();
    $bot = b15Bot(955014, $member);

    $bot->hearCallbackQueryData('catalog:detail:abc:1')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('catalog:detail:999999:1')->reply()->assertCalled('editMessageText');
    $bot->setCommonChat(Chat::make(-955014, ChatType::GROUP));
    $bot->hearText('/catalog')->reply()->assertNoReply();
    $bot->hearCallbackQueryData("catalog:detail:{$id}:1")->reply()->assertNoReply();
    $bot->setCommonChat(Chat::make(955014, ChatType::PRIVATE));

    $member->forceFill(['is_active' => false])->save();
    $bot->hearText('/catalog')->reply()->assertReplyText('دسترسی مجاز نیست.');

    $unlinked = b15Bot(955015);
    $unlinked->hearText('/catalog')->reply()->assertReplyText('دسترسی مجاز نیست.');
});

it('adds an optional reply-menu link without interfering with previous custom GB input', function (): void {
    $root = b15Root();
    $id = b15Preset($root, 'new-plan');
    $member = User::factory()->create();
    $bot = b15Bot(955016, $member);

    $actor = app(ResolveTelegramActor::class)->handle(955016);
    $keyboard = app(ReplyMenuRenderer::class)->homeKeyboard($actor);
    $labels = array_map(static fn ($button): string => $button->text, array_merge(...$keyboard->keyboard));
    expect($labels)->toContain(ReplyMenuLabels::CATALOG);

    app(PendingShopVolume::class)->remember($member->id, 'vpn-access', 30);
    $bot->hearText(ReplyMenuLabels::CATALOG)->reply()->assertCalled('sendMessage');
    expect(app(PendingShopVolume::class)->peek($member->id))->toBeNull();
    $bot->hearCallbackQueryData("catalog:detail:{$id}:1")->reply()->assertCalled('editMessageText');

    config()->set('telegram.hybrid_menu_enabled', false);
    $bot->hearText(ReplyMenuLabels::CATALOG)->reply()->assertNoReply();
    $bot->hearText('/catalog')->reply()->assertCalled('sendMessage');
});

it('provides copyable HTML samples solely to authorized Super Admin, with a real product slug', function (): void {
    $root = b15Root();
    $bot = b15Bot(955001);
    $bot->hearCallbackQueryData('plan:examples')->reply()
        ->assertCalled('answerCallbackQuery')
        ->assertCalled('sendMessage');

    expect(DB::table('sales_plans')->count())->toBe(0);
    $guest = User::factory()->create();
    $guestBot = b15Bot(955017, $guest);
    $guestBot->hearCallbackQueryData('plan:examples')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);

    $bot->setCommonUser(TelegramUser::make(955001, false, 'Root'));
    $bot->setCommonChat(Chat::make(955001, ChatType::PRIVATE));
    config()->set('super_admin.telegram_ids', ['955018']);
    $bot->hearCallbackQueryData('plan:examples')->reply()
        ->assertReply('answerCallbackQuery', ['text' => 'دسترسی مجاز نیست.']);
});
