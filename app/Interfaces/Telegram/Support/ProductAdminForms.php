<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use Illuminate\Validation\ValidationException;

final class ProductAdminForms
{
    /** @return array<string, int|string> */
    public function parse(string $operation, string $input): array
    {
        if (strlen($input) > 500 || preg_match('/[\r\n\x00-\x1f\x7f]/', $input) === 1) {
            throw $this->invalid();
        }

        $fields = array_map(trim(...), explode('|', $input));
        if ($operation === 'create' && count($fields) === 4) {
            return ['slug' => $fields[0], 'name' => $fields[1],
                'min_gb' => $this->number($fields[2]), 'max_gb' => $this->number($fields[3])];
        }

        if ($operation === 'edit' && count($fields) === 3) {
            return ['name' => $fields[0], 'min_gb' => $this->number($fields[1]),
                'max_gb' => $this->number($fields[2])];
        }

        if ($operation === 'rate' && count($fields) === 2) {
            return ['base_price_rials' => $this->rials($fields[0]),
                'per_gb_rials' => $this->rials($fields[1])];
        }

        throw $this->invalid();
    }

    private function number(string $value): int
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $value) !== 1) {
            throw $this->invalid();
        }

        return (int) $value;
    }

    private function rials(string $tomans): int
    {
        $n = $this->number($tomans);
        if ($n > 100_000_000_000) {
            throw $this->invalid();
        }

        return $n * 10;
    }

    private function invalid(): ValidationException
    {
        return ValidationException::withMessages(['form' => 'Use a single line of valid ASCII integer fields.']);
    }
}
