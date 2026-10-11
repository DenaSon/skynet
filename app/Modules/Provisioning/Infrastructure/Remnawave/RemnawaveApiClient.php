<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Infrastructure\Remnawave;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Version-pinned, transport-only Remnawave HTTP client.
 *
 * It deliberately does not provision orders, expose endpoints to Telegram,
 * retry writes, unwrap response envelopes, or mutate billing records.
 */
final readonly class RemnawaveApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $apiToken,
        private int $timeoutSeconds = 15,
    ) {
        $parts = parse_url($baseUrl);

        if (
            ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            throw new InvalidArgumentException('Remnawave API URL must be an HTTPS origin without credentials, query or fragment.');
        }

        if (trim($apiToken) === '') {
            throw new InvalidArgumentException('Remnawave API token is required.');
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 120) {
            throw new InvalidArgumentException('Remnawave timeout must be between 1 and 120 seconds.');
        }
    }

    /**
     * Execute an explicitly mapped OpenAPI operation.
     *
     * Callers MUST authorize the business action before calling this client.
     * Success responses are returned as the original JSON envelope. The
     * application/DTO layer interprets the response rather than this client.
     * No requests are retried: writes must use reconciliation and deduplication
     * at the Provisioning layer before safe retries can be introduced.
     *
     * @param  array<string, int|string>  $pathParameters
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<mixed>|string|null
     */
    public function call(
        string $operationId,
        array $pathParameters = [],
        array $query = [],
        ?array $body = null,
    ): array|string|null {
        $operation = RemnawaveOperationCatalog::find($operationId);

        if ($operation === null) {
            throw new InvalidArgumentException('Unknown Remnawave operation ID.');
        }

        foreach (array_keys($query) as $name) {
            if (! in_array($name, $operation['queryParameters'], true)) {
                throw new InvalidArgumentException("Unsupported Remnawave query parameter: {$name}");
            }
        }

        foreach ($operation['requiredQuery'] as $name) {
            if (! array_key_exists($name, $query) || $query[$name] === null) {
                throw new InvalidArgumentException("Missing required Remnawave query parameter: {$name}");
            }
        }

        if ($operation['requiredBody'] && $body === null) {
            throw new InvalidArgumentException('This Remnawave operation requires a JSON request body.');
        }

        if (! $operation['hasBody'] && $body !== null) {
            throw new InvalidArgumentException('This Remnawave operation does not accept a JSON request body.');
        }

        if ($body !== null) {
            foreach ($operation['requiredBodyFields'] as $name) {
                if (! array_key_exists($name, $body) || $body[$name] === null) {
                    throw new InvalidArgumentException("Missing required Remnawave JSON field: {$name}");
                }
            }
        }

        $path = preg_replace_callback(
            '/\\{([A-Za-z][A-Za-z0-9]*)\\}/',
            static function (array $matches) use ($pathParameters, $operation): string {
                $key = $matches[1];
                $value = $pathParameters[$key] ?? null;

                if (! is_string($value) && ! is_int($value)) {
                    throw new InvalidArgumentException("Missing or invalid Remnawave path parameter: {$key}");
                }

                if ((string) $value === '') {
                    throw new InvalidArgumentException("Empty Remnawave path parameter: {$key}");
                }

                if (in_array($key, $operation['numericPathParameters'], true)
                    && ! preg_match('/^[1-9][0-9]*$/D', (string) $value)) {
                    throw new InvalidArgumentException("Remnawave path parameter must be a positive integer: {$key}");
                }

                return rawurlencode((string) $value);
            },
            $operation['path'],
        );

        if ($path === null) {
            throw new RuntimeException('Unable to map Remnawave API path.');
        }

        // No dynamic hosts/paths are accepted from Telegram or the customer.
        $options = ['query' => $query];

        if ($body !== null) {
            $options['json'] = $body;
        }

        $request = Http::acceptJson()
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(min(5, $this->timeoutSeconds))
            ->withOptions(['allow_redirects' => false]);

        // Public subscription/auth routes do not receive our administrative token.
        if ($operation['requiresAuth']) {
            $request = $request->withToken($this->apiToken);
        }

        $response = $request->send($operation['method'], rtrim($this->baseUrl, '/').$path, $options);

        // Do not forward bearer tokens to a redirect target.
        if ($response->redirect()) {
            throw new RuntimeException('Unexpected Remnawave API redirect.');
        }

        $response->throw();

        return $this->decode($response);
    }

    /**
     * @return array<mixed>|string|null
     */
    private function decode(Response $response): array|string|null
    {
        if ($response->body() === '') {
            return null;
        }

        $decoded = $response->json();

        if (is_array($decoded)) {
            return $decoded;
        }

        return $response->body();
    }
}
