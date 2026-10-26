<?php

namespace App\Interfaces\Telegram\Support;

final class ReplyMenuLabels
{
    public const SALES_BUY = '🟢 خرید سرویس';

    public const SALES_SUBSCRIPTIONS = '📋 سرویس‌های من';

    public const SALES_ACCOUNT = '👤 حساب کاربری';

    public const SALES_SUPPORT = '💬 پشتیبانی';

    public const SALES_TUTORIAL = '📚 آموزش';

    public const SALES_WALLET = '💰 اعتبار من';

    public const SALES_TRIAL = '🧪 تست رایگان';

    public const SALES_REPORTS = '📊 گزارش‌های من';

    public const BUY = '🛒 خرید اشتراک';

    public const SUBSCRIPTIONS = '📦 سرویس‌های من';

    public const CATALOG = '📋 پلن‌ها و قیمت‌ها';

    public const ACCOUNT = '👤 حساب من';

    public const INVITE = '🎁 دعوت دوستان';

    public const SETTINGS = '⚙️ تنظیمات';

    public const ADMIN = '🛡️ مدیریت';

    public const WALLET = '💰 کیف پول پنل فروش';

    public const OLD_WALLET_PANEL = '💼 کیف پول پنل فروش';

    /** Stale reply keyboards remain supported after text-only rename. */
    public const LEGACY_WALLET = '💼 کیف پول نماینده';

    public const BUILDER = '🧩 پلن‌ساز پنل فروش';

    public const OLD_BUILDER_PANEL = '💼 پلن‌ساز پنل فروش';

    public const LEGACY_BUILDER = '💼 پلن‌ساز نماینده';

    public const USERS = '👥 کاربران';

    public const ROLES = '🔐 نقش‌ها و دسترسی‌ها';

    public const PLANS = '📦 مدیریت پلن‌ها';

    public const SALES_SECTION = '📦 محصولات و پلن‌ها';

    public const FINANCE_SECTION = '💳 امور مالی';

    public const SERVERS = '🖥️ وضعیت سرورها';

    public const STATS = '📊 آمار و گزارش‌ها';

    public const DENABAN = '🏔️ دنابان';

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
            self::SETTINGS, self::ADMIN, self::WALLET, self::BUILDER, self::SALES_REPORTS,
            self::LEGACY_WALLET, self::LEGACY_BUILDER,
            self::OLD_WALLET_PANEL, self::OLD_BUILDER_PANEL, self::USERS,
            self::ROLES, self::PLANS, self::PRODUCTS, self::SALES_SECTION,
            self::FINANCE_SECTION, self::SERVERS, self::STATS, self::DENABAN, self::TOPUPS, self::RECEIPTS, self::HOME,
        ];
    }
}
