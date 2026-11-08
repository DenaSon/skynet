<?php

declare(strict_types=1);

use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('retains the historic amount correction in ledger and net cash reports without a replay command', function (): void {
    config()->set('super_admin.telegram_ids', ['969001', '969002']);
    config()->set('billing.topups_enabled', true);
    Role::findOrCreate('reseller', 'web');

    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 969001]);
    $customer = User::factory()->create();
    $customer->syncRoles(['reseller']);

    $topup = app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $customer->id, 90_000_000, 'HISTORICALBANK56', 'Historic verified credit',
    );
    $wallet = (int) DB::table('billing_wallets')->where('user_id', $customer->id)->value('id');
    $purchase = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $wallet, 'kind' => 'purchase', 'idempotency_key' => 'historic:purchase:1',
        'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $purchase, 'wallet_id' => $wallet,
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 3_575_000],
        ['journal_id' => $purchase, 'wallet_id' => null,
            'account_code' => 'order_liability', 'side' => 'credit', 'amount_rials' => 3_575_000],
    ]);
    $correctionJournal = DB::table('billing_journals')->insertGetId([
        'wallet_id' => $wallet, 'kind' => 'topup_amount_correction',
        'idempotency_key' => 'topup_amount_correction:'.$topup, 'created_at' => now(),
    ]);
    DB::table('billing_postings')->insert([
        ['journal_id' => $correctionJournal, 'wallet_id' => $wallet,
            'account_code' => 'wallet_liability', 'side' => 'debit', 'amount_rials' => 81_000_000],
        ['journal_id' => $correctionJournal, 'wallet_id' => null,
            'account_code' => 'cash_clearing', 'side' => 'credit', 'amount_rials' => 81_000_000],
    ]);
    DB::table('billing_topup_amount_corrections')->insert([
        'topup_id' => $topup, 'billing_journal_id' => $correctionJournal,
        'verified_amount_rials' => 9_000_000, 'adjustment_amount_rials' => 81_000_000,
        'corrected_by' => $root->id, 'reason' => 'Verified historic correction',
        'created_at' => now(),
    ]);

    expect(DB::table('billing_topups')->where('id', $topup)->value('status'))->toBe('approved')
        ->and((int) DB::table('billing_topups')->where('id', $topup)->value('amount_rials'))->toBe(90_000_000)
        ->and((int) app(ViewWallet::class)->handle($customer->id)->balanceRials)->toBe(5_425_000);
    foreach (['7', '30', 'all'] as $period) {
        $report = app(ReadAdminCashReceipts::class)->handle($root->id, $period);
        expect($report['topupsRials'])->toBe(9_000_000)
            ->and($report['cashInRials'])->toBe(9_000_000)
            ->and($report['currentWalletBalanceRials'])->toBe(5_425_000);
    }
});
