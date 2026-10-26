<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Notifications\DispatchTelegramNotification;
use App\Interfaces\Telegram\Notifications\NotificationUnavailable;
use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Referral\Application\GetReferralInvite;
use App\Modules\Sales\Application\ReferralRewardOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatType;
use SergiX44\Nutgram\Telegram\Types\Chat\Chat;
use SergiX44\Nutgram\Telegram\Types\User\User as TelegramUser;
use Tests\Support\ReferralRewardFixture as Fixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('referral', [
        'rewards_enabled' => true, 'reward_tomans' => 30000,
        'max_rewards_per_referrer' => 7, 'rewards_start_at' => '2026-10-26T00:00:00Z',
    ]);
    $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
    Http::preventStrayRequests();
    Queue::fake();
});

function ref03Activate(int $order): void
{
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)
        ->update(app(ReferralRewardOutbox::class)->captureFields());
    app(ReferralRewardOutbox::class)->process($order);
}

it('records one notification intent in the credit transaction and does not duplicate wallet or notice', function (): void {
    config()->set('telegram_notifications.enabled', true);
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    ref03Activate($order);
    app(ReferralRewardOutbox::class)->process($order);

    $award = DB::table('referral_rewards')->first();
    $notice = DB::table('telegram_notification_deliveries')->first();
    expect($award)->not->toBeNull()
        ->and($notice->event_key)->toBe('referral:reward:'.$award->id.':credited')
        ->and($notice->event_type)->toBe('ReferralRewardCredited')
        ->and($notice->bot_key)->toBe('sales')
        ->and($notice->state)->toBe('pending')
        ->and((int) $notice->recipient_user_id)->toBe($referrer->id)
        ->and((int) $notice->order_id)->toBe($order)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1)
        ->and(DB::table('referral_rewards')->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(300000);
    Http::assertNothingSent();
});

it('retains financial credit and a non-replayable suppressed notice while alerts are disabled', function (): void {
    config()->set('telegram_notifications.enabled', false);
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    ref03Activate($order);
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('suppressed')
        ->and(app(ViewWallet::class)->handle($referrer->id)->balanceRials)->toBe(300000);
    config()->set('telegram_notifications.enabled', true);
    $noticeId = (int) DB::table('telegram_notification_deliveries')->value('id');
    app(DispatchTelegramNotification::class, ['deliveryId' => $noticeId])
        ->handle(app(TelegramNotificationSender::class));
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('suppressed');
    Http::assertNothingSent();
});

it('rolls back the award, ledger and credited state when a notification intent cannot be stored', function (): void {
    config()->set('telegram_notifications.enabled', true);
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)
        ->update(app(ReferralRewardOutbox::class)->captureFields());
    DB::unprepared("CREATE TRIGGER fail_ref03 BEFORE INSERT ON telegram_notification_deliveries BEGIN SELECT RAISE(ABORT, 'fixture'); END");
    app(ReferralRewardOutbox::class)->process($order);
    expect(DB::table('referral_rewards')->count())->toBe(0)
        ->and(DB::table('billing_journals')->where('kind', 'referral_reward')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->where('sales_order_id', $order)->value('referral_state'))->toBe('pending');
    DB::unprepared('DROP TRIGGER fail_ref03');
    $this->travel(61)->seconds();
    app(ReferralRewardOutbox::class)->process($order);
    expect(DB::table('referral_rewards')->count())->toBe(1)
        ->and(DB::table('telegram_notification_deliveries')->count())->toBe(1);
});

it('delivers only a verified owner reward and never leaks the invited customer identity', function (): void {
    config()->set('telegram_notifications.enabled', true);
    config()->set('telegram.sales.enabled', true);
    config()->set('nutgram.token', '12345:admin-fixture');
    config()->set('telegram.sales.token', '56789:sales-fixture');
    $referrer = Fixture::member();
    $invitee = Fixture::member();
    $invitee->forceFill(['name' => 'PRIVATE_INVITEE_MARKER'])->save();
    $order = Fixture::order($invitee, $referrer);
    DB::table('telegram_bot_contacts')->insert([
        'user_id' => $referrer->id, 'bot_key' => 'sales',
        'chat_id' => 9800000 + $referrer->id, 'delivery_status' => 'reachable',
        'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    ref03Activate($order);
    $notice = DB::table('telegram_notification_deliveries')->first();

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    $sender = app(TelegramNotificationSender::class);
    $tampered = clone $notice;
    $tampered->event_key = 'referral:reward:999:credited';
    expect(fn () => $sender->send($tampered))->toThrow(NotificationUnavailable::class);
    Http::assertNothingSent();

    $deliveryId = (int) $notice->id;
    app(DispatchTelegramNotification::class, ['deliveryId' => $deliveryId])->handle($sender);
    app(DispatchTelegramNotification::class, ['deliveryId' => $deliveryId])->handle($sender);
    expect(DB::table('telegram_notification_deliveries')->value('state'))->toBe('sent');
    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '56789:sales-fixture/sendMessage')
            && str_contains((string) $request['text'], '30,000')
            && ! str_contains((string) $request['text'], 'PRIVATE_INVITEE_MARKER')
            && str_contains((string) $request['reply_markup'], 'sales:invite');
    });
});

it('shows only the referrer own registered and rewarded friends with current cap and frozen earned total', function (): void {
    $referrer = Fixture::member();
    $other = Fixture::member();
    $buyer = Fixture::member();
    $paid = Fixture::order($buyer, $referrer);
    Fixture::order(Fixture::member(), $referrer);
    $foreign = Fixture::order(Fixture::member(), $other);
    ref03Activate($paid);
    ref03Activate($foreign);

    $summary = app(GetReferralInvite::class)->handle($referrer->id);
    expect($summary->joinedCount)->toBe(2)
        ->and($summary->rewardedCount)->toBe(1)
        ->and($summary->earnedRials)->toBe(300000)
        ->and($summary->remainingRewards)->toBe(6)
        ->and($summary->rewardAmountRials)->toBe(300000);
    config()->set('referral.max_rewards_per_referrer', 1);
    config()->set('referral.reward_tomans', 45000);
    $changed = app(GetReferralInvite::class)->handle($referrer->id);
    expect($changed->earnedRials)->toBe(300000)
        ->and($changed->remainingRewards)->toBe(0)
        ->and($changed->rewardAmountRials)->toBe(450000);
    config()->set('referral.rewards_enabled', false);
    $disabled = app(GetReferralInvite::class)->handle($referrer->id);
    expect($disabled->earnedRials)->toBe(300000)
        ->and($disabled->remainingRewards)->toBeNull();
});

it('retains existing Sales invite copy and share controls while showing live reward terms', function (): void {
    config()->set('telegram.sales.bot_username', 'DenaGate_Test_Bot');
    $referrer = Fixture::member();
    $order = Fixture::order(Fixture::member(), $referrer);
    ref03Activate($order);
    $invite = app(GetReferralInvite::class)->handle($referrer->id);
    $url = 'https://t.me/DenaGate_Test_Bot?start=ref_'.$invite->code;

    $bot = Nutgram::fake();
    require base_path('app/Interfaces/Telegram/routes/sales.php');
    $telegramId = 9800000 + $referrer->id;
    $bot->setCommonUser(TelegramUser::make($telegramId, false, 'Referral'));
    $bot->setCommonChat(Chat::make($telegramId, ChatType::PRIVATE));
    $bot->hearText('/invite')->reply()->assertReplyText(
        "<b>👥 دعوت دوستان</b>\n\nلینک اختصاصی شما:\n".'<code>'.$url.'</code>'
            ."\n\n👥 دوستان ثبت‌نام‌شده: <b>1</b>"
            ."\n🎁 دوستان دارای پاداش: <b>1</b>"
            ."\n💰 مجموع پاداش‌ها: <b>30,000 تومان</b>"
            ."\n🎯 ظرفیت باقی‌مانده پاداش: <b>6</b>"
            ."\n💵 مبلغ هر پاداش: <b>30,000 تومان</b>"
            ."\n\n<blockquote>پاداش فقط برای دوست جدید، پس از اولین خرید پولی و فعال‌سازی موفق سرویس به اعتبار شما اضافه می‌شود. ثبت‌نام، تست رایگان و خرید تکراری پاداش ندارند؛ پنل‌های فروش هم مشمول نیستند.</blockquote>"
    );
    Http::assertNothingSent();
});
