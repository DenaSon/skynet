<?php

namespace App\Interfaces\Telegram\Support;

final class ReplyMenuLabels
{
    public const BUY = '🛒 خرید اشتراک';
    public const SUBSCRIPTIONS = '📦 سرویس‌های من';
    public const ACCOUNT = '👤 حساب من';
    public const INVITE = '🎁 دعوت دوستان';
    public const SETTINGS = '⚙️ تنظیمات';
    public const ADMIN = '🛡️ مدیریت';
    public const WALLET = '💼 کیف پول نماینده';
    public const USERS = '👥 کاربران';
    public const ROLES = '🔐 نقش‌ها و دسترسی‌ها';
    public const TOPUPS = '💳 بررسی شارژها';
    public const RECEIPTS = '🧾 بررسی رسیدهای خرید';
    public const HOME = '🏠 منوی اصلی';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BUY, self::SUBSCRIPTIONS, self::ACCOUNT, self::INVITE,
            self::SETTINGS, self::ADMIN, self::WALLET, self::USERS,
            self::ROLES, self::TOPUPS, self::RECEIPTS, self::HOME,
        ];
    }
}
