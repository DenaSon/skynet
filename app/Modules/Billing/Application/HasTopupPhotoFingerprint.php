<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Minimal Billing-owned read boundary for cross-purpose receipt reuse. */
final readonly class HasTopupPhotoFingerprint
{
    public function handle(string $fingerprint): bool
    {
        return Schema::hasTable('billing_topup_photos')
            && DB::table('billing_topup_photos')->where('photo_fingerprint', $fingerprint)->exists();
    }
}
