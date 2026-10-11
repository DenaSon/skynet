<?php

namespace App\Modules\Sales\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfigureProduct
{
    public function handle(string $slug, string $name, int $minGb = 1, int $maxGb = 100, bool $active = true): void
    {
        $slug = trim($slug);
        $name = trim($name);

        if (preg_match('/^[a-z][a-z0-9-]{2,49}$/D', $slug) !== 1) {
            throw ValidationException::withMessages(['slug' => 'Product slug must be 3–50 lowercase ASCII letters, digits or hyphens.']);
        }

        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/u', $name) === 1) {
            throw ValidationException::withMessages(['name' => 'Product name must be 1–120 printable characters.']);
        }

        if ($minGb < 1 || $maxGb > 100 || $minGb > $maxGb) {
            throw ValidationException::withMessages(['traffic' => 'Volume limits must be between 1 and 100 GB.']);
        }

        DB::table('sales_products')->updateOrInsert(
            ['slug' => $slug],
            [
                'name' => $name,
                'min_gb' => $minGb,
                'max_gb' => $maxGb,
                'is_active' => $active,
                'updated_at' => now(),
            ],
        );
    }
}
