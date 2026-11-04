<?php

declare(strict_types=1);

use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReverseUnusedWalletTopup;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['964001', '964002']);
    config()->set('billing.topups_enabled', true);
});

function topupCorrectionRoot(): User
{
    $user = User::factory()->create(['name' => 'Trusted Finance Admin']);
    $user->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 964001]);

    return $user;
}

function topupCorrectionFund(User $root, User $buyer, int $rials, string $reference): int
{
    return app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $buyer->id, $rials, $reference, 'Verified source deposit',
    );
}

/** Simulate an actual posted wallet-funded purchase, without any Provider writes. */
function topupCorrectionSpend(int $buyerId, int $rials, string $key): void
{
    $walletId = (int) DB::table('billing_wallets')->where('user_id', $buyerId)->value('id');
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'purchase',
        'idempotency_key' => $key, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $walletId,
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => $rials],
        ['journal_id' => $journal, 'wallet_id' => null,
            'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => $rials],
    ]);
}

it('records one balanced reversal of an unused verified deposit and preserves original evidence', function (): void {
    $root = topupCorrectionRoot();
    $buyer = User::factory()->create(['name' => 'Buyer']);
    $topup = topupCorrectionFund($root, $buyer, 275_000, 'CORRECT-27500');
    $reversal = app(ReverseUnusedWalletTopup::class);
    $preview = $reversal->preview($root->id, $buyer->id, $topup);

    expect($preview['eligible'])->toBeTrue()
        ->and($preview['balance_rials'])->toBe(275_000)
        ->and($preview['amount_rials'])->toBe(275_000)
        ->and($preview['block_reason'])->toBeNull();

    $journal = $reversal->handle($root->id, $buyer->id, $topup, $preview['balance_rials'], 'تأیید اشتباه رسید');

    expect(DB::table('billing_topups')->where('id', $topup)->value('status'))->toBe('voided')
        ->and(DB::table('billing_topups')->where('id', $topup)->value('reversal_reason'))->toBe('تأیید اشتباه رسید')
        ->and((int) DB::table('billing_topups')->where('id', $topup)->value('reversed_by'))->toBe($root->id)
        ->and((int) DB::table('billing_topups')->where('id', $topup)->value('reversal_journal_id'))->toBe($journal)
        ->and(DB::table('billing_topups')->where('id', $topup)->value('reversed_at'))->not->toBeNull()
        ->and(DB::table('billing_journals')->count())->toBe(2)
        ->and(DB::table('billing_journals')->where('id', $journal)->value('kind'))->toBe('topup_reversal')
        ->and(DB::table('billing_journals')->where('id', $journal)->value('idempotency_key'))->toBe('topup_reversal:'.$topup)
        ->and(DB::table('billing_postings')->count())->toBe(4)
        ->and((int) DB::table('billing_postings')->where('journal_id', $journal)->where('account_code', 'cash_clearing')
            ->where('side', 'credit')->sum('amount_rials'))->toBe(275_000)
        ->and((int) DB::table('billing_postings')->where('journal_id', $journal)->where('account_code', 'wallet_liability')
            ->where('side', 'debit')->sum('amount_rials'))->toBe(275_000)
        ->and(DB::table('billing_verified_receipts')->where('topup_id', $topup)->count())->toBe(1)
        ->and(DB::table('billing_bank_reference_claims')->where('source_id', $topup)->where('source_type', 'wallet_topup')->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(0)
        ->and(DB::table('sales_orders')->count())->toBe(0);

    foreach (['7', '30', 'all'] as $period) {
        $report = app(ReadAdminCashReceipts::class)->handle($root->id, $period);
        expect($report['topupsRials'])->toBe(0)
            ->and($report['adminTopupsRials'])->toBe(0)
            ->and($report['cashInRials'])->toBe(0)
            ->and($report['salesRials'])->toBe(0);
    }
    expect(fn () => $reversal->handle($root->id, $buyer->id, $topup, 0, 'تکرار'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_journals')->count())->toBe(2);
});

it('blocks spent topups even when a later deposit replenishes the entire balance', function (): void {
    $root = topupCorrectionRoot();
    $buyer = User::factory()->create();
    $first = topupCorrectionFund($root, $buyer, 1_000_000, 'INVALID-PAID-1');
    topupCorrectionSpend($buyer->id, 700_000, 'test:purchase:one');
    topupCorrectionFund($root, $buyer, 1_000_000, 'NEW-DEPOSIT-2');
    $before = DB::table('billing_postings')->count();
    $reversal = app(ReverseUnusedWalletTopup::class);

    $preview = $reversal->preview($root->id, $buyer->id, $first);
    expect($preview['balance_rials'])->toBe(1_300_000)
        ->and($preview['eligible'])->toBeFalse()
        ->and($preview['block_reason'])->toContain('مصرف');

    expect(fn () => $reversal->handle($root->id, $buyer->id, $first, 1_300_000, 'رسید نامعتبر'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_postings')->count())->toBe($before)
        ->and(DB::table('billing_topups')->where('id', $first)->value('status'))->toBe('approved')
        ->and(DB::table('billing_journals')->where('kind', 'topup_reversal')->count())->toBe(0);
});

it('fails closed on changed balance, missing proof, foreign topup and role revocation', function (): void {
    $root = topupCorrectionRoot();
    $buyer = User::factory()->create();
    $stranger = User::factory()->create();
    $topup = topupCorrectionFund($root, $buyer, 275_000, 'STALE-275000');
    $reversal = app(ReverseUnusedWalletTopup::class);
    $initial = $reversal->preview($root->id, $buyer->id, $topup);

    topupCorrectionFund($root, $buyer, 10_000, 'EXTRA-10000');
    expect(fn () => $reversal->handle($root->id, $buyer->id, $topup, $initial['balance_rials'], 'مانده تغییر کرده'))
        ->toThrow(ValidationException::class);
    expect(fn () => $reversal->preview($root->id, $stranger->id, $topup))
        ->toThrow(ValidationException::class);

    DB::table('billing_verified_receipts')->where('topup_id', $topup)->delete();
    expect($reversal->preview($root->id, $buyer->id, $topup)['eligible'])->toBeFalse();
    expect(fn () => $reversal->handle($root->id, $buyer->id, $topup, 285_000, 'فاقد سند'))
        ->toThrow(ValidationException::class);

    $root->syncRoles(['admin']);
    expect(fn () => $reversal->preview($root->id, $buyer->id, $topup))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('billing_journals')->where('kind', 'topup_reversal')->count())->toBe(0);
});

it('rejects inactive or privileged customers and empty reversal reasons', function (): void {
    $root = topupCorrectionRoot();
    $buyer = User::factory()->create();
    $topup = topupCorrectionFund($root, $buyer, 275_000, 'ACCEPTED-27500');
    $reversal = app(ReverseUnusedWalletTopup::class);

    expect(fn () => $reversal->handle($root->id, $buyer->id, $topup, 275_000, ''))
        ->toThrow(ValidationException::class);

    $buyer->forceFill(['is_active' => false])->save();
    expect(fn () => $reversal->preview($root->id, $buyer->id, $topup))
        ->toThrow(AuthorizationException::class);
    $buyer->forceFill(['is_active' => true])->save();
    $buyer->syncRoles(['member', 'admin']);
    expect(fn () => $reversal->preview($root->id, $buyer->id, $topup))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('billing_journals')->where('kind', 'topup_reversal')->count())->toBe(0);
});
