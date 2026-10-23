<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SubscriptionExpiryDisplay;

it('converts UTC expiry to a Persian date and the correct Tehran clock', function (): void {
    $display = new SubscriptionExpiryDisplay;
    $lines = $display->lines(
        new DateTimeImmutable('2026-10-24T08:20:00Z'),
        new DateTimeImmutable('2026-10-22T08:20:00Z'),
    );

    expect($lines)->toBe([
        '📅 تاریخ انقضا: <b>۱۴۰۵/۰۸/۰۲</b>، ساعت <b>۱۱:۵۰</b> (ایران)',
        '⏳ روزهای باقی‌مانده: <b>۲ روز</b>',
    ]);
});

it('uses the Tehran calendar date when the UTC calendar date differs', function (): void {
    $display = new SubscriptionExpiryDisplay;
    $lines = $display->lines(
        new DateTimeImmutable('2026-03-20T21:00:00Z'),
        new DateTimeImmutable('2026-03-20T20:00:00Z'),
    );

    expect($lines[0])->toBe('📅 تاریخ انقضا: <b>۱۴۰۵/۰۱/۰۱</b>، ساعت <b>۰۰:۳۰</b> (ایران)')
        ->and($lines[1])->toContain('۰ روز (کمتر از ۲۴ ساعت)');
});

it('never shows negative remaining days for expired subscriptions', function (): void {
    $display = new SubscriptionExpiryDisplay;
    $lines = $display->lines(
        new DateTimeImmutable('2026-10-24T08:20:00Z'),
        new DateTimeImmutable('2026-10-25T08:20:00Z'),
    );

    expect($lines[1])->toBe('⏳ روزهای باقی‌مانده: <b>۰ روز (منقضی شده)</b>');
});
