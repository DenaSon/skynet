<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SubscriptionQrPng;

it('renders a real PNG containing a subscription QR in memory', function (): void {
    $png = app(SubscriptionQrPng::class)->render('https://sub.example.test/private-credential-123');
    $size = getimagesizefromstring($png);

    expect(str_starts_with($png, "\x89PNG\r\n\x1a\n"))->toBeTrue()
        ->and($size)->toBeArray()
        ->and($size[0])->toBeGreaterThan(100)
        ->and($size[1])->toBeGreaterThan(100);
});

it('rejects non-HTTPS, malformed and oversized inputs before rendering', function (): void {
    $renderer = app(SubscriptionQrPng::class);

    foreach (['http://sub.example.test/private', 'not-a-link', 'https://sub.example.test/'.str_repeat('a', 2050)] as $url) {
        expect(fn () => $renderer->render($url))->toThrow(InvalidArgumentException::class);
    }
});
