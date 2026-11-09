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
    ];

    // Only these models have passed a real GapGPT smoke test.
    public const SELECTABLE = [
        'gpt-6-luna',
        'gapgpt-deepseek-v4.1-flash',
    ];

    public function selectedByNumber(int $number): ?string
    {
        return self::SELECTABLE[$number - 1] ?? null;
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
