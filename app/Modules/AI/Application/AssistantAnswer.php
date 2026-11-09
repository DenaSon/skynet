<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

final readonly class AssistantAnswer
{
    public function __construct(
        public string $model,
        public string $text,
        public int $inputTokens,
        public int $outputTokens,
    ) {}
}
