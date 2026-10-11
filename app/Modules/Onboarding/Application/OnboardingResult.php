<?php

namespace App\Modules\Onboarding\Application;

final readonly class OnboardingResult
{
    public function __construct(
        public int $userId,
        public bool $created,
        public bool $referred,
    ) {}
}
