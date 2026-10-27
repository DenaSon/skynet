<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\SalesTrialUi;
use App\Modules\Trial\Application\ProvisionFreeTrial;
use App\Modules\Trial\Jobs\ProvisionFreeTrialJob;
use GuzzleHttp\Psr7\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use SergiX44\Nutgram\Testing\FakeNutgram;
use Spatie\Permission\Models\Role;
use Tests\Support\FreeTrialFixtures;

uses(RefreshDatabase::class, FreeTrialFixtures::class);

beforeEach(function (): void {
    $this->configureFreeTrial();
    $route = new Route('POST', 'api/telegram/sales/webhook', static fn (): null => null);
    $route->defaults('bot_key', TelegramBotContext::SALES);
    $route->bind(request());
    request()->setRouteResolver(static fn (): Route => $route);
});

function trialUiBot(int $id = 9210001): Nutgram
{
    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $bot->setCommonUser(TelegramUser::make($id, false, 'Buyer'));
    $bot->setCommonChat(Chat::make($id, ChatType::PRIVATE));

    return $bot;
}

it('requires a private native self-contact and explicit masked one-time confirmation', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:trial:contact')->reply()->assertRaw(function (Request $request): bool {
        $data = FakeNutgram::getActualData($request);

        return str_contains(json_encode($data), 'request_contact');
    }, index: 1);
    $bot->hearText('09123456789')->reply()->assertCalled('sendMessage');
    expect(DB::table('trial_claims')->count())->toBe(0);
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => 9210001]])
        ->reply()->assertCalled('sendMessage', 2);
    $draft = Cache::get('telegram:sales:trial:'.$member->id);
    expect($draft['stage'])->toBe('confirm')
        ->and(json_encode($draft))->not->toContain('9123456789')
        ->and($draft['masked'])->toBe('+98******6789')
        ->and(DB::table('trial_claims')->count())->toBe(0);
    $bot->hearCallbackQueryData('sales:trial:confirm:'.$draft['token'])->reply()->assertCalled('editMessageText');
    Queue::assertPushed(ProvisionFreeTrialJob::class, fn ($job): bool => $job->connection === 'database');
    $bot->hearCallbackQueryData('sales:trial:confirm:'.$draft['token'])->reply();
    expect(DB::table('trial_claims')->count())->toBe(1)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects unsolicited foreign and missing contact ownership', function (?int $contactId): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => 9210001]])->reply();
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => $contactId]])->reply();
    expect(Cache::get('telegram:sales:trial:'.$member->id)['stage'])->toBe('contact')
        ->and(DB::table('trial_claims')->count())->toBe(0);
    Http::assertNothingSent();
})->with([null, 9210099]);

it('cancels native contact intake and restores the native menu without accepting stale confirmation', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearText('/cancel')->reply()->assertCalled('sendMessage', 3);
    expect(Cache::get('telegram:sales:trial:'.$member->id))->toBeNull();
    $bot->hearCallbackQueryData('sales:trial:confirm:'.str_repeat('a', 32))->reply();
    expect(DB::table('trial_claims')->count())->toBe(0);
});

it('hides trial for disabled flag reseller elevated and paid identities and rejects forged entry', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    config()->set('trial.enabled', false);
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse();
    config()->set('trial.enabled', true);
    Role::findOrCreate('reseller', 'web');
    $member->syncRoles(['reseller']);
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse()
        ->and(DB::table('trial_claims')->count())->toBe(0);
});

it('never receives contacts from a group or Admin Bot context', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->setCommonChat(Chat::make(-912, ChatType::GROUP));
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse();
    $bot->setCommonChat(Chat::make(9210001, ChatType::PRIVATE));
    request()->route()->defaults('bot_key', 'admin');
    app(SalesTrialUi::class)->requestContact($bot);
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse();
});

it('shows live minutes and MiB and uses the existing QR renderer after activation', function (): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial')->reply()->assertCalled('editMessageText');
    $bot->hearCallbackQueryData('sales:trial:link')->reply()->assertCalled('sendPhoto', 1)->assertCalled('sendMessage', 0);
    $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    $bot->hearCallbackQueryData('sales:trial:link')->reply()->assertCalled('sendMessage')->assertCalled('sendPhoto', 0);
});

it('retires native Contact drafts when navigating to an existing catalog or service route', function (string $navigation): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    if (str_starts_with($navigation, '/')) {
        $bot->hearText($navigation)->reply();
    } else {
        $bot->hearCallbackQueryData($navigation)->reply();
    }
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse()
        ->and(DB::table('trial_claims')->count())->toBe(0);
})->with(['/catalog', 'sales:subs:list:0']);

it('binds the verified contact draft to its original numeric Telegram account across relinking', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => 9210001]])->reply();
    $draft = Cache::get('telegram:sales:trial:'.$member->id);
    DB::table('telegram_accounts')->where('user_id', $member->id)->update(['telegram_user_id' => 9210002]);
    $other = trialUiBot(9210002);
    $other->hearCallbackQueryData('sales:trial:confirm:'.$draft['token'])->reply();
    expect(DB::table('trial_claims')->count())->toBe(0);
    Queue::assertNotPushed(ProvisionFreeTrialJob::class);
});

it('keeps one claim across a Telegram username change', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => 9210001]])->reply();
    $draft = Cache::get('telegram:sales:trial:'.$member->id);
    $renamed = TelegramUser::make(9210001, false, 'Buyer');
    $renamed->username = 'changed_username';
    $bot->setCommonUser($renamed);
    $bot->hearCallbackQueryData('sales:trial:confirm:'.$draft['token'])->reply();
    $bot->hearCallbackQueryData('sales:trial:confirm:'.$draft['token'])->reply();
    expect(DB::table('trial_claims')->count())->toBe(1);
});

/** @return array<string, mixed> */
function trialUiPayload(Request $request): array
{
    $data = FakeNutgram::getActualData($request);
    if (is_string($data['reply_markup'] ?? null)) {
        $data['reply_markup'] = json_decode($data['reply_markup'], true, flags: JSON_THROW_ON_ERROR);
    }

    return $data;
}

it('puts trial and tutorial in the persistent keyboard and removes their home and account inline entries', function (): void {
    $this->trialMember();
    $bot = trialUiBot();
    $bot->hearText('/start')->reply()->assertRaw(function (Request $request): bool {
        $markup = trialUiPayload($request)['reply_markup'];
        $labels = array_column(array_merge(...$markup['keyboard']), 'text');

        return $markup['is_persistent'] === true && in_array('🧪 تست رایگان', $labels, true)
            && in_array('📚 آموزش', $labels, true);
    }, index: 0)->assertRaw(function (Request $request): bool {
        $markup = json_encode(trialUiPayload($request)['reply_markup']);

        return ! str_contains($markup, 'sales:trial') && ! str_contains($markup, 'sales:help:apps');
    }, index: 1);
    $bot->hearCallbackQueryData('sales:account')->reply()->assertRaw(function (Request $request): bool {
        return ! str_contains(json_encode(trialUiPayload($request)['reply_markup']), 'sales:trial');
    }, index: 1);
    $bot->hearText('🧪 تست رایگان')->reply()->assertCalled('sendMessage');
    Http::assertNothingSent();
});

it('keeps tutorial available to resellers without granting a trial through forged reply text', function (): void {
    $member = $this->trialMember();
    Role::findOrCreate('reseller', 'web');
    $member->syncRoles(['reseller']);
    $bot = trialUiBot();
    $bot->hearText('/start')->reply()->assertRaw(function (Request $request): bool {
        $labels = array_column(array_merge(...trialUiPayload($request)['reply_markup']['keyboard']), 'text');

        return in_array('📚 آموزش', $labels, true) && ! in_array('🧪 تست رایگان', $labels, true);
    }, index: 0);
    $bot->hearText('📚 آموزش')->reply()->assertRaw(function (Request $request): bool {
        $data = trialUiPayload($request);
        $rich = is_string($data['rich_message'] ?? null)
            ? json_decode($data['rich_message'], true, 512, JSON_THROW_ON_ERROR)
            : ($data['rich_message'] ?? []);

        return str_contains((string) ($rich['html'] ?? ''), 'برای مشاهده مراحل کامل')
            && str_contains(json_encode($data['reply_markup'], JSON_THROW_ON_ERROR), 'sales:help:builder');
    });
    $bot->hearText('🧪 تست رایگان')->reply();
    expect(DB::table('trial_claims')->count())->toBe(0);
    Http::assertNothingSent();
});

it('hides a used trial immediately on menu refresh for both terminal conditions before the scheduled scan', function (bool $traffic): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    if ($traffic) {
        $this->trialRemote['userTraffic']['usedTrafficBytes'] = ProvisionFreeTrial::BYTES;
    } else {
        $this->travel(2)->hours();
    }
    $bot = trialUiBot();
    $bot->hearText('/start')->reply()->assertRaw(function (Request $request): bool {
        $labels = array_column(array_merge(...trialUiPayload($request)['reply_markup']['keyboard']), 'text');

        return in_array('📚 آموزش', $labels, true) && ! in_array('🧪 تست رایگان', $labels, true);
    }, index: 0);
    $bot->hearText('🧪 تست رایگان')->reply()->assertRaw(function (Request $request): bool {
        $data = trialUiPayload($request);
        $labels = array_column(array_merge(...$data['reply_markup']['keyboard']), 'text');

        return str_contains($data['text'], 'تست به پایان رسیده است') && ! in_array('🧪 تست رایگان', $labels, true);
    });
    expect(DB::table('trial_claims')->count())->toBe(1);
})->with([true, false]);

it('routes tutorial navigation ahead of contact drafts and restores the normal keyboard', function (): void {
    $member = $this->trialMember();
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearText('📚 آموزش')->reply()->assertCalled('sendMessage')->assertCalled('sendRichMessage')->assertRaw(function (Request $request): bool {
        return isset(trialUiPayload($request)['reply_markup']['keyboard']);
    }, index: 0);
    expect(Cache::has('telegram:sales:trial:'.$member->id))->toBeFalse();
    $bot->hearCallbackQueryData('sales:trial:contact')->reply();
    $bot->hearMessage(['contact' => ['phone_number' => '+989123456789', 'first_name' => 'Buyer', 'user_id' => 9210001]])
        ->reply()->assertRaw(function (Request $request): bool {
            return isset(trialUiPayload($request)['reply_markup']['keyboard']);
        }, index: 0);
    expect(DB::table('trial_claims')->count())->toBe(0);
});

it('keeps a very long trial URL intact in one text message without a second QR message', function (): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    $url = 'https://sub.example.test/'.str_repeat('a', 1100);
    $this->trialRemote['subscriptionUrl'] = $url;
    $bot = trialUiBot();
    $bot->hearCallbackQueryData('sales:trial:link')->reply()
        ->assertCalled('sendPhoto', 0)->assertCalled('sendMessage', 1)
        ->assertRaw(function (Request $request) use ($url): bool {
            $data = trialUiPayload($request);

            return str_contains($data['text'], $url)
                && ! str_contains(json_encode($data['reply_markup']), 'copy_text');
        }, index: 1);
});

it('retains the private trial link as text when Telegram rejects the QR upload', function (): void {
    $member = $this->trialMember();
    $this->activateFreeTrial($member);
    $bot = trialUiBot();
    $bot->willReceive(true)->willReceive([], ok: false);
    $bot->hearCallbackQueryData('sales:trial:link')->reply()
        ->assertCalled('sendPhoto', 1)->assertCalled('sendMessage', 1)
        ->assertRaw(function (Request $request): bool {
            $data = trialUiPayload($request);

            return str_contains($data['text'], 'https://sub.example.test/private-trial')
                && str_contains(json_encode($data['reply_markup']), 'copy_text');
        }, index: 2);
});
