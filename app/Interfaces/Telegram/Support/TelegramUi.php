<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use InvalidArgumentException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\Message;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

final class TelegramUi
{
    /**
     * An expired acknowledgement is a transport-only failure, not a failed
     * business operation. Other Telegram failures must still propagate.
     */
    public static function answerCallback(Nutgram $bot, ?string $text = null): void
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }

        try {
            if ($text === null) {
                $bot->answerCallbackQuery();
            } else {
                $bot->answerCallbackQuery(text: $text);
            }
        } catch (TelegramException $exception) {
            $description = strtolower($exception->getMessage());
            $stale = $exception->getCode() === 400
                && (str_contains($description, 'query is too old')
                    || str_contains($description, 'query id is invalid'));

            if (! $stale) {
                throw $exception;
            }
        }
    }

    /**
     * Telegram treats an unchanged text/markup edit as HTTP 400 even though
     * the requested UI is already visible. Do not retry that no-op update.
     * Every other edit failure remains actionable.
     */
    public static function editText(
        Nutgram $bot,
        string $text,
        ?InlineKeyboardMarkup $keyboard = null,
        ?string $parseMode = null,
    ): void {
        // The QR/link card is a Telegram *photo*. editMessageText cannot turn
        // a photo caption into a text message (HTTP 400); navigate with a new
        // text message instead. Do not mutate or erase the private QR.
        $callbackMessage = $bot->isCallbackQuery() ? $bot->callbackQuery()?->message : null;
        if ($callbackMessage instanceof Message
            && ($callbackMessage->photo !== null
                || ($callbackMessage->rich_message !== null && $callbackMessage->reply_markup === null))) {
            // Rich Home uses a persistent Reply Keyboard, not editable InlineMarkup.
            // Callbacks embedded inside Rich content must open a new page.
            $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: $parseMode);

            return;
        }

        try {
            $bot->editMessageText(text: $text, reply_markup: $keyboard, parse_mode: $parseMode);
        } catch (TelegramException $exception) {
            if (! self::isUnmodified($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Rich service preview uses the same Telegram navigation semantics as text.
     * QR/photo cards are left untouched and receive a new rich message instead.
     */
    public static function editRichText(
        Nutgram $bot,
        InputRichMessage $richMessage,
        ?InlineKeyboardMarkup $keyboard = null,
    ): void {
        $callbackMessage = $bot->isCallbackQuery() ? $bot->callbackQuery()?->message : null;
        if (! $bot->isCallbackQuery()
            || ($callbackMessage instanceof Message
                && ($callbackMessage->photo !== null
                    || ($callbackMessage->rich_message !== null && $callbackMessage->reply_markup === null)))) {
            // A reply-keyboard Rich Home cannot be edited; send a fresh Rich page.
            $bot->sendRichMessage(rich_message: $richMessage, reply_markup: $keyboard);

            return;
        }

        try {
            $bot->editMessageText(rich_message: $richMessage, reply_markup: $keyboard);
        } catch (TelegramException $exception) {
            if (! self::isUnmodified($exception)) {
                throw $exception;
            }
        }
    }

    public static function editCaption(
        Nutgram $bot,
        string $caption,
        ?InlineKeyboardMarkup $keyboard = null,
        ?int $chatId = null,
        ?int $messageId = null,
    ): void {
        try {
            $bot->editMessageCaption(
                caption: $caption,
                chat_id: $chatId,
                message_id: $messageId,
                reply_markup: $keyboard,
            );
        } catch (TelegramException $exception) {
            if (! self::isUnmodified($exception)) {
                throw $exception;
            }
        }
    }

    private static function isUnmodified(TelegramException $exception): bool
    {
        return $exception->getCode() === 400
            && str_contains(strtolower($exception->getMessage()), 'message is not modified');
    }

    /** Short, plain-text Admin receipt caption, shared by live and queued delivery. */
    public static function purchaseReceiptCaption(object $receipt, bool $new = false): string
    {
        $name = self::receiptSingleLine((string) $receipt->buyerName, 60);
        $plan = self::receiptSingleLine((string) $receipt->productName, 60);
        $telegram = $receipt->buyerTelegramId === null ? 'متصل نیست' : (string) $receipt->buyerTelegramId;

        return implode("\n", [
            ($new ? '🧾 رسید جدید خرید #' : '🧾 رسید در انتظار بررسی #').$receipt->id,
            '',
            '👤 اطلاعات خریدار',
            'نام ثبت‌شده: '.($name === '' ? 'ثبت نشده' : $name),
            'شناسه کاربر: #'.$receipt->buyerUserId,
            'شناسه تلگرام: '.$telegram,
            '',
            '📦 اطلاعات سفارش',
            'سفارش: #'.$receipt->orderId,
            'پلن: '.$plan,
            'مبلغ: '.number_format(intdiv((int) $receipt->amountRials + 9, 10)).' تومان',
            '',
            '⚠️ قبل از تأیید، دریافت واقعی وجه را در بانک بررسی کنید.',
        ]);
    }

    /** User-controlled display names must not inject fake caption lines or bidi overrides. */
    private static function receiptSingleLine(string $value, int $limit): string
    {
        $value = (string) preg_replace('/[\\p{Cc}\\p{Zl}\\p{Zp}\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]/u', ' ', $value);
        $value = trim((string) preg_replace('/\\s+/u', ' ', $value));

        return mb_substr($value, 0, $limit);
    }

    public static function code(string $text): string
    {
        return '<code>'.self::escape($text).'</code>';
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Optional feedback must never prevent an authorized read from completing. */
    public static function activity(Nutgram $bot): void
    {
        try {
            $bot->sendChatAction(action: ChatAction::TYPING);
        } catch (\Throwable) {
            // No retry: a typing indicator is not a business operation.
        }
    }

    public static function copy(string $text, string $label = '📋 کپی نمونه'): InlineKeyboardButton
    {
        if (mb_strlen($text) < 1 || mb_strlen($text) > 256) {
            throw new InvalidArgumentException('Telegram copy text must contain 1–256 characters.');
        }

        return InlineKeyboardButton::make($label, copy_text: CopyTextButton::make($text));
    }

    public static function role(string $role): string
    {
        return match ($role) {
            'member' => 'کاربر',
            'reseller' => 'پنل فروش',
            'admin' => 'مدیر',
            'super-admin' => 'مدیر اصلی',
            'accountant' => 'حسابدار',
            default => $role,
        };
    }

    public static function topupStatus(string $status): string
    {
        return match ($status) {
            'requested' => 'ثبت‌شده',
            'submitted' => 'در انتظار بررسی',
            'approved' => 'تأییدشده',
            'rejected' => 'ردشده',
            'voided' => 'ابطال‌شده',
            default => 'در حال بررسی',
        };
    }

    public static function toman(int $rials): string
    {
        return number_format(intdiv($rials + 9, 10));
    }

    /** Format a toman amount using Persian digits and a readable thousands separator. */
    public static function tomanPersian(int $rials): string
    {
        return strtr(self::toman($rials), [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ',' => '٬',
        ]);
    }

    public static function planButtonLabel(string $name, ?int $trafficGb, int $durationDays): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}]+/u', ' ', $name));
        if (mb_strlen($name) > 14) {
            $name = rtrim(mb_substr($name, 0, 13)).'…';
        }

        $traffic = $trafficGb === null ? 'نامحدود' : $trafficGb.'گیگ';

        return $name.' · '.$traffic.'/'.$durationDays.'روز';
    }

    public static function planMode(string $mode): string
    {
        return match ($mode) {
            'preset' => 'ثابت',
            'builder' => 'سفارشی پنل فروش',
            default => 'نامشخص',
        };
    }

    public static function planKind(string $kind): string
    {
        return match ($kind) {
            'volume' => 'حجمی',
            'time' => 'زمانی (حجم نامحدود)',
            'hybrid' => 'حجم و زمان',
            default => 'نامشخص',
        };
    }

    public static function audience(string $audience): string
    {
        return match ($audience) {
            'public' => 'کاربران عادی',
            'reseller' => 'پنل‌های فروش',
            'both' => 'همه کاربران',
            default => 'نامشخص',
        };
    }

    public static function status(string $status): string
    {
        return match ($status) {
            'draft' => '📝 پیش‌نویس',
            'active' => '🟢 فعال',
            'inactive' => '⏸️ غیرفعال',
            'archived' => '📦 بایگانی‌شده',
            default => 'نامشخص',
        };
    }
}
