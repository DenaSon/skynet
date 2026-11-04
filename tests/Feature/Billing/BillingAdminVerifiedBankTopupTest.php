<?php

declare(strict_types=1);

use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\ReadAdminCashReceipts;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('super_admin.telegram_ids', ['958111']);
    config()->set('billing.topups_enabled', true);
});

function verifiedCreditRoot(): User
{
    $root = User::factory()->create(['name' => 'Finance Root']);
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 958111]);

    return $root;
}

it('posts one audited balanced admin bank credit with no order settlement or provider work', function (): void {
    $root = verifiedCreditRoot();
    $buyer = User::factory()->create(['name' => 'Wallet Buyer']);

    $topupId = app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $buyer->id, 275_000, 'bank-extra-275', 'واریز اضافه سفارش',
    );
    $topup = DB::table('billing_topups')->where('id', $topupId)->first();
    $journal = DB::table('billing_journals')->first();
    $postings = DB::table('billing_postings')->orderBy('id')->get();

    expect($topup->origin)->toBe('admin_verified_bank')
        ->and($topup->admin_reason)->toBe('واریز اضافه سفارش')
        ->and($topup->payment_reference)->toBe('BANK-EXTRA-275')
        ->and($topup->status)->toBe('approved')
        ->and((int) $topup->reviewed_by)->toBe($root->id)
        ->and($topup->reviewed_at)->not->toBeNull()
        ->and($journal->kind)->toBe('admin_verified_topup')
        ->and($journal->idempotency_key)->toBe('topup:'.$topupId)
        ->and($postings)->toHaveCount(2)
        ->and($postings->sum('amount_rials'))->toBe(550_000)
        ->and($postings->where('side', 'debit')->sum('amount_rials'))->toBe(275_000)
        ->and($postings->where('side', 'credit')->sum('amount_rials'))->toBe(275_000)
        ->and(DB::table('billing_bank_reference_claims')->where('payment_reference', 'BANK-EXTRA-275')->count())->toBe(1)
        ->and(DB::table('billing_verified_receipts')->where('topup_id', $topupId)->count())->toBe(1)
        ->and(app(ViewWallet::class)->handle($buyer->id)->balanceRials)->toBe(275_000)
        ->and(DB::table('sales_orders')->count())->toBe(0)
        ->and(DB::table('sales_fulfillment_outbox')->count())->toBe(0);

    $wallet = app(BrowseCustomerWallets::class)->detail($root->id, $buyer->id);
    expect($wallet['entries'][0]['kind'])->toBe('admin_verified_topup')
        ->and($wallet['topups'][0]['origin'])->toBe('admin_verified_bank')
        ->and($wallet['topups'][0]['reason'])->toBe('واریز اضافه سفارش')
        ->and($wallet['topups'][0]['reviewer'])->toBe('Finance Root');
});

it('separates admin credits from ordinary topups across financial reports without counting them as sales', function (): void {
    $root = verifiedCreditRoot();
    $buyer = User::factory()->create();

    $manual = app(CreditVerifiedAdminBankTopup::class)->handle(
        $root->id, $buyer->id, 275_000, 'MANUAL275BANK', 'اضافه واریزی',
    );
    $normal = app(RequestTopup::class)->handle($buyer->id, 500_000, 'verified:customer:normal');
    app(SubmitTopupPhoto::class)->handle($buyer->id, $normal, 958112, 'photo-file-verified', 'verified-unique-photo', 14);
    app(ReviewTopup::class)->handle($root->id, $normal, true, 'CUSTOMER-TOPUP-BANK');

    expect(DB::table('billing_topups')->where('id', $normal)->value('origin'))->toBe('customer')
        ->and(DB::table('billing_topups')->where('id', $manual)->value('origin'))->toBe('admin_verified_bank');

    foreach (['7', '30', 'all'] as $period) {
        $report = app(ReadAdminCashReceipts::class)->handle($root->id, $period);
        expect($report['customerTopupsRials'])->toBe(500_000)
            ->and($report['customerTopupsCount'])->toBe(1)
            ->and($report['adminTopupsRials'])->toBe(275_000)
            ->and($report['adminTopupsCount'])->toBe(1)
            ->and($report['topupsRials'])->toBe(775_000)
            ->and($report['topupsCount'])->toBe(2)
            ->and($report['cashInRials'])->toBe(775_000)
            ->and($report['salesRials'])->toBe(0)
            ->and($report['currentWalletBalanceRials'])->toBe(775_000);
    }
    $profit = app(ReadAdminAccountingReport::class)->handle($root->id, 'month');
    expect($profit['salesRials'])->toBe(0)
        ->and($profit['profitRials'])->toBe(0);
});

it('rejects replay and cross-purpose bank reference collision and rolls back every partial write', function (): void {
    $root = verifiedCreditRoot();
    $a = User::factory()->create();
    $b = User::factory()->create();
    $credits = app(CreditVerifiedAdminBankTopup::class);

    $firstId = $credits->handle($root->id, $a->id, 275_000, 'ONCEBANKREF275', 'پرداخت اضافی');
    expect(fn () => $credits->handle($root->id, $a->id, 275_000, 'ONCEBANKREF275', 'تکرار'))
        ->toThrow(QueryException::class);
    expect(fn () => $credits->handle($root->id, $b->id, 550_000, 'ONCEBANKREF275', 'کاربر دیگر'))
        ->toThrow(QueryException::class);

    // A verified order reference is reserved globally, not just in the topup table.
    DB::table('billing_bank_reference_claims')->insert([
        'payment_reference' => 'RESERVED-SALES-REF',
        'source_type' => 'sales_order', 'source_id' => 41, 'verified_at' => now(),
    ]);
    expect(fn () => $credits->handle($root->id, $b->id, 100_000, 'RESERVED-SALES-REF', 'تکرار فروش'))
        ->toThrow(QueryException::class);
    expect(DB::table('billing_topups')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2)
        ->and(DB::table('billing_verified_receipts')->where('topup_id', $firstId)->exists())->toBeTrue()
        ->and(app(ViewWallet::class)->handle($a->id)->balanceRials)->toBe(275_000)
        ->and(app(ViewWallet::class)->handle($b->id)->balanceRials)->toBe(0);
});

it('blocks unauthorized roots, revoked grants, ineligible customers and invalid bank data without financial writes', function (): void {
    $root = verifiedCreditRoot();
    $buyer = User::factory()->create();
    $admin = User::factory()->admin()->create();
    $credits = app(CreditVerifiedAdminBankTopup::class);

    expect(fn () => $credits->handle($admin->id, $buyer->id, 275_000, 'VALIDBANKREF1', 'نامعتبر'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => $credits->handle($root->id, $buyer->id, 0, 'VALIDBANKREF1', 'بدون مبلغ'))
        ->toThrow(ValidationException::class);
    expect(fn () => $credits->handle($root->id, $buyer->id, 100_000_010, 'VALIDBANKREF1', 'بیش از سقف'))
        ->toThrow(ValidationException::class);
    expect(fn () => $credits->handle($root->id, $buyer->id, 275_000, 'BAD', 'شناسه اشتباه'))
        ->toThrow(ValidationException::class);
    expect(fn () => $credits->handle($root->id, $buyer->id, 275_000, 'VALIDBANKREF1', ''))
        ->toThrow(ValidationException::class);

    $buyer->forceFill(['is_active' => false])->save();
    expect(fn () => $credits->handle($root->id, $buyer->id, 275_000, 'VALIDBANKREF1', 'کاربر غیرفعال'))
        ->toThrow(AuthorizationException::class);
    $buyer->forceFill(['is_active' => true])->save();
    config()->set('billing.topups_enabled', false);
    expect(fn () => $credits->handle($root->id, $buyer->id, 275_000, 'VALIDBANKREF1', 'شارژ خاموش'))
        ->toThrow(AuthorizationException::class);
    config()->set('billing.topups_enabled', true);
    config()->set('super_admin.telegram_ids', ['958112']);
    expect(fn () => $credits->handle($root->id, $buyer->id, 275_000, 'VALIDBANKREF1', 'دسترسی لغو'))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(0)
        ->and(DB::table('billing_verified_receipts')->count())->toBe(0)
        ->and(DB::table('billing_topups')->count())->toBe(0);
});
