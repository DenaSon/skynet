<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application;

use App\Modules\Identity\Application\AssertBillingAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ACC-01. Personal business-book entries only: never touch customer wallets,
 * payment settlements, bank records, or external funds.
 */
final readonly class RecordBusinessEntry
{
    public function __construct(private AssertBillingAccess $access) {}

    public function record(
        int $actorId,
        string $kind,
        string $category,
        int $amountRials,
        ?string $memo,
        string $idempotencyKey,
    ): int {
        $this->access->reviewer($actorId);
        if (! in_array($kind, ['expense', 'owner_draw'], true)
            || ! in_array($category, $kind === 'expense'
                ? ['server', 'domain', 'fee', 'other'] : ['owner'], true)
            || $amountRials < 100_000 || $amountRials > 1_000_000_000_000
            || ! preg_match('/\A[a-f0-9]{24,64}\z/D', $idempotencyKey)
            || ($memo !== null && (mb_strlen($memo) > 200 || trim($memo) === ''))) {
            throw ValidationException::withMessages(['accounting' => 'Invalid entry fields.']);
        }

        $fields = [
            'kind' => $kind, 'category' => $category, 'amount_rials' => $amountRials,
            'memo' => $memo, 'created_by' => $actorId,
        ];

        // Atomic, unique retry key. A repeated callback cannot add a second entry.
        DB::table('accounting_entries')->insertOrIgnore($fields + [
            'idempotency_key' => $idempotencyKey, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $entry = DB::table('accounting_entries')
            ->where('idempotency_key', $idempotencyKey)->first();
        if ($entry === null || $entry->reversal_of_id !== null
            || $entry->created_by !== $actorId
            || $entry->kind !== $kind || $entry->category !== $category
            || (int) $entry->amount_rials !== $amountRials || $entry->memo !== $memo) {
            throw ValidationException::withMessages(['accounting' => 'Idempotency key conflict.']);
        }

        return (int) $entry->id;
    }

    public function reverse(int $actorId, int $entryId, string $idempotencyKey): int
    {
        $this->access->reviewer($actorId);
        if ($entryId < 1 || ! preg_match('/\A[a-f0-9]{24,64}\z/D', $idempotencyKey)) {
            throw ValidationException::withMessages(['accounting' => 'Invalid correction.']);
        }

        return DB::transaction(function () use ($actorId, $entryId, $idempotencyKey): int {
            $original = DB::table('accounting_entries')
                ->where('id', $entryId)->lockForUpdate()->first();
            if ($original === null || $original->reversal_of_id !== null) {
                throw ValidationException::withMessages(['accounting' => 'Entry cannot be reversed.']);
            }

            $existing = DB::table('accounting_entries')
                ->where('reversal_of_id', $entryId)->first();
            if ($existing !== null) {
                if ($existing->idempotency_key !== $idempotencyKey) {
                    throw ValidationException::withMessages(['accounting' => 'Entry already corrected.']);
                }

                return (int) $existing->id;
            }

            DB::table('accounting_entries')->insert([
                'kind' => $original->kind,
                'category' => $original->category,
                'amount_rials' => $original->amount_rials,
                'memo' => 'اصلاح ثبت شماره '.$entryId,
                'created_by' => $actorId,
                'idempotency_key' => $idempotencyKey,
                'reversal_of_id' => $entryId,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return (int) DB::getPdo()->lastInsertId();
        }, 3);
    }

    /** @return array{entries:\Illuminate\Support\Collection<int,\stdClass>,total:int,page:int} */
    public function history(int $actorId, int $page = 1): array
    {
        $this->access->reviewer($actorId);
        $page = max(1, min(1000, $page));
        $query = DB::table('accounting_entries');

        return [
            'entries' => (clone $query)->orderByDesc('id')->offset(($page - 1) * 8)->limit(8)->get(),
            'total' => (clone $query)->count(),
            'page' => $page,
        ];
    }
}
