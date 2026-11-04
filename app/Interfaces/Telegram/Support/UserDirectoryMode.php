<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

/** Stable short callback modes shared by the user list, details and support profile. */
final class UserDirectoryMode
{
    public const MODES = ['a', 'r', 's', 'm', 'aw', 'mw', 'rw', 'ap', 'mp', 'rp'];

    public static function valid(string $mode): bool
    {
        return in_array($mode, self::MODES, true);
    }

    public static function rootOnly(string $mode): bool
    {
        return ! in_array($mode, ['a', 'r', 's'], true);
    }

    public static function type(string $mode): string
    {
        return str_starts_with($mode, 'm') ? 'm' : (str_starts_with($mode, 'r') ? 'r' : 'a');
    }

    public static function sort(string $mode): string
    {
        return str_ends_with($mode, 'w') ? 'wallet' : (str_ends_with($mode, 'p') ? 'purchase' : 'recent');
    }

    public static function for(string $type, string $sort): ?string
    {
        if (! in_array($type, ['a', 'm', 'r'], true) || ! in_array($sort, ['recent', 'wallet', 'purchase'], true)) {
            return null;
        }

        return $type.match ($sort) {
            'wallet' => 'w',
            'purchase' => 'p',
            default => '',
        };
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
