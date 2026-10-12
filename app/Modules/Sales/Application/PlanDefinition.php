<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Illuminate\Validation\ValidationException;

/**
 * Pricing/provider-agnostic catalog specification. For a builder, the buyer
 * selects values within bounds in a later increment; presets have exact values.
 * Null traffic is NOT provider zero: this is a Sales-side unlimited marker.
 */
final readonly class PlanDefinition
{
    public function __construct(
        public string $productSlug,
        public string $slug,
        public string $name,
        public string $kind,
        public string $mode,
        public string $audience,
        public ?int $trafficGb,
        public ?int $durationDays,
        public ?int $minGb,
        public ?int $maxGb,
        public int $minDays,
        public int $maxDays,
        public int $sortOrder = 100,
        public bool $featured = false,
    ) {}

    public function validated(): self
    {
        if (preg_match('/\A[a-z][a-z0-9-]{2,49}\z/D', $this->slug) !== 1
            || preg_match('/\A[a-z][a-z0-9-]{2,49}\z/D', $this->productSlug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'Invalid catalog slug.']);
        }

        if ($this->name !== trim($this->name) || $this->name === ''
            || mb_strlen($this->name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $this->name) === 1) {
            throw ValidationException::withMessages(['name' => 'Use a printable name of at most 120 characters.']);
        }

        if (! in_array($this->kind, ['volume', 'time', 'hybrid'], true)
            || ! in_array($this->mode, ['preset', 'builder'], true)
            || ! in_array($this->audience, ['public', 'reseller', 'both'], true)) {
            throw ValidationException::withMessages(['plan' => 'Invalid plan kind, mode or audience.']);
        }

        if ($this->minDays < 1 || $this->maxDays > 365 || $this->minDays > $this->maxDays
            || $this->sortOrder < 0 || $this->sortOrder > 10_000) {
            throw ValidationException::withMessages(['duration' => 'Invalid duration or display bounds.']);
        }

        if ($this->mode === 'preset') {
            if ($this->durationDays === null || $this->durationDays < $this->minDays
                || $this->durationDays > $this->maxDays) {
                throw ValidationException::withMessages(['duration' => 'Preset duration outside allowed bounds.']);
            }
        } elseif ($this->durationDays !== null || $this->audience !== 'reseller') {
            // Only resellers may access the flexible builder in B1.
            throw ValidationException::withMessages(['plan' => 'Builder must be reseller-only with no fixed duration.']);
        }

        if ($this->kind === 'time') {
            if ($this->trafficGb !== null || $this->minGb !== null || $this->maxGb !== null) {
                throw ValidationException::withMessages(['traffic' => 'Time-based plans are unlimited by volume.']);
            }
        } else {
            if ($this->minGb === null || $this->maxGb === null
                || $this->minGb < 1 || $this->maxGb > 2048 || $this->minGb > $this->maxGb) {
                throw ValidationException::withMessages(['traffic' => 'Invalid finite traffic bounds.']);
            }

            if ($this->mode === 'preset') {
                if ($this->trafficGb === null || $this->trafficGb < $this->minGb
                    || $this->trafficGb > $this->maxGb) {
                    throw ValidationException::withMessages(['traffic' => 'Preset volume outside allowed bounds.']);
                }
            } elseif ($this->trafficGb !== null) {
                throw ValidationException::withMessages(['traffic' => 'Builder must not have fixed volume.']);
            }
        }

        return $this;
    }
}
