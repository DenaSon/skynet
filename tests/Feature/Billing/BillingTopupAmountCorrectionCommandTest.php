<?php

declare(strict_types=1);

use App\Modules\Billing\Application\CorrectVerifiedTopupAmount;
use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ReverseUnusedWalletTopup;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['969001', '969002']);
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('reseller', 'web');
    Role::findOrCreate('member', 'web');
});

function amountCorrectionRoot(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 969001]);

    return $root;
}

function amountCorrectionFund(User $root, User $customer, int $amount = 90_000_000): int
{
    return app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $customer->id, $amount, 'BANK900K01', 'Operator-confirmed bank deposit',
    );
}

function amountCorrectionPurchase(int $walletId, int $amount, string $key): void
{
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'wallet_id' => $walletId, 'kind' => 'purchase',
        'idempotency_key' => $key, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $journal, 'wallet_id' => $walletId,
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => $amount],
        ['journal_id' => $journal, 'wallet_id' => null,
            'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => $amount],
    ]);
}

it('corrects only the bank overstatement after purchases, preserving the original receipt and orders', function (): void {
    $root = amountCorrectionRoot();
    $customer = User::factory()->create();
    $customer->syncRoles(['reseller']);
    $topupId = amountCorrectionFund($root, $customer);
    $walletId = (int) DB::table('billing_wallets')->where('user_id', $customer->id)->value('id');
    amountCorrectionPurchase($walletId, 1_787_500, 'purchase:order:344');
    amountCorrectionPurchase($walletId, 1_787_500, 'purchase:order:346');

    $action = app(CorrectVerifiedTopupAmount::class);
    $preview = $action->preview($root->id, $customer->id, $topupId, 9_000_000);
    expect($preview['original_rials'])->toBe(90_000_000)
        ->and($preview['verified_rials'])->toBe(9_000_000)
        ->and($preview['adjustment_rials'])->toBe(81_000_000)
        ->and($preview['balance_rials'])->toBe(86_425_000)
        ->and(DB::table('billing_topup_amount_corrections')->count())->toBe(0);

    // Existing full reversal must remain blocked because the wallet was spent.
    expect(app(ReverseUnusedWalletTopup::class)->preview($root->id, $customer->id, $topupId)['eligible'])->toBeFalse();

    $journal = $action->correct($root->id, $customer->id, $topupId, 9_000_000, 86_425_000, 'Verified 900000 toman in bank, not 9000000.');

    $audit = DB::table('billing_topup_amount_corrections')->where('topup_id', $topupId)->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->corrected_by)->toBe($root->id)
        ->and((int) $audit->billing_journal_id)->toBe($journal)
        ->and((int) $audit->verified_amount_rials)->toBe(9_000_000)
        ->and((int) $audit->adjustment_amount_rials)->toBe(81_000_000)
        ->and($audit->reason)->toContain('Verified')
        ->and(DB::table('billing_journals')->where('id', $journal)->value('kind'))->toBe('topup_amount_correction')
        ->and((int) DB::table('billing_postings')->where('journal_id', $journal)
            ->where('account_code', 'wallet_liability')->where('side', 'debit')->sum('amount_rials'))->toBe(81_000_000)
        ->and((int) DB::table('billing_postings')->where('journal_id', $journal)
            ->where('account_code', 'cash_clearing')->where('side', 'credit')->sum('amount_rials'))->toBe(81_000_000)
        ->and((int) DB::table('billing_topups')->where('id', $topupId)->value('amount_rials'))->toBe(90_000_000)
        ->and(DB::table('billing_topups')->where('id', $topupId)->value('status'))->toBe('approved')
        ->and(DB::table('billing_verified_receipts')->where('topup_id', $topupId)->count())->toBe(1)
        ->and(DB::table('billing_bank_reference_claims')->where('source_id', $topupId)->count())->toBe(1)
        ->and((int) app(ViewWallet::class)->handle($customer->id)->balanceRials)->toBe(5_425_000);

    foreach (['7', '30', 'all'] as $period) {
        $cash = app(ReadAdminCashReceipts::class)->handle($root->id, $period);
        expect($cash['topupsRials'])->toBe(9_000_000)
            ->and($cash['topupsCount'])->toBe(1)
            ->and($cash['cashInRials'])->toBe(9_000_000)
            ->and($cash['currentWalletBalanceRials'])->toBe(5_425_000);
    }
});

it('rejects replay, wrong proof, stale balance and unauthorized correction without partial writes', function (): void {
    $root = amountCorrectionRoot();
    $customer = User::factory()->create();
    $customer->syncRoles(['reseller']);
    $topupId = amountCorrectionFund($root, $customer);
    $action = app(CorrectVerifiedTopupAmount::class);

    expect(fn () => $action->correct($root->id, $customer->id, $topupId, 9_000_000, 1, 'Stale'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_topup_amount_corrections')->count())->toBe(0);
    expect(DB::table('billing_journals')->count())->toBe(1);

    $journal = $action->correct($root->id, $customer->id, $topupId, 9_000_000, 90_000_000, 'One correction');
    expect($journal)->toBeGreaterThan(0);
    expect(fn () => $action->correct($root->id, $customer->id, $topupId, 9_000_000, 9_000_000, 'Replay'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_journals')->count())->toBe(2);

    $another = User::factory()->create();
    $another->syncRoles(['member']);
    expect(fn () => $action->preview($root->id, $another->id, $topupId, 9_000_000))
        ->toThrow(ValidationException::class);
});

it('exposes only dry-run by default and requires an exact incident-specific apply confirmation', function (): void {
    $root = amountCorrectionRoot();
    $customer = User::factory()->create(['id' => 494]);
    $customer->syncRoles(['reseller']);
    DB::table('billing_wallets')->insert([
        'id' => 2393, 'user_id' => $customer->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    for ($id = 1; $id <= 55; $id++) {
        DB::table('billing_topups')->insert([
            'id' => $id, 'wallet_id' => 2393, 'request_key' => 'fixture:'.$id,
            'amount_rials' => 100_000, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $topupId = amountCorrectionFund($root, $customer);
    expect($topupId)->toBe(56);
    amountCorrectionPurchase(2393, 1_787_500, 'purchase:order:344');
    amountCorrectionPurchase(2393, 1_787_500, 'purchase:order:346');

    $params = [
        '--operator-id' => (string) $root->id,
        '--bank-amount-toman' => '900000',
    ];
    expect(Artisan::call('finance:correct-topup-56', $params))->toBe(0)
        ->and(DB::table('billing_topup_amount_corrections')->count())->toBe(0);
    expect(Artisan::call('finance:correct-topup-56', $params + ['--apply' => true]))->toBe(1)
        ->and(DB::table('billing_topup_amount_corrections')->count())->toBe(0);
    expect(Artisan::call('finance:correct-topup-56', [
        '--operator-id' => (string) $root->id,
        '--bank-amount-toman' => '9000000',
        '--apply' => true,
        '--confirm' => 'CORRECT-56-8100000',
        '--reason' => 'Invalid amount',
    ]))->toBe(1);
    expect(Artisan::call('finance:correct-topup-56', $params + [
        '--apply' => true, '--confirm' => 'CORRECT-56-8100000', '--reason' => 'Bank confirmed 900000 toman',
    ]))->toBe(0);
    expect(DB::table('billing_topup_amount_corrections')->count())->toBe(1)
        ->and((int) app(ViewWallet::class)->handle($customer->id)->balanceRials)->toBe(5_425_000);
    expect(Artisan::call('finance:correct-topup-56', $params + [
        '--apply' => true, '--confirm' => 'CORRECT-56-8100000', '--reason' => 'Replay',
    ]))->toBe(1);
});
