<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Illuminate\Validation\ValidationException;

final class ModelCatalog
{
    /** Documented candidates; availability must be checked against real GapGPT. */
    public const MODELS = [
        'gpt-6-luna',
        'gapgpt-deepseek-v4.1-flash',
        'gpt-6.1-terra',
        'gpt-6.1-sol',
        'gemini-2.5-flash-lite',
        'gpt-5-nano',
    ];

    // Only these models have passed a real GapGPT smoke test.
    public const SELECTABLE = [
        'gpt-6-luna',
        'gapgpt-deepseek-v4.1-flash',
    ];

    // Candidates are disabled until the operator verifies exact GapGPT IDs and prices.
    public const ECONOMY_CANDIDATES = [
        'gemini-2.5-flash-lite',
        'gpt-5-nano',
    ];

    /**
     * @return list<string>
     */
    public static function selectable(): array
    {
        $enabled = config('denagate_ai.verified_economy_models', []);

        if (! is_array($enabled)) {
            return self::SELECTABLE;
        }

        return [
            ...self::SELECTABLE,
            ...array_values(array_intersect(self::ECONOMY_CANDIDATES, $enabled)),
        ];
    }

    public function selectedByNumber(int $number): ?string
    {
        return self::selectable()[$number - 1] ?? null;
    }

    public function resolve(?string $requested = null): string
    {
        $model = $requested ?? config('denagate_ai.default_model');

        if (! is_string($model) || ! in_array($model, self::MODELS, true)) {
            throw ValidationException::withMessages(['model' => 'Unsupported AI model.']);
        }

        return $model;
    }
}
