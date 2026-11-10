<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final readonly class AskAssistant
{
    private const APPROVED_URLS = [
        'https://api.gapgpt.app/v1',
        'https://api.gapapi.com/v1',
    ];

    public function __construct(private ModelCatalog $models) {}

    /**
     * @param  list<array<string, mixed>>  $history
     */
    public function handle(string $prompt, ?string $modelId = null, array $history = [], bool $customerSupport = false, bool $executive = false): AssistantAnswer
    {
        if (config('denagate_ai.enabled') !== true) {
            throw new RuntimeException('AI is disabled.');
        }

        if ($customerSupport && $executive) {
            throw ValidationException::withMessages(['mode' => 'Conflicting AI assistant modes.']);
        }

        $model = $this->models->resolve($modelId);
        $prompt = trim($prompt);

        if ($prompt === '' || mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            throw ValidationException::withMessages(['prompt' => 'Invalid AI prompt.']);
        }

        if (count($history) > (int) config('denagate_ai.context_max_messages', 30)) {
            throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
        }

        $historyCharacters = 0;
        foreach ($history as $message) {
            if (! in_array($message['role'] ?? null, ['user', 'assistant'], true)
                || ! is_string($message['content'] ?? null)
                || ($length = mb_strlen($message['content'])) < 1
                || $length > (int) config('denagate_ai.max_output_chars', 6000)) {
                throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
            }

            $historyCharacters += $length;
        }

        if ($historyCharacters > (int) config('denagate_ai.context_max_chars', 6000)) {
            throw ValidationException::withMessages(['history' => 'Invalid AI history.']);
        }

        $key = config('ai.providers.gapgpt.key');
        $url = config('ai.providers.gapgpt.url');

        // Prevent missing credentials or unintended upstream endpoints before SDK invocation.
        if (! is_string($key) || trim($key) === ''
            || ! is_string($url) || ! in_array(rtrim($url, '/'), self::APPROVED_URLS, true)) {
            throw new RuntimeException('AI provider is not configured.');
        }

        try {
            $agent = $executive
                ? new DenizExecutiveAssistant
                : new DenixAssistant(customerSupport: $customerSupport);
            $response = $agent->withMessages($history)->prompt(
                $prompt,
                provider: 'gapgpt',
                model: $model,
                timeout: (int) config('denagate_ai.timeout_seconds', 20),
            );

            $text = trim($response->text);

            if ($text === '' || mb_strlen($text) > (int) config('denagate_ai.max_output_chars', 6000)) {
                throw new RuntimeException('Invalid AI response.');
            }

            return new AssistantAnswer(
                model: $model,
                text: $text,
                inputTokens: $response->usage->inputTokens,
                outputTokens: $response->usage->outputTokens,
            );
        } catch (Throwable $error) {
            // Do not log upstream messages, response bodies, prompts, headers or credentials.
            // Record enough safe metadata to locate SDK failures during rollout.
            $category = match (true) {
                $error instanceof RequestException => 'http_error',
                $error instanceof ConnectionException => 'transport_error',
                $error instanceof RuntimeException && $error->getMessage() === 'Invalid AI response.' => 'invalid_response',
                default => 'provider_or_sdk_error',
            };

            $code = $error->getCode();
            Log::warning('AI request failed.', [
                'category' => $category,
                'exception_type' => $error::class,
                'http_status' => $error instanceof RequestException
                    ? $error->response->status()
                    : ($code >= 400 && $code <= 599 ? $code : null),
                'source_file' => basename($error->getFile()),
                'source_line' => $error->getLine(),
                'previous_exception_type' => $error->getPrevious() ? $error->getPrevious()::class : null,
            ]);

            throw new RuntimeException('AI request could not be completed.');
        }
    }
}
