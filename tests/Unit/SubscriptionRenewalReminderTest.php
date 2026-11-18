<?php

declare(strict_types=1);

use App\Modules\Provisioning\Application\SubscriptionUsage;
use App\Support\SubscriptionRenewalReminder;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

it('builds natural copyable text for an active service', function (): void {
    $usage = new SubscriptionUsage(
        expireAt: (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        usedBytes: 0,
        limitBytes: 1024,
        status: 'ACTIVE',
    );

    $text = SubscriptionRenewalReminder::text('Matin_1', $usage);

    expect($text)
        ->toStartWith("سلام، وقتتون بخیر 🌱\n\n")
        ->toContain('اعتبار سرویس Matin_1 در تاریخ ', ' ساعت ', ' تموم می‌شه.')
        ->toContain('اگر قصد تمدید دارید، پیام بدید تا راهنمایی‌تون کنم.')
        ->not->toContain('تهران', '«', '»');
});

it('uses past-tense copy and a generic service label for an expired service without a customer name', function (): void {
    $usage = new SubscriptionUsage(
        expireAt: (new DateTimeImmutable('-1 day', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        usedBytes: 0,
        limitBytes: 1024,
        status: 'EXPIRED',
    );

    $text = SubscriptionRenewalReminder::text(null, $usage);

    expect($text)
        ->toContain('اعتبار سرویس شما در تاریخ ', ' ساعت ', ' به پایان رسیده.')
        ->toContain('اگر قصد تمدید دارید، پیام بدید تا راهنمایی‌تون کنم.')
        ->not->toContain('تهران', '«', '»');
});

it('does not create reminders for unsupported or inconsistent provider statuses', function (): void {
    $future = (new DateTimeImmutable('+1 day', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);

    expect(SubscriptionRenewalReminder::text('Matin_1', new SubscriptionUsage($future, 0, 1024, 'DISABLED')))
        ->toBeNull()
        ->and(SubscriptionRenewalReminder::text('Matin_1', new SubscriptionUsage($future, 0, 1024, 'EXPIRED')))
        ->toBeNull();
});
