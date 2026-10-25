<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Notifications;

use Illuminate\Support\Facades\DB;

/** Read-only snapshot of the two existing finance review queues. */
final class PendingReceiptSummary
{
    /** @return array{purchases:int,topups:int}|null */
    public function overdue(bool $purchases = true, bool $topups = true): ?array
    {
        $cutoff = now()->subMinutes(15);

        $claims = DB::table('sales_bank_payment_claims')->where('status', 'submitted');
        $topups = DB::table('billing_topups as topups')
            ->join('billing_topup_photos as photos', 'photos.topup_id', '=', 'topups.id')
            ->where('topups.status', 'submitted')->whereNull('topups.payment_reference');

        if (! ($purchases && (clone $claims)->where('created_at', '<=', $cutoff)->exists())
            && ! ($topups && (clone $topups)->where('photos.created_at', '<=', $cutoff)->exists())) {
            return null;
        }

        return [
            'purchases' => $purchases ? (clone $claims)->count() : 0,
            'topups' => $topups ? (clone $topups)->count() : 0,
        ];
    }
}
