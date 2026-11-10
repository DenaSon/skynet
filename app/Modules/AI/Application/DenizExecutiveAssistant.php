<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

/**
 * Independent Executive response budget; Business and regular group chat retain
 * the existing 700-token assistant. There are still no executable AI tools.
 */
#[MaxTokens(1100)]
final class DenizExecutiveAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return app(DenizExecutiveKnowledge::class)->instructions();
    }

    public function tools(): iterable
    {
        return [];
    }
}
