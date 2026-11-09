<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;

#[MaxTokens(700)]
final class DenixAssistant implements Agent, HasTools
{
    use Promptable;

    public function instructions(): string
    {
        return app(DenizKnowledge::class)->instructions();
    }

    public function tools(): iterable
    {
        return [];
    }
}
