<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

/** Stable compact callback modes shared by user lists, details and support profiles. */
final class UserDirectoryMode
{
    // Legacy a/r/s/m/aw/mw/rw/ap/mp/rp modes stay valid.
    // New modes: [segment a|m|r][sort w|p][ascending u][one optional filter].
    public const FILTER_LABELS = [
        '' => 'همه کاربران',
        'b' => 'بدون خرید',
        'q' => 'در انتظار پرداخت',
        'v' => 'نیازمند بررسی',
        'c' => 'اعتبار بدون خرید',
        'i' => 'حساب‌های غیرفعال',
        'h' => 'دارای سرویس',
    ];

    public static function valid(string $mode): bool
    {
        return $mode === 's' || preg_match('/\\A[amr][wp]?u?[bqvcih]?\\z/D', $mode) === 1;
    }

    public static function rootOnly(string $mode): bool
    {
        // Preserve delegated Admin a/r/search; operational and financial filters remain trusted-root-only.
        return ! in_array($mode, ['a', 'r', 's'], true);
    }

    public static function type(string $mode): string
    {
        return str_starts_with($mode, 'm') ? 'm' : (str_starts_with($mode, 'r') ? 'r' : 'a');
    }

    public static function sort(string $mode): string
    {
        return str_contains($mode, 'w') ? 'wallet' : (str_contains($mode, 'p') ? 'purchase' : 'recent');
    }

    public static function direction(string $mode): string
    {
        return str_contains($mode, 'u') ? 'asc' : 'desc';
    }

    public static function filter(string $mode): string
    {
        $last = substr($mode, -1);

        return array_key_exists($last, self::FILTER_LABELS) ? $last : '';
    }

    public static function for(string $type, string $sort, string $direction = 'desc', string $filter = ''): ?string
    {
        if (! in_array($type, ['a', 'm', 'r'], true)
            || ! in_array($sort, ['recent', 'wallet', 'purchase'], true)
            || ! in_array($direction, ['asc', 'desc'], true)
            || ! array_key_exists($filter, self::FILTER_LABELS)) {
            return null;
        }

        return $type.match ($sort) {
            'wallet' => 'w',
            'purchase' => 'p',
            default => '',
        }.($direction === 'asc' ? 'u' : '').$filter;
    }

    public static function pageCallback(string $mode, int $page): string
    {
        return match ($mode) {
            'a' => 'users:page:'.$page,
            'r' => 'users:resellers:'.$page,
            's' => 'users:findpage:'.$page,
            default => 'users:filtered:'.$mode.':'.$page,
        };
    }
}
