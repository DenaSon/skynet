<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use InvalidArgumentException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;

final class TelegramUi
{
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
