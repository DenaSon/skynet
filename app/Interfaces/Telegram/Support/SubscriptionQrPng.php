<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use InvalidArgumentException;
use RuntimeException;

/** Ephemeral QR rendering; no URL persistence, outbound QR service or provider access. */
final readonly class SubscriptionQrPng
{
    public function render(string $url): string
    {
        if (strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)
            || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new InvalidArgumentException('Invalid subscription URL.');
        }

        if (! extension_loaded('gd') || ! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('PNG rendering is unavailable.');
        }

        return (new PngWriter)->write(new QrCode(data: $url, size: 480, margin: 12))->getString();
    }
}
