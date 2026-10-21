<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Root-scoped, bounded finance reads; existing ledgers are the only source of balance. */
final readonly class BrowseCustomerWallets
{
    public function __construct(private AssertBillingAccess $access) {}

    /** @return list<array{id:int,name:string,balance_rials:int}> */
    public function page(int $rootId, int $page = 1): array
    {
        $this->access->reviewer($rootId);
        if ($page < 1 || $page > 10000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }

        return DB::table('billing_wallets as w')
            ->join('users as u', 'u.id', '=', 'w.user_id')
            ->orderByDesc('w.id')->offset(($page - 1) * 8)->limit(9)
            ->get(['w.id', 'w.user_id', 'u.name'])
            ->map(fn (object $item): array => [
                'id' => (int) $item->user_id,
                'name' => (string) $item->name,
                'balance_rials' => $this->balance((int) $item->id),
            ])->all();
    }

    /** @return array{id:int,name:string,active:bool,balance_rials:int,entries:list<array{journal:int,kind:string,side:string,amount_rials:int,at:string,source:string}>,topups:list<array{id:int,amount_rials:int,status:string,at:string,reference:?string}>} */
    public function detail(int $rootId, int $userId): array
    {
        $this->access->reviewer($rootId);
        if ($userId < 1) {
            throw ValidationException::withMessages(['wallet' => 'Invalid customer.']);
        }

        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'is_active']);
        if ($user === null) {
            throw ValidationException::withMessages(['wallet' => 'Customer not found.']);
        }

        $walletId = DB::table('billing_wallets')->where('user_id', $userId)->value('id');
        $entries = [];
        $topups = [];
        if ($walletId !== null) {
            $entries = DB::table('billing_postings as p')
                ->join('billing_journals as j', 'j.id', '=', 'p.journal_id')
                ->where('p.wallet_id', $walletId)->where('p.account_code', 'wallet_liability')
                ->orderByDesc('p.id')->limit(10)
                ->get(['j.id as journal_id', 'j.kind', 'j.idempotency_key', 'j.created_at', 'p.side', 'p.amount_rials'])
                ->map(static function (object $row): array {
                    $key = (string) $row->idempotency_key;
                    $source = preg_match('/\A(?:topup|wallet:order|order):[0-9]+\z/D', $key) === 1
                        ? $key : '';
                    return [
                        'journal' => (int) $row->journal_id,
                        'kind' => (string) $row->kind,
                        'side' => (string) $row->side,
                        'amount_rials' => (int) $row->amount_rials,
                        'at' => (string) $row->created_at,
                        'source' => $source,
                    ];
                })->all();

            $topups = DB::table('billing_topups')->where('wallet_id', $walletId)
                ->orderByDesc('id')->limit(8)
                ->get(['id', 'amount_rials', 'status', 'created_at', 'payment_reference'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'amount_rials' => (int) $row->amount_rials,
                    'status' => (string) $row->status,
                    'at' => (string) $row->created_at,
                    'reference' => $row->payment_reference === null ? null : (string) $row->payment_reference,
                ])->all();
        }

        return [
            'id' => (int) $user->id, 'name' => (string) $user->name,
            'active' => (bool) $user->is_active,
            'balance_rials' => $walletId === null ? 0 : $this->balance((int) $walletId),
            'entries' => $entries, 'topups' => $topups,
        ];
    }

    private function balance(int $walletId): int
    {
        $credits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->where('side', 'credit')->sum('amount_rials');
        $debits = (int) DB::table('billing_postings')
            ->where('wallet_id', $walletId)->where('account_code', 'wallet_liability')
            ->where('side', 'debit')->sum('amount_rials');

        return $credits - $debits;
    }
}
