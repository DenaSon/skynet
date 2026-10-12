<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Sales\Application\PlanDefinition;
use App\Modules\Sales\Application\PlanPricingDefinition;
use App\Modules\Sales\Application\PlanSummary;
use App\Modules\Sales\Application\PlanTariffSummary;
use Illuminate\Validation\ValidationException;

final class PlanAdminForms
{
    public function plan(string $type, string $input): PlanDefinition
    {
        $fields = $this->fields($input);
        $form = match ($type) {
            'preset' => $this->preset($fields),
            'builder' => $this->builder($fields),
            'edit' => $this->edit($fields),
            default => throw ValidationException::withMessages(['form' => 'Invalid form.']),
        };

        return $form->validated();
    }

    public function price(string $type, string $input, PlanSummary $plan): PlanPricingDefinition
    {
        $fields = $this->fields($input);
        if ($type === 'fixed' && count($fields) === 3) {
            $value = new PlanPricingDefinition(
                'fixed', $this->rials($fields[0]), 0, 0, 0,
                $this->number($fields[2]), $this->rials($fields[1]),
            );
        } elseif ($type === 'formula' && count($fields) === 5) {
            $value = new PlanPricingDefinition(
                'formula', null, $this->rials($fields[0]),
                $this->rials($fields[1]), $this->rials($fields[2]),
                $this->number($fields[4]), $this->rials($fields[3]),
            );
        } else {
            throw ValidationException::withMessages(['form' => 'Invalid number of tariff fields.']);
        }

        return $value->validatedFor($plan);
    }

    public function editLine(PlanSummary $plan): string
    {
        return implode(' | ', [
            $plan->productSlug, $plan->slug, $plan->name, $plan->kind,
            $plan->mode, $plan->audience, $this->optional($plan->trafficGb),
            $this->optional($plan->durationDays), $this->optional($plan->minGb),
            $this->optional($plan->maxGb), $plan->minDays, $plan->maxDays,
            $plan->sortOrder, $plan->featured ? 1 : 0,
        ]);
    }

    public function tariffLine(PlanTariffSummary $price): string
    {
        $tomans = static fn (int $rials): string => (string) intdiv($rials, 10);

        return $price->method === 'fixed'
            ? implode(' | ', [
                $tomans((int) $price->fixedPriceRials),
                $tomans($price->minimumTotalRials),
                $price->resellerDiscountPercent,
            ])
            : implode(' | ', [
                $tomans($price->basePriceRials),
                $tomans($price->perGbRials),
                $tomans($price->perDayRials),
                $tomans($price->minimumTotalRials),
                $price->resellerDiscountPercent,
            ]);
    }

    /**
     * @param  list<string>  $f
     */
    private function preset(array $f): PlanDefinition
    {
        if (count($f) !== 7) {
            throw ValidationException::withMessages(['form' => 'Preset requires seven fields.']);
        }

        $gb = $this->nullableNumber($f[5]);
        $days = $this->number($f[6]);

        return new PlanDefinition(
            $f[0], $f[1], $f[2], $f[3], 'preset', $f[4],
            $gb, $days, $gb, $gb, $days, $days,
        );
    }

    /**
     * @param  list<string>  $f
     */
    private function builder(array $f): PlanDefinition
    {
        if (count($f) !== 8) {
            throw ValidationException::withMessages(['form' => 'Builder requires eight fields.']);
        }

        return new PlanDefinition(
            $f[0], $f[1], $f[2], $f[3], 'builder', 'reseller',
            null, null, $this->nullableNumber($f[4]), $this->nullableNumber($f[5]),
            $this->number($f[6]), $this->number($f[7]),
        );
    }

    /**
     * @param  list<string>  $f
     */
    private function edit(array $f): PlanDefinition
    {
        if (count($f) !== 14) {
            throw ValidationException::withMessages(['form' => 'Edit requires fourteen fields.']);
        }

        $featured = $f[13];
        if (! in_array($featured, ['0', '1'], true)) {
            throw ValidationException::withMessages(['form' => 'Featured must be 0 or 1.']);
        }

        return new PlanDefinition(
            $f[0], $f[1], $f[2], $f[3], $f[4], $f[5],
            $this->nullableNumber($f[6]), $this->nullableNumber($f[7]),
            $this->nullableNumber($f[8]), $this->nullableNumber($f[9]),
            $this->number($f[10]), $this->number($f[11]),
            $this->number($f[12]), $featured === '1',
        );
    }

    /** @return list<string> */
    private function fields(string $input): array
    {
        if (strlen($input) > 750 || preg_match('/[\r\n\x00-\x08\x0b-\x1f\x7f]/', $input) === 1) {
            throw ValidationException::withMessages(['form' => 'Input is too long or multiline.']);
        }

        return array_map(trim(...), explode('|', $input));
    }

    private function number(string $value): int
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $value) !== 1) {
            throw ValidationException::withMessages(['form' => 'Use nonnegative ASCII integers.']);
        }

        return (int) $value;
    }

    private function nullableNumber(string $value): ?int
    {
        return $value === '-' ? null : $this->number($value);
    }

    private function rials(string $tomans): int
    {
        $value = $this->number($tomans);

        if ($value > 100_000_000_000) {
            throw ValidationException::withMessages(['form' => 'Toman amount exceeds the maximum.']);
        }

        return $value * 10;
    }

    private function optional(?int $value): string
    {
        return $value === null ? '-' : (string) $value;
    }
}
