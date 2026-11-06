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

    public const SALES_PANEL_REQUEST = '🧩 درخواست نمایندگی';

    public const SALES_PANEL_STATUS = '📨 وضعیت درخواست نمایندگی';

    public const SALES_REPORTS = '📊 گزارش‌های من';

    public const SALES_APP = '📱 اپلیکیشن';

    public const SALES_REWARD = '🎁 دریافت جایزه';

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

    public const BUILDER = '🧩 پلن سفارشی';

    /** Previously delivered Telegram reply keyboards stay routable. */
    public const PREVIOUS_BUILDER = '🧩 پلن‌ساز پنل فروش';

    public const OLD_BUILDER_PANEL = '💼 پلن‌ساز پنل فروش';

    public const LEGACY_BUILDER = '💼 پلن‌ساز نماینده';

    public const USERS = '👥 کاربران';

    public const ROLES = '🔐 نقش‌ها و دسترسی‌ها';

    public const PLANS = '📦 مدیریت پلن‌ها';

    public const SALES_SECTION = '📦 محصولات و پلن‌ها';

    public const FINANCE_SECTION = '💳 امور مالی';

    public const SERVERS = '🖥️ وضعیت سرورها';

    public const STATS = '📊 آمار و گزارش‌ها';

    /** Keep old Telegram keyboards routable after the visual rename. */
    public const DENABAN = '🏔️ دنابان';

    public const DENABAN_RADAR = '📡 دنابان';

    public const DASHBOARD = '🧭 داشبورد';

    public const REPORTS = '📈 گزارش‌ها';

    public const CONTROL_PANEL = '⚙️ کنترل پنل';

    public const MANAGEMENT_KPIS = '📊 شاخص‌های مدیریتی';

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
            self::BUY, self::SUBSCRIPTIONS, self::CATALOG, self::ACCOUNT, self::INVITE, self::SALES_REWARD,
            self::SETTINGS, self::ADMIN, self::SALES_WALLET, self::WALLET, self::BUILDER, self::SALES_REPORTS,
            self::SALES_PANEL_REQUEST, self::SALES_PANEL_STATUS, self::LEGACY_WALLET, self::LEGACY_BUILDER,
            self::OLD_WALLET_PANEL, self::OLD_BUILDER_PANEL, self::PREVIOUS_BUILDER, self::USERS,
            self::ROLES, self::PLANS, self::PRODUCTS, self::SALES_SECTION,
            self::FINANCE_SECTION, self::SERVERS, self::STATS, self::DENABAN, self::MANAGEMENT_KPIS, self::TOPUPS, self::RECEIPTS, self::HOME,
            self::DENABAN_RADAR, self::DASHBOARD, self::REPORTS, self::CONTROL_PANEL,
        ];
    }
}
