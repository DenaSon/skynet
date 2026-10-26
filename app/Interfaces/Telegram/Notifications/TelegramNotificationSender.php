<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\ReplyMenuRenderer;
use App\Interfaces\Telegram\Support\SubscriptionPageLink;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Billing\Application\VerifyWalletOrderDebit;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\AdminCapabilityAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramRecipient;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Sales\Application\ManualBankPayments;
use App\Modules\Sales\Application\ReviewSalesPanelRequest;
use App\Modules\Trial\Application\EndFreeTrial;
use App\Modules\Trial\Application\ReadFreeTrial;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use Throwable;

final readonly class TelegramNotificationSender
{
    public function __construct(
        private TelegramRecipient $recipients,
        private ResolveTelegramActor $actors,
        private ManualBankPayments $payments,
    ) {}

    public function send(object $delivery): void
    {
        $userId = (int) $delivery->recipient_user_id;
        $chat = $this->recipients->forActiveUser($userId);

        if ($chat === null || $chat < 1) {
            throw new NotificationUnavailable('inactive');
        }

        $bot = (string) $delivery->bot_key;

        if ($bot === 'admin') {
            if (DB::table('telegram_bot_contacts')->where('user_id', $userId)
                ->where('bot_key', 'admin')->where('delivery_status', 'blocked')->exists()) {
                throw new NotificationUnavailable('blocked');
            }
        } elseif ($bot === 'sales') {
            $contact = DB::table('telegram_bot_contacts')->where('user_id', $userId)
                ->where('bot_key', 'sales')->where('delivery_status', 'reachable')->first();

            if ($contact === null || (int) $contact->chat_id !== $chat) {
                throw new NotificationUnavailable('not_started');
            }
        } else {
            throw new NotificationUnavailable('invalid_bot');
        }

        $token = $this->token($bot);

        if ($delivery->event_type === 'ReferralRewardCredited') {
            $orderId = (int) $delivery->order_id;
            if ($bot !== 'sales' || $orderId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $reward = DB::table('referral_rewards')
                ->where('sales_order_id', $orderId)
                ->where('referrer_user_id', $userId)
                ->first(['id', 'amount_rials']);
            if ($reward === null
                || $delivery->event_key !== 'referral:reward:'.$reward->id.':credited'
                || ! DB::table('sales_fulfillment_outbox')
                    ->where('sales_order_id', $orderId)->where('referral_state', 'credited')->exists()) {
                throw new NotificationUnavailable('invalid_referral_reward');
            }
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '🎁 دعوت دوستان', 'callback_data' => 'sales:invite'],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat,
                '<b>🎁 پاداش معرفی به اعتبار شما اضافه شد</b>'
                    ."\n\nمبلغ پاداش: <b>".TelegramUi::toman((int) $reward->amount_rials).' تومان</b>'
                    ."\n\nاین پاداش پس از اولین خرید و فعال‌سازی موفق دوست معرفی‌شده ثبت شده است.",
                $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'TrialEnded') {
            $trialId = (int) $delivery->trial_claim_id;
            if ($bot !== 'sales' || $trialId < 1 || $delivery->event_key !== 'free-trial:'.$trialId.':ended') {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $trials = app(EndFreeTrial::class);
            if (! $trials->claimNotice($trialId, $userId, $chat)) {
                throw new NotificationUnavailable('trial_already_attempted');
            }
            try {
                $this->text($bot, $chat, "<b>🎁 تست رایگان شما به پایان رسید</b>\n\nامیدواریم تجربه خوبی از DenaGate داشته باشید.\n\nبرای ادامه استفاده می‌توانید یکی از پلن‌های اصلی را انتخاب کنید.",
                    $this->trialMenu($userId, $chat, ended: true), 'HTML');
                $trials->noticeSent($trialId);
            } catch (NotificationUnavailable $error) {
                $trials->releaseRejectedNotice($trialId);
                throw $error;
            } catch (Throwable $error) {
                if ($error->getCode() === 429) {
                    $trials->releaseRejectedNotice($trialId);
                    throw $error;
                }
                // A timeout/unknown send may already have reached Telegram. Keep
                // the fence even after lease expiry or manual outbox retry.
                throw new NotificationUnavailable('trial_delivery_uncertain');
            }

            return;
        }

        if (in_array($delivery->event_type, [TrialWarningNotifications::LOW_TRAFFIC, TrialWarningNotifications::EXPIRING], true)) {
            if ($bot !== 'sales') {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $message = app(TrialWarningNotifications::class)->messageFor($delivery, $chat);
            if ($message === null) {
                throw new NotificationUnavailable('trial_warning_obsolete');
            }
            $this->text($bot, $chat, $message, json_encode(['inline_keyboard' => [
                [['text' => '🛒 خرید اشتراک', 'callback_data' => 'sales:buy']],
                [['text' => '📋 مشاهده پلن‌ها', 'callback_data' => 'menu:plan-catalog']],
            ]], JSON_THROW_ON_ERROR), 'HTML');

            return;
        }

        if ($delivery->event_type === 'TrialActivated') {
            $trialId = (int) $delivery->trial_claim_id;
            if ($bot !== 'sales' || $trialId < 1
                || $delivery->event_key !== 'free-trial:'.$trialId.':activated') {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $status = app(ReadFreeTrial::class)->status($userId, $chat);
            if ($status->id !== $trialId || $status->ended() || $status->providerStatus !== 'ACTIVE') {
                throw new NotificationUnavailable('trial_not_active');
            }
            $this->text($bot, $chat, "<b>✅ تست رایگان شما فعال شد</b>\n\nحجم: <b>۲۵۰ مگابایت</b> · اعتبار: <b>۲ ساعت</b>\nاز بخش تست رایگان، وضعیت و لینک اتصال را ببینید.",
                $this->trialMenu($userId, $chat), 'HTML');

            return;
        }

        if ($delivery->event_type === 'SalesPanelSubmitted') {
            $requestId = (int) $delivery->panel_request_id;
            if ($bot !== 'admin' || $requestId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $actor = $this->actors->handle($chat);
            if ($actor === null || $actor->userId !== $userId || ! $actor->canManageRoles()) {
                throw new NotificationUnavailable('not_authorized');
            }
            try {
                $row = app(ReviewSalesPanelRequest::class)->find($userId, $requestId);
            } catch (Throwable) {
                throw new NotificationUnavailable('not_authorized');
            }
            if ($row['status'] !== 'pending') {
                throw new NotificationUnavailable('already_reviewed');
            }
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '🧩 بررسی درخواست', 'callback_data' => 'sp:detail:'.$requestId],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat,
                '<b>🧩 درخواست جدید پنل فروش</b>'
                ."\n\nدرخواست #{$requestId}"
                ."\nنام: ".TelegramUi::escape($row['first_name'].' '.$row['last_name'])
                ."\nموبایل: ".TelegramUi::code($row['mobile'])
                ."\n\n🕒 منتظر بررسی شماست.",
                $keyboard, 'HTML',
            );

            return;
        }

        if (in_array($delivery->event_type, ['SalesPanelApproved', 'SalesPanelRejected'], true)) {
            $requestId = (int) $delivery->panel_request_id;
            if ($bot !== 'sales' || $requestId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $expected = $delivery->event_type === 'SalesPanelApproved' ? 'approved' : 'rejected';
            $row = DB::table('sales_panel_requests')
                ->where('id', $requestId)->where('user_id', $userId)->where('status', $expected)
                ->first(['id', 'status']);
            if ($row === null) {
                throw new NotificationUnavailable('invalid_panel');
            }
            if ($expected === 'approved') {
                $actor = $this->actors->handle($chat);
                if ($actor === null || $actor->userId !== $userId
                    || ! in_array('reseller', $actor->roles, true)) {
                    throw new NotificationUnavailable('role_revoked');
                }
            }

            $message = $expected === 'approved'
                ? "<b>✅ پنل فروش شما فعال شد</b>\n\nدرخواست #{$requestId} تأیید شد."
                    ."\nاکنون می‌توانید پلن‌ها را با قیمت ویژه پنل فروش تهیه کنید."
                : '<b>❌ درخواست پنل فروش تأیید نشد</b>'
                    ."\n\nدرخواست #{$requestId}"
                    ."\nحساب فعلی شما بدون تغییر باقی مانده است."
                    ."\nبرای راهنمایی بیشتر با پشتیبانی گفتگو کنید.";
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '👤 حساب کاربری', 'callback_data' => 'sales:account'],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, $message, $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'WalletTopupSubmitted') {
            $topupId = (int) $delivery->topup_id;
            if ($bot !== 'admin' || $topupId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $actor = $this->actors->handle($chat);
            if ($actor === null || $actor->userId !== $userId || ! $actor->allows('topups.review')) {
                throw new NotificationUnavailable('not_authorized');
            }

            // Fresh reviewer authorization and submitted status are checked again
            // by the existing protected finance-photo read Action.
            try {
                $row = app(BrowsePhotoTopups::class)->photo($userId, $topupId);
            } catch (Throwable) {
                throw new NotificationUnavailable('already_reviewed');
            }

            // First deliver an explicit, independently acknowledged text alert.
            // Cross-bot photo transfer must not determine whether a manager
            // receives the submission notice at all. The finance queue remains
            // the authoritative place for reviewing the receipt.
            $keyboard = json_encode(['inline_keyboard' => [
                [['text' => '🧾 مشاهده رسید #'.$topupId, 'callback_data' => 'finance:topup:photo:'.$topupId]],
                [['text' => '📋 صف رسیدهای شارژ', 'callback_data' => 'finance:topups:1']],
            ]], JSON_THROW_ON_ERROR);
            $this->text(
                $bot, $chat,
                "🔔 <b>رسید جدید شارژ کیف پول #{$topupId}</b>"
                    ."\nکاربر #{$row['user_id']}"
                    ."\nمبلغ: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
                    ."\n\n🕒 در انتظار بررسی واریز توسط مدیر.",
                $keyboard, 'HTML',
            );

            // Photo is a convenience copy; successful notification delivery
            // does not depend on getFile/download/reupload across bot tokens.
            $caption = "🧾 درخواست شارژ کیف پول #{$topupId}"
                ."\nکاربر #{$row['user_id']}"
                ."\nمبلغ: ".TelegramUi::toman($row['amount_rials']).' تومان'
                ."\n\n⚠️ فقط پس از مشاهده واریز واقعی در بانک تأیید کنید.";
            try {
                $this->showWalletTopupPhotoToRoot($topupId, $userId, $chat, $caption);
            } catch (Throwable) {
                // No duplicate alert or failed financial state: the text
                // reached Telegram and the photo is available in Admin finance.
            }

            return;
        }

        if (in_array($delivery->event_type, ['WalletTopupApproved', 'WalletTopupRejected'], true)) {
            $topupId = (int) $delivery->topup_id;
            if ($bot !== 'sales' || $topupId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $topup = DB::table('billing_topups as t')
                ->join('billing_wallets as w', 'w.id', '=', 't.wallet_id')
                ->where('t.id', $topupId)->where('w.user_id', $userId)
                ->first(['t.id', 't.status', 't.amount_rials']);
            $expected = $delivery->event_type === 'WalletTopupApproved' ? 'approved' : 'rejected';
            if ($topup === null || $topup->status !== $expected) {
                throw new NotificationUnavailable('invalid_topup');
            }

            $amount = TelegramUi::toman((int) $topup->amount_rials);
            $message = $expected === 'approved'
                ? "<b>✅ اعتبار شما افزایش یافت</b>\n\nدرخواست #{$topupId}"
                    ."\nمبلغ افزوده‌شده: <b>{$amount} تومان</b>"
                : "<b>❌ درخواست شارژ تأیید نشد</b>\n\nدرخواست #{$topupId}"
                    ."\nمبلغ درخواست: <b>{$amount} تومان</b>"
                    ."\n\nبرای پیگیری با پشتیبانی تماس بگیرید.";
            try {
                $balance = app(ViewWallet::class)->handle($userId)->balanceRials;
            } catch (Throwable) {
                throw new NotificationUnavailable('inactive_wallet');
            }
            $message .= "\nاعتبار فعلی: <b>".TelegramUi::toman($balance).' تومان</b>';

            $keyboard = json_encode([
                'inline_keyboard' => [[
                    ['text' => '💰 اعتبار من', 'callback_data' => 'sales:wallet'],
                ]],
            ], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, $message, $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'WalletPurchaseSettled') {
            $orderId = (int) $delivery->order_id;
            if ($bot !== 'sales' || $orderId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $order = DB::table('sales_orders as o')
                ->join('sales_order_settlements as s', 's.sales_order_id', '=', 'o.id')
                ->where('o.id', $orderId)->where('o.buyer_user_id', $userId)
                ->whereIn('o.status', ['paid', 'fulfilled'])
                ->where('s.method', 'customer_wallet')
                ->first(['o.total_price_rials', 's.amount_rials', 's.billing_journal_id']);
            if ($order === null || (int) $order->total_price_rials !== (int) $order->amount_rials
                || ! app(VerifyWalletOrderDebit::class)->handle(
                    $userId, $orderId, (int) $order->billing_journal_id, (int) $order->total_price_rials,
                )) {
                throw new NotificationUnavailable('invalid_settlement');
            }

            try {
                $balance = app(ViewWallet::class)->handle($userId)->balanceRials;
            } catch (Throwable) {
                throw new NotificationUnavailable('inactive_wallet');
            }

            $amount = TelegramUi::toman((int) $order->amount_rials);
            $message = '<b>✅ پرداخت از اعتبار موفق بود</b>'
                ."\n\nسفارش #{$orderId}"
                ."\nمبلغ کسرشده: <b>{$amount} تومان</b>"
                ."\nاعتبار فعلی: <b>".TelegramUi::toman($balance).' تومان</b>'
                ."\n\nوضعیت فعال‌سازی سرویس را در سفارش‌های خود ببینید.";
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '📋 مشاهده سفارش', 'callback_data' => 'sales:checkout:view:'.$orderId],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, $message, $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'FulfillmentPlanBlocked') {
            $actor = $this->actors->handle($chat);
            if ($bot !== 'admin' || $actor === null || $actor->userId !== $userId || ! $actor->canManageRoles()) {
                throw new NotificationUnavailable('not_authorized');
            }
            $orderId = (int) $delivery->order_id;
            $blocked = DB::table('sales_orders as orders')
                ->join('sales_fulfillment_outbox as intent', 'intent.sales_order_id', '=', 'orders.id')
                ->where('orders.id', $orderId)->where('orders.status', 'paid')
                ->where('intent.state', 'needs_review')->exists();
            if (! $blocked) {
                throw new NotificationUnavailable('already_resolved');
            }
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '📋 بررسی سفارش', 'callback_data' => 'full:view:'.$orderId, 'style' => 'primary'],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat,
                '<b>⚠️ سفارش پرداخت‌شده نیازمند بررسی است</b>'
                ."\n\nسفارش #{$orderId}"
                ."\nمشخصات پلن یا سرویس مرتبط تغییر کرده است."
                ."\nفعال‌سازی پیش از ساخت اشتراک متوقف شد؛ پرداخت محفوظ است.",
                $keyboard, 'HTML',
            );

            return;
        }

        if ($delivery->event_type === 'ReceiptReviewReminder') {
            if ($bot !== 'admin') {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $actor = $this->actors->handle($chat);
            $user = User::query()->with('telegramAccount')->find($userId);
            if ($actor === null || $actor->userId !== $userId || $user === null) {
                throw new NotificationUnavailable('not_authorized');
            }

            $capabilities = app(AdminCapabilityAccess::class);
            $purchaseAllowed = $capabilities->allowed($user, 'purchases.review');
            $topupAllowed = $capabilities->allowed($user, 'topups.review');
            if (! $purchaseAllowed && ! $topupAllowed) {
                throw new NotificationUnavailable('not_authorized');
            }

            // Recipient-specific counts and routes: do not leak another
            // payment category, including after an in-flight role revocation.
            $pending = app(PendingReceiptSummary::class)->overdue($purchaseAllowed, $topupAllowed);
            if ($pending === null) {
                throw new NotificationUnavailable('already_reviewed');
            }
            $rows = [];
            $lines = ['<b>🔔 یادآوری بررسی رسیدها</b>', ''];
            if ($purchaseAllowed) {
                $lines[] = '🧾 رسید خرید: <b>'.$pending['purchases'].'</b>';
                $rows[] = [['text' => '🧾 رسیدهای خرید', 'callback_data' => 'bank:list']];
            }
            if ($topupAllowed) {
                $lines[] = '💰 رسید شارژ کیف پول: <b>'.$pending['topups'].'</b>';
                $rows[] = [['text' => '💰 رسیدهای شارژ', 'callback_data' => 'finance:topups:1']];
            }
            $lines[] = '';
            $lines[] = 'حداقل یک رسید بیش از ۱۵ دقیقه منتظر بررسی است.';
            $keyboard = json_encode(['inline_keyboard' => $rows], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, implode("\n", $lines), $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'ReceiptSubmitted') {
            $actor = $this->actors->handle($chat);

            if ($actor === null || $actor->userId !== $userId || ! $actor->allows('purchases.review')) {
                throw new NotificationUnavailable('not_authorized');
            }

            $claimId = (int) $delivery->claim_id;
            $orderId = (int) $delivery->order_id;
            if (DB::table('sales_bank_payment_claims')->where('id', $claimId)
                ->where('status', 'submitted')->doesntExist()) {
                throw new NotificationUnavailable('already_reviewed');
            }

            $row = $this->payments->detail($userId, $claimId);

            $caption = "🧾 رسید جدید #{$claimId}\nسفارش #{$orderId}"
                ."\nپلن: ".mb_substr((string) $row->productName, 0, 60)
                ."\nمبلغ: ".number_format(intdiv($row->amountRials + 9, 10)).' تومان'
                ."\n\n⚠️ فقط پس از بررسی گردش واقعی بانک تأیید کنید.";
            $buttons = json_encode([
                'inline_keyboard' => [[
                    ['text' => '✅ تأیید پرداخت', 'callback_data' => 'bank:decide:'.$claimId.':a'],
                    ['text' => '❌ رد رسید', 'callback_data' => 'bank:decide:'.$claimId.':r'],
                ]],
            ], JSON_THROW_ON_ERROR);

            try {
                $this->photo($bot, $chat, $row->photoFileId, (string) $row->sourceBotKey, $caption, $buttons);

                return;
            } catch (NotificationUnavailable $e) {
                if ($e->category === 'blocked' || $e->category === 'invalid_bot') {
                    throw $e;
                }
                // Photo missing/unavailable: keep the durable review queue as source of truth.
            } catch (Throwable $e) {
                // Telegram 429 applies to the entire destination bot. Sending a
                // fallback text immediately would double traffic while throttled.
                if ($e instanceof RuntimeException && $e->getCode() === 429) {
                    throw $e;
                }

                // Never log file IDs, tokens, binary data or external exception messages.
            }

            $this->text($bot, $chat, "🧾 رسید جدید #{$claimId} برای سفارش #{$orderId} ثبت شد.\nتصویر فعلاً قابل ارسال نیست؛ برای بررسی از /payments استفاده کنید.");

            return;
        }

        if ($delivery->event_type === 'PaymentReviewed') {
            $claim = DB::table('sales_bank_payment_claims')
                ->where('id', (int) $delivery->claim_id)
                ->where('buyer_user_id', $userId)->first();

            if ($claim === null || ! in_array($claim->status, ['approved', 'rejected'], true)) {
                throw new NotificationUnavailable('invalid_claim');
            }

            $order = (int) $delivery->order_id;
            // A late, retried payment event must not tell a customer their
            // already-active subscription is still being prepared.
            if ($claim->status === 'approved' && DB::table('sales_orders')
                ->where('id', $order)->where('buyer_user_id', $userId)
                ->where('status', 'fulfilled')->exists()) {
                throw new NotificationUnavailable('superseded_by_activation');
            }
            $autoReady = config('sales.auto_fulfillment_enabled', false) === true
                && config('services.remnawave.writes_enabled') === true
                && config('services.remnawave.squad_bindings_live_verified') === true;
            $text = $claim->status === 'approved'
                ? ($autoReady
                    ? "✅ پرداخت سفارش #{$order} تأیید شد؛ اشتراک در حال آماده‌سازی است."
                    : "✅ پرداخت سفارش #{$order} تأیید شد. درخواست فعال‌سازی ثبت شد؛ سرویس هنوز ساخته نشده است.")
                : "❌ رسید سفارش #{$order} رد شد. در صورت نیاز می‌توانید رسید جدید بفرستید.";

            $this->text($bot, $chat, $text);

            return;
        }

        if (in_array($delivery->event_type, ['SubscriptionExpiry24h', 'SubscriptionExpiry1h', 'SubscriptionLowTraffic'], true)) {
            if ($bot !== 'sales' || (int) $delivery->order_id < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $message = app(SubscriptionAlertMonitor::class)->messageFor($delivery);
            if ($message === null) {
                throw new NotificationUnavailable('stale_subscription');
            }

            // Always open the existing authenticated Sales service detail.
            // No credential or remote identifier is placed in callback data.
            $orderId = (int) $delivery->order_id;
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '📋 سرویس من', 'callback_data' => 'sales:subs:view:'.$orderId.':0', 'style' => 'primary'],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, $message, $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'SubscriptionRenewed') {
            $orderId = (int) $delivery->order_id;
            if ($bot !== 'sales' || $orderId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }
            $source = DB::table('sales_renewal_orders as renewal')
                ->join('sales_orders as orders', 'orders.id', '=', 'renewal.sales_order_id')
                ->join('sales_fulfillment_outbox as outbox', 'outbox.sales_order_id', '=', 'orders.id')
                ->join('provisioning_order_links as link', 'link.sales_order_id', '=', 'renewal.source_order_id')
                ->join('provisioning_subscriptions as sub', 'sub.id', '=', 'link.provisioning_subscription_id')
                ->where('orders.id', $orderId)->where('orders.buyer_user_id', $userId)
                ->where('orders.status', 'fulfilled')->where('outbox.state', 'completed')
                ->whereColumn('sub.id', 'renewal.provisioning_subscription_id')
                ->where('sub.state', 'active')->value('renewal.source_order_id');
            if ($source === null) {
                throw new NotificationUnavailable('renewal_not_complete');
            }
            $keyboard = json_encode(['inline_keyboard' => [[
                ['text' => '📦 سرویس من', 'callback_data' => 'sales:subs:view:'.$source.':0',
                    'style' => 'primary'],
            ]]], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat,
                "<b>✅ اشتراک شما تمدید شد</b>\n\nسفارش تمدید #{$orderId}\nاعتبار و حجم سرویس قبلی شما به‌روزرسانی شد.",
                $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'SubscriptionActivated') {
            $orderId = (int) $delivery->order_id;
            if ($bot !== 'sales' || $orderId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $complete = DB::table('sales_orders as orders')
                ->join('sales_fulfillment_outbox as outbox', 'outbox.sales_order_id', '=', 'orders.id')
                ->join('provisioning_order_links as links', 'links.sales_order_id', '=', 'orders.id')
                ->join('provisioning_subscriptions as service', 'service.id', '=', 'links.provisioning_subscription_id')
                ->where('orders.id', $orderId)->where('orders.buyer_user_id', $userId)
                ->where('orders.status', 'fulfilled')->where('outbox.state', 'completed')
                ->where('service.state', 'active')->exists();

            if (! $complete) {
                throw new NotificationUnavailable('not_active');
            }

            // Only an order ID is placed in callbacks. The Sales presenter
            // rechecks authorization and active RW data on every click.
            $buttons = [[
                'text' => '🔐 نمایش کد اتصال',
                'callback_data' => 'sales:subs:link:'.$orderId.':0',
            ]];
            $rows = [$buttons];
            if (app(SubscriptionPageLink::class)->configured()) {
                $rows[] = [[
                    'text' => '🌐 صفحه اشتراک',
                    'callback_data' => 'sales:subs:page:'.$orderId.':0',
                ]];
            }
            $keyboard = json_encode(['inline_keyboard' => $rows], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, "✅ اشتراک شما فعال شد!\nسفارش #{$orderId}\n\nبرای دریافت اتصال دکمه زیر را انتخاب کنید.", $keyboard);

            return;
        }

        throw new NotificationUnavailable('unsupported_event');
    }

    /** Returns true only when the photo was handed to Telegram for this bot. */
    public function showReceiptToRoot(int $claimId, int $rootId, int $chatId, string $caption): void
    {
        $actor = $this->actors->handle($chatId);

        if ($actor === null || $actor->userId !== $rootId || ! $actor->allows('purchases.view')) {
            throw new NotificationUnavailable('not_authorized');
        }

        $row = $this->payments->detail($rootId, $claimId);
        $keyboard = json_encode(['inline_keyboard' => $actor->allows('purchases.review')
            ? [[['text' => '✅ تأیید پرداخت', 'callback_data' => 'bank:decide:'.$claimId.':a'],
                ['text' => '❌ رد رسید', 'callback_data' => 'bank:decide:'.$claimId.':r']]]
            : [[['text' => '🧾 صف رسیدها', 'callback_data' => 'bank:list']]]], JSON_THROW_ON_ERROR);

        $this->photo('admin', $chatId, $row->photoFileId, (string) $row->sourceBotKey, $caption, $keyboard);
    }

    /** Protected Admin-only photo; reuses the existing Sales→Admin Telegram bridge. */
    public function showWalletTopupPhotoToRoot(int $topupId, int $rootId, int $chatId, string $caption): void
    {
        $actor = $this->actors->handle($chatId);
        if ($actor === null || $actor->userId !== $rootId || ! $actor->allows('topups.view')) {
            throw new NotificationUnavailable('not_authorized');
        }

        $row = app(BrowsePhotoTopups::class)->photo($rootId, $topupId);
        $buttons = [];
        if ($actor->allows('topups.review')) {
            $buttons[] = [
                ['text' => '🔎 بررسی واریز', 'callback_data' => 'finance:topup:decision:'.$topupId.':a'],
                ['text' => '❌ رد رسید', 'callback_data' => 'finance:topup:decision:'.$topupId.':r'],
            ];
        }
        $buttons[] = [['text' => '◀️ صف شارژ', 'callback_data' => 'finance:topups:1']];
        $keyboard = json_encode(['inline_keyboard' => $buttons], JSON_THROW_ON_ERROR);

        $this->photo('admin', $chatId, $row['file_id'], 'sales', $caption, $keyboard);
    }

    /** End delivery also replaces the persistent keyboard, removing Trial immediately. */
    private function trialMenu(int $userId, int $chat, bool $ended = false): string
    {
        $actor = $this->actors->handle($chat);
        if ($actor === null || $actor->userId !== $userId) {
            throw new NotificationUnavailable('invalid_recipient');
        }
        $keyboard = app(ReplyMenuRenderer::class)->salesKeyboard($actor, ! $ended);
        if ($ended) {
            $keyboard->addRow(KeyboardButton::make(ReplyMenuLabels::CATALOG));
        }

        return json_encode($keyboard, JSON_THROW_ON_ERROR);
    }

    private function text(string $bot, int $chat, string $text, ?string $keyboard = null, ?string $parseMode = null): void
    {
        $fields = ['chat_id' => $chat, 'text' => $text, 'protect_content' => 'true'];
        if ($parseMode !== null) {
            $fields['parse_mode'] = $parseMode;
        }
        if ($keyboard !== null) {
            $fields['reply_markup'] = $keyboard;
        }
        $result = Http::timeout(10)->asForm()->post($this->api($this->token($bot), 'sendMessage'), $fields);

        $this->check($result, $bot, $chat);
    }

    private function photo(string $bot, int $chat, string $fileId, string $sourceBot, string $caption, string $keyboard): void
    {
        $token = $this->token($bot);
        $fields = [
            'chat_id' => $chat, 'caption' => $caption,
            'reply_markup' => $keyboard, 'protect_content' => 'true',
        ];

        if ($bot === $sourceBot) {
            $response = Http::timeout(12)->asForm()
                ->post($this->api($token, 'sendPhoto'), $fields + ['photo' => $fileId]);
            $this->check($response, $bot, $chat);

            return;
        }

        // Cross-bot file_id cannot be reused. Download only from Telegram's
        // fixed official host, with strictly constrained path and size.
        $sourceToken = $this->token($sourceBot);
        $file = Http::timeout(8)->asForm()
            ->post($this->api($sourceToken, 'getFile'), ['file_id' => $fileId]);
        $this->check($file, $sourceBot);
        $path = $file->json('result.file_path');
        $bytesCount = $file->json('result.file_size');

        if (! is_string($path) || preg_match('/\A[a-zA-Z0-9_\/.\\-]{1,255}\z/D', $path) !== 1
            || str_contains($path, '..') || str_starts_with($path, '/')
            || ! is_int($bytesCount) || $bytesCount < 1
            || $bytesCount > (int) config('telegram_notifications.max_photo_bytes')) {
            throw new NotificationUnavailable('invalid_media');
        }

        $image = Http::timeout(10)->withOptions(['allow_redirects' => false])
            ->get('https://api.telegram.org/file/bot'.$sourceToken.'/'.$path);

        if (! $image->successful()) {
            throw new RuntimeException('Telegram photo source unavailable.');
        }

        $data = $image->body();

        // Some otherwise valid JPEGs carry non-fatal libjpeg metadata warnings.
        // Do not let GD warnings leak data or bypass the MIME/size checks.
        $dimensions = @getimagesizefromstring($data);

        $mime = is_array($dimensions) ? $dimensions['mime'] : null;

        if (strlen($data) > (int) config('telegram_notifications.max_photo_bytes')
            || strlen($data) < 1 || ! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            throw new NotificationUnavailable('invalid_media');
        }

        $filename = $mime === 'image/png' ? 'receipt.png' : 'receipt.jpg';
        $upload = Http::timeout(12)->attach('photo', $data, $filename, ['Content-Type' => $mime])
            ->post($this->api($token, 'sendPhoto'), $fields);
        $this->check($upload, $bot, $chat);
    }

    private function check(Response $response, string $bot, ?int $chatId = null): void
    {
        if ($response->status() === 403) {
            if ($chatId !== null) {
                DB::table('telegram_bot_contacts')->where('bot_key', $bot)
                    ->where('chat_id', $chatId)
                    ->update(['delivery_status' => 'blocked', 'updated_at' => now()]);
            }

            throw new NotificationUnavailable($chatId === null ? 'source_unavailable' : 'blocked');
        }

        if ($response->status() === 429) {
            throw new RuntimeException('Telegram rate limited.', 429);
        }

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new RuntimeException('Telegram transport unavailable.');
        }
    }

    private function token(string $bot): string
    {
        if ($bot === 'sales' && config('telegram.sales.enabled', false) !== true) {
            throw new NotificationUnavailable('sales_disabled');
        }

        $token = match ($bot) {
            'admin' => config('nutgram.token'),
            'sales' => config('telegram.sales.token'),
            default => null,
        };

        if (! is_string($token) || $token === ''
            || ($bot === 'sales' && $token === config('nutgram.token'))) {
            throw new NotificationUnavailable('missing_credentials');
        }

        return $token;
    }

    private function api(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }
}
