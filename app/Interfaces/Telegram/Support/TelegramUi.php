<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use InvalidArgumentException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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
            $stale = $exception->getCode() === 400
                && (str_contains($exception->getMessage(), 'query is too old')
                    || str_contains($exception->getMessage(), 'query ID is invalid'));

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
    public static function editText(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        try {
            $bot->editMessageText(text: $text, reply_markup: $keyboard);
        } catch (TelegramException $exception) {
            if ($exception->getCode() !== 400
                || ! str_contains(strtolower($exception->getMessage()), 'message is not modified')) {
                throw $exception;
            }
        }
    }

    public static function code(string $text): string
    {
        return '<code>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
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
            'reseller' => 'نماینده فروش',
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
            default => 'در حال بررسی',
        };
    }

    public static function toman(int $rials): string
    {
        return number_format(intdiv($rials + 9, 10));
    }

    public static function planMode(string $mode): string
    {
        return match ($mode) {
            'preset' => 'ثابت',
            'builder' => 'سفارشی نماینده',
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
            'reseller' => 'نمایندگان فروش',
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
