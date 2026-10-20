<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

/**
 * The official Remnawave Subpage serves the provider's existing subscription
 * URL. Never manufacture a short UUID URL or redirect secrets to another host.
 */
final readonly class SubscriptionPageLink
{
    public function configured(): bool
    {
        return $this->host() !== null;
    }

    public function matches(string $providerUrl): bool
    {
        $host = $this->host();
        if ($host === null
            || strlen($providerUrl) > 2048
            || preg_match('/[\x00-\x20\x7F]/', $providerUrl) === 1
            || ! str_starts_with($providerUrl, 'https://')) {
            return false;
        }

        $url = parse_url($providerUrl);

        return is_array($url)
            && ($url['scheme'] ?? null) === 'https'
            && strtolower((string) ($url['host'] ?? '')) === $host
            && ! isset($url['port'])
            && ! isset($url['user'])
            && ! isset($url['pass'])
            && ! isset($url['fragment'])
            && isset($url['path'])
            && $url['path'] !== '' && $url['path'] !== '/';
    }

    private function host(): ?string
    {
        $host = strtolower(trim((string) config('sales.subscription_page_host', '')));

        if ($host === '' || ! str_contains($host, '.') || strlen($host) > 253
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $host;
    }
}
