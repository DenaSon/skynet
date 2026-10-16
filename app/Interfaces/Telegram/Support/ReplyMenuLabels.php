<?php

namespace App\Interfaces\Telegram\Support;

final class ReplyMenuLabels
{
    public const BUY = '🛒 خرید اشتراک';

    public const SUBSCRIPTIONS = '📦 سرویس‌های من';

    public const CATALOG = '📋 پلن‌ها و قیمت‌ها';

    public const ACCOUNT = '👤 حساب من';

    public const INVITE = '🎁 دعوت دوستان';

    public const SETTINGS = '⚙️ تنظیمات';

    public const ADMIN = '🛡️ مدیریت';

    public const WALLET = '💼 کیف پول نماینده';

    public const BUILDER = '💼 پلن‌ساز نماینده';

    public const USERS = '👥 کاربران';

    public const ROLES = '🔐 نقش‌ها و دسترسی‌ها';

    public const PLANS = '📦 مدیریت پلن‌ها';

    public const SALES_SECTION = '📦 محصولات و پلن‌ها';

    public const FINANCE_SECTION = '💳 امور مالی';

    public const SERVERS = '🖥️ وضعیت سرورها';

    public const PRODUCTS = '📚 محصولات پایه';

    public const TOPUPS = '💳 بررسی شارژها';

    public const RECEIPTS = '🧾 بررسی رسیدهای خرید';

    public const HOME = '🏠 منوی اصلی';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::BUY, self::SUBSCRIPTIONS, self::CATALOG, self::ACCOUNT, self::INVITE,
            self::SETTINGS, self::ADMIN, self::WALLET, self::BUILDER, self::USERS,
            self::ROLES, self::PLANS, self::PRODUCTS, self::SALES_SECTION,
            self::FINANCE_SECTION, self::SERVERS, self::TOPUPS, self::RECEIPTS, self::HOME,
        ];
    }
}
