<?php

declare(strict_types=1);

use App\Support\PersianCalendar;

it('displays Jalali civil dates and Tehran local time without changing the represented instant', function (): void {
    expect(PersianCalendar::date('2026-10-22'))->toBe('۱۴۰۵/۰۷/۳۰')
        ->and(PersianCalendar::date('2026-10-23'))->toBe('۱۴۰۵/۰۸/۰۱')
        ->and(PersianCalendar::date('2026-03-20'))->toBe('۱۴۰۴/۱۲/۲۹')
        ->and(PersianCalendar::date('2026-03-21'))->toBe('۱۴۰۵/۰۱/۰۱')
        ->and(PersianCalendar::localDateTime('2026-10-23 15:50'))->toBe('۱۴۰۵/۰۸/۰۱ ۱۵:۵۰')
        ->and(PersianCalendar::utcDateTime('2026-10-22 21:00:00'))->toBe('۱۴۰۵/۰۸/۰۱ ۰۰:۳۰');
});

it('handles Persian leap-day and Nowruz transition for canonical dates', function (): void {
    expect(PersianCalendar::date('2025-03-20'))->toBe('۱۴۰۳/۱۲/۳۰')
        ->and(PersianCalendar::date('2025-03-21'))->toBe('۱۴۰۴/۰۱/۰۱')
        ->and(PersianCalendar::dayOfMonth(new DateTimeImmutable('2026-10-22 12:00', new DateTimeZone('Asia/Tehran'))))->toBe(30)
        ->and(PersianCalendar::dayOfMonth(new DateTimeImmutable('2026-10-23 12:00', new DateTimeZone('Asia/Tehran'))))->toBe(1);
});

it('does not change malformed date values or UTC daily bucket identity', function (): void {
    expect(PersianCalendar::date('2026-02-30'))->toBe('2026-02-30')
        ->and(PersianCalendar::date('unavailable'))->toBe('unavailable')
        ->and(PersianCalendar::localDateTime('unavailable'))->toBe('unavailable')
        ->and(PersianCalendar::date('2026-10-22'))->toBe('۱۴۰۵/۰۷/۳۰');
});
