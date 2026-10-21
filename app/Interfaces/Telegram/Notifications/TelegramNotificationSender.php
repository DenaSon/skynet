<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use App\Interfaces\Telegram\Support\SubscriptionPageLink;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramRecipient;
use App\Modules\Sales\Application\ManualBankPayments;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

        if ($delivery->event_type === 'WalletTopupSubmitted') {
            $topupId = (int) $delivery->topup_id;
            if ($bot !== 'admin' || $topupId < 1) {
                throw new NotificationUnavailable('invalid_recipient');
            }

            $actor = $this->actors->handle($chat);
            if ($actor === null || $actor->userId !== $userId || ! $actor->canManageRoles()) {
                throw new NotificationUnavailable('not_authorized');
            }

            // Fresh reviewer authorization and submitted status are checked again
            // by the existing protected finance-photo read Action.
            try {
                $row = app(BrowsePhotoTopups::class)->photo($userId, $topupId);
            } catch (Throwable) {
                throw new NotificationUnavailable('already_reviewed');
            }

            $caption = "🧾 درخواست شارژ کیف پول #{$topupId}"
                ."\nکاربر #{$row['user_id']}"
                ."\nمبلغ: ".TelegramUi::toman($row['amount_rials'])." تومان"
                ."\n\n⚠️ فقط پس از مشاهده واریز واقعی در بانک تأیید کنید.";
            try {
                $this->showWalletTopupPhotoToRoot($topupId, $userId, $chat, $caption);

                return;
            } catch (NotificationUnavailable $e) {
                if (in_array($e->category, ['not_authorized', 'blocked', 'missing_credentials'], true)) {
                    throw $e;
                }
            } catch (Throwable $e) {
                if ($e instanceof RuntimeException && $e->getCode() === 429) {
                    throw $e;
                }
            }

            $keyboard = json_encode([
                'inline_keyboard' => [[
                    ['text' => '🧾 بررسی در امور مالی', 'callback_data' => 'finance:topups:1'],
                ]],
            ], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat,
                "🧾 رسید شارژ کیف پول #{$topupId} دریافت شد.\n"
                ."کاربر #{$row['user_id']} · مبلغ: ".TelegramUi::toman($row['amount_rials'])
                ." تومان\nتصویر فعلاً قابل ارسال نیست؛ درخواست در صف امور مالی محفوظ است.",
                $keyboard,
            );

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
                ? "<b>✅ کیف پول شما شارژ شد</b>\n\nدرخواست #{$topupId}"
                    ."\nمبلغ افزوده‌شده: <b>{$amount} تومان</b>"
                : "<b>❌ درخواست شارژ تأیید نشد</b>\n\nدرخواست #{$topupId}"
                    ."\nمبلغ درخواست: <b>{$amount} تومان</b>"
                    ."\n\nبرای پیگیری با پشتیبانی تماس بگیرید.";
            try {
                $balance = app(ViewWallet::class)->handle($userId)->balanceRials;
            } catch (Throwable) {
                throw new NotificationUnavailable('inactive_wallet');
            }
            $message .= "\nموجودی فعلی: <b>".TelegramUi::toman($balance)." تومان</b>";

            $keyboard = json_encode([
                'inline_keyboard' => [[
                    ['text' => '💼 مشاهده کیف پول', 'callback_data' => 'sales:wallet'],
                ]],
            ], JSON_THROW_ON_ERROR);
            $this->text($bot, $chat, $message, $keyboard, 'HTML');

            return;
        }

        if ($delivery->event_type === 'ReceiptSubmitted') {
            $actor = $this->actors->handle($chat);

            if ($actor === null || $actor->userId !== $userId || ! $actor->canManageRoles()) {
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

        if ($actor === null || $actor->userId !== $rootId || ! $actor->canManageRoles()) {
            throw new NotificationUnavailable('not_authorized');
        }

        $row = $this->payments->detail($rootId, $claimId);
        $keyboard = json_encode([
            'inline_keyboard' => [[
                ['text' => '✅ تأیید پرداخت', 'callback_data' => 'bank:decide:'.$claimId.':a'],
                ['text' => '❌ رد رسید', 'callback_data' => 'bank:decide:'.$claimId.':r'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->photo('admin', $chatId, $row->photoFileId, (string) $row->sourceBotKey, $caption, $keyboard);
    }

    /** Protected Admin-only photo; reuses the existing Sales→Admin Telegram bridge. */
    public function showWalletTopupPhotoToRoot(int $topupId, int $rootId, int $chatId, string $caption): void
    {
        $actor = $this->actors->handle($chatId);
        if ($actor === null || $actor->userId !== $rootId || ! $actor->canManageRoles()) {
            throw new NotificationUnavailable('not_authorized');
        }

        $row = app(BrowsePhotoTopups::class)->photo($rootId, $topupId);
        $keyboard = json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ بررسی تأیید', 'callback_data' => 'finance:topup:decision:'.$topupId.':a'],
                    ['text' => '❌ رد رسید', 'callback_data' => 'finance:topup:decision:'.$topupId.':r'],
                ],
                [['text' => '◀️ صف شارژ', 'callback_data' => 'finance:topups:1']],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->photo('admin', $chatId, $row['file_id'], 'sales', $caption, $keyboard);
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
