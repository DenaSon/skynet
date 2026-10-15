<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

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

            $caption = "🧾 رسید جدید #{$claimId}\nسفارش #{$orderId}\n\n⚠️ پیش از تأیید، گردش واقعی بانک را بررسی کنید.";
            $buttons = json_encode([
                'inline_keyboard' => [[[
                    'text' => '🧾 بررسی رسید',
                    'callback_data' => 'bank:show:'.$claimId,
                ]]],
            ], JSON_THROW_ON_ERROR);

            try {
                $this->photo($bot, $chat, $row->photoFileId, (string) $row->sourceBotKey, $caption, $buttons);

                return;
            } catch (NotificationUnavailable $e) {
                if ($e->category === 'blocked' || $e->category === 'invalid_bot') {
                    throw $e;
                }
                // Photo missing/unavailable: keep the durable review queue as source of truth.
            } catch (Throwable) {
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
            $text = $claim->status === 'approved'
                ? "✅ پرداخت سفارش #{$order} تأیید شد. درخواست فعال‌سازی ثبت شد؛ سرویس هنوز ساخته نشده است."
                : "❌ رسید سفارش #{$order} رد شد. در صورت نیاز می‌توانید رسید جدید بفرستید.";

            $this->text($bot, $chat, $text);

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
                ['text' => '✅ بررسی تأیید', 'callback_data' => 'bank:choose:'.$claimId.':a'],
                ['text' => '❌ رد رسید', 'callback_data' => 'bank:choose:'.$claimId.':r'],
            ]],
        ], JSON_THROW_ON_ERROR);

        $this->photo('admin', $chatId, $row->photoFileId, (string) $row->sourceBotKey, $caption, $keyboard);
    }

    private function text(string $bot, int $chat, string $text): void
    {
        $result = Http::timeout(10)->asForm()->post($this->api($this->token($bot), 'sendMessage'), [
            'chat_id' => $chat, 'text' => $text, 'protect_content' => 'true',
        ]);

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
