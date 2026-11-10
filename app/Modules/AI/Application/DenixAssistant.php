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

    public function __construct(
        private readonly bool $customerSupport = false,
        private readonly bool $executive = false,
    ) {}

    public function instructions(): string
    {
        return $this->executive
            ? app(DenizExecutiveKnowledge::class)->instructions()
            : app(DenizKnowledge::class)->instructions($this->customerSupport);
    }

    public function tools(): iterable
    {
        return [];
    }
}
