<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Only authorized active Admin operators can see submitted photo evidence. */
final readonly class BrowsePhotoTopups
{
    public function __construct(private AssertBillingAccess $access) {}

    /** @return list<array{id:int,user_id:int,name:string,amount_rials:int}> */
    public function pending(int $rootId, int $page = 1): array
    {
        $this->access->topupViewer($rootId);
        if ($page < 1 || $page > 10000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }
        if (! Schema::hasTable('billing_topup_photos')) {
            return [];
        }

        return $this->query()->orderByDesc('t.id')->offset(($page - 1) * 8)->limit(9)
            ->get(['t.id', 'w.user_id', 'u.name', 't.amount_rials'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'name' => (string) $row->name,
                'amount_rials' => (int) $row->amount_rials,
            ])->all();
    }

    /** @return array{id:int,user_id:int,name:string,amount_rials:int,file_id:string,source_bot:string} */
    public function photo(int $rootId, int $topupId): array
    {
        $this->access->topupViewer($rootId);
        if ($topupId < 1 || ! Schema::hasTable('billing_topup_photos')) {
            throw ValidationException::withMessages(['topup' => 'Photo unavailable.']);
        }

        $row = $this->query()->where('t.id', $topupId)
            ->first(['t.id', 'w.user_id', 'u.name', 't.amount_rials', 'f.file_encrypted', 'f.source_bot_key']);
        if ($row === null || $row->source_bot_key !== 'sales') {
            throw ValidationException::withMessages(['topup' => 'Photo unavailable.']);
        }

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'amount_rials' => (int) $row->amount_rials,
            'file_id' => Crypt::decryptString((string) $row->file_encrypted),
            'source_bot' => 'sales',
        ];
    }

    private function query(): Builder
    {
        return DB::table('billing_topups as t')
            ->join('billing_wallets as w', 'w.id', '=', 't.wallet_id')
            ->join('billing_topup_photos as f', 'f.topup_id', '=', 't.id')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->where('t.status', 'submitted')->whereNull('t.payment_reference');
    }
}
