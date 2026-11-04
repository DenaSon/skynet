<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Root-scoped, bounded finance reads; existing ledgers are the only source of balance. */
final readonly class BrowseCustomerWallets
{
    public function __construct(private AssertBillingAccess $access) {}

    /** @return list<array{id:int,name:string,balance_rials:int,account_type:string}> */
    public function page(int $rootId, int $page = 1): array
    {
        $this->access->financeViewer($rootId);
        if ($page < 1 || $page > 10000) {
            throw ValidationException::withMessages(['page' => 'Invalid page.']);
        }

        // Sort the full directory BEFORE pagination; a funded older wallet must
        // appear on page one. Stable wallet-ID tie-breaker prevents duplicates.
        return DB::query()->fromSub(ReadAdminWalletSummary::walletBalancesQuery(), 'balances')
            ->orderByRaw('CASE WHEN balance_rials > 0 THEN 0 WHEN balance_rials = 0 THEN 1 ELSE 2 END')
            ->orderByDesc('balance_rials')
            ->orderByDesc('wallet_id')
            ->offset(($page - 1) * 8)->limit(9)
            ->get(['user_id', 'name', 'balance_rials', 'account_type'])
            ->map(static fn (object $item): array => [
                'id' => (int) $item->user_id,
                'name' => (string) $item->name,
                'balance_rials' => (int) $item->balance_rials,
                'account_type' => (string) $item->account_type,
            ])->all();
    }

    /** @return array{id:int,name:string,active:bool,account_type:string,balance_rials:int,entries:list<array{journal:int,kind:string,side:string,amount_rials:int,at:string,source:string}>,topups:list<array{id:int,amount_rials:int,status:string,at:string,reference:?string,origin:string,reason:?string,reviewer:?string,reversal_reason:?string,reverser:?string,reversed_at:?string,reversal_journal_id:?int}>} */
    public function detail(int $rootId, int $userId): array
    {
        $this->access->financeViewer($rootId);
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
        $accountType = null;
        if ($walletId !== null) {
            $accountType = ReadAdminWalletSummary::walletBalancesQuery()
                ->where('w.user_id', $userId)->first()?->account_type;
            $entries = DB::table('billing_postings as p')
                ->join('billing_journals as j', 'j.id', '=', 'p.journal_id')
                ->where('p.wallet_id', $walletId)->where('p.account_code', 'wallet_liability')
                ->orderByDesc('p.id')->limit(10)
                ->get(['j.id as journal_id', 'j.kind', 'j.idempotency_key', 'j.created_at', 'p.side', 'p.amount_rials'])
                ->map(static function (object $row): array {
                    $key = (string) $row->idempotency_key;
                    $source = preg_match('/\A(?:topup|topup_reversal|wallet:order|order):[0-9]+\z/D', $key) === 1
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

            $topups = DB::table('billing_topups as t')
                ->leftJoin('users as reviewer', 'reviewer.id', '=', 't.reviewed_by')
                ->leftJoin('users as reverser', 'reverser.id', '=', 't.reversed_by')
                ->where('t.wallet_id', $walletId)->orderByDesc('t.id')->limit(8)
                ->get(['t.id', 't.amount_rials', 't.status', 't.created_at', 't.payment_reference',
                    't.origin', 't.admin_reason', 'reviewer.name as reviewer_name',
                    't.reversal_reason', 't.reversed_at', 't.reversal_journal_id',
                    'reverser.name as reverser_name'])
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'amount_rials' => (int) $row->amount_rials,
                    'status' => (string) $row->status,
                    'at' => (string) $row->created_at,
                    'reference' => $row->payment_reference === null ? null : (string) $row->payment_reference,
                    'origin' => (string) $row->origin,
                    'reason' => $row->admin_reason === null ? null : (string) $row->admin_reason,
                    'reviewer' => $row->reviewer_name === null ? null : (string) $row->reviewer_name,
                    'reversal_reason' => $row->reversal_reason === null ? null : (string) $row->reversal_reason,
                    'reverser' => $row->reverser_name === null ? null : (string) $row->reverser_name,
                    'reversed_at' => $row->reversed_at === null ? null : (string) $row->reversed_at,
                    'reversal_journal_id' => $row->reversal_journal_id === null
                        ? null : (int) $row->reversal_journal_id,
                ])->all();
        }

        if ($accountType === null) {
            // Details are also available for users without a wallet yet.
            $roles = User::query()->find($userId);
            $accountType = $roles?->hasAnyRole(['admin', 'super-admin']) ? 'other'
                : ($roles?->hasRole('reseller') ? 'reseller'
                    : ($roles?->hasRole('member') ? 'member' : 'other'));
        }

        return [
            'id' => (int) $user->id, 'name' => (string) $user->name,
            'active' => (bool) $user->is_active, 'account_type' => $accountType,
            'balance_rials' => $walletId === null ? 0 : $this->balance((int) $walletId),
            'entries' => $entries, 'topups' => $topups,
        ];
    }

    /**
     * @return list<array{id:int,amount_rials:int,status:string,origin:string,at:string}>
     */
    public function correctionCandidates(int $rootId, int $userId, int $page): array
    {
        $this->access->reviewer($rootId);
        if ($userId < 1 || $page < 1 || $page > 1000) {
            throw ValidationException::withMessages(['wallet' => 'Invalid wallet or page.']);
        }

        $walletId = DB::table('billing_wallets')->where('user_id', $userId)->value('id');
        if ($walletId === null) {
            throw ValidationException::withMessages(['wallet' => 'Wallet does not exist.']);
        }

        // Include corrected rows for a useful audit trail; only approved
        // entries can proceed to the protected preview/confirmation.
        return DB::table('billing_topups')->where('wallet_id', $walletId)
            ->whereIn('status', ['approved', 'voided'])
            ->orderByDesc('id')->offset(($page - 1) * 8)->limit(9)
            ->get(['id', 'amount_rials', 'status', 'origin', 'created_at'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'amount_rials' => (int) $row->amount_rials,
                'status' => (string) $row->status,
                'origin' => (string) $row->origin,
                'at' => (string) $row->created_at,
            ])->all();
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
