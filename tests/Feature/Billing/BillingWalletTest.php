<?php

use App\Modules\Billing\Application\ListTopupReviews;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function billingReseller(): User
{
    Role::findOrCreate('reseller', 'web');
    $user = User::factory()->create();
    $user->syncRoles(['member', 'reseller']);

    return $user;
}

function billingRoot(int $telegramId = 747474): User
{
    config()->set('super_admin.telegram_ids', ['747474', '858585']);
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => $telegramId]);

    return $root;
}

it('creates one empty wallet for active members and resellers without granting member write permissions', function (): void {
    $reseller = billingReseller();
    $snapshot = app(ViewWallet::class)->handle($reseller->id);
    expect($snapshot->balanceRials)->toBe(0)
        ->and($snapshot->topups)->toBe([])
        ->and(DB::table('billing_wallets')->count())->toBe(1);

    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_wallets')->count())->toBe(1);

    $member = User::factory()->create();
    $member->assignRole('member');
    expect(app(ViewWallet::class)->handle($member->id)->balanceRials)->toBe(0)
        ->and(app(ViewWallet::class)->handle($member->id)->topups)->toBe([])
        ->and(DB::table('billing_wallets')->count())->toBe(2)
        ->and(app(RequestTopup::class)->handle($member->id, 500000, 'tg:member:123456'))->toBeGreaterThan(0);

    $outsider = User::factory()->create();
    $outsider->syncRoles([]);
    expect(fn () => app(ViewWallet::class)->handle($outsider->id))->toThrow(AuthorizationException::class);
    $member->forceFill(['is_active' => false])->save();
    expect(fn () => app(ViewWallet::class)->handle($member->id))->toThrow(AuthorizationException::class);
    $reseller->forceFill(['is_active' => false])->save();
    expect(fn () => app(ViewWallet::class)->handle($reseller->id))->toThrow(AuthorizationException::class);
});

it('credits a manually verified transfer once using balanced liability and cash postings', function (): void {
    $reseller = billingReseller();
    $root = billingRoot();
    $topups = app(RequestTopup::class);

    $id = $topups->handle($reseller->id, 5000000, 'tg:121212:1001');
    expect($topups->handle($reseller->id, 5000000, 'tg:121212:1001'))->toBe($id)
        ->and(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0);
    expect(fn () => $topups->handle($reseller->id, 6000000, 'tg:121212:1001'))->toThrow(ValidationException::class);

    app(SubmitTopupReference::class)->handle($reseller->id, $id, 'bank102938');
    expect(app(ListTopupReviews::class)->handle($root->id))->toHaveCount(1);
    expect(app(ReviewTopup::class)->handle($root->id, $id, true, 'BANK102938'))->toBeTrue();

    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(5000000)
        ->and(DB::table('billing_verified_receipts')->count())->toBe(1)
        ->and(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('billing_postings')->count())->toBe(2)
        ->and(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('approved')
        ->and(DB::table('billing_topups')->where('id', $id)->value('reviewed_by'))->toBe($root->id);

    $debits = (int) DB::table('billing_postings')->where('side', 'debit')->sum('amount_rials');
    $credits = (int) DB::table('billing_postings')->where('side', 'credit')->sum('amount_rials');
    expect($debits)->toBe(5000000)->and($credits)->toBe($debits);

    expect(fn () => app(ReviewTopup::class)->handle($root->id, $id, true, 'BANK102938'))
        ->toThrow(ValidationException::class);

    expect(DB::table('billing_journals')->count())->toBe(1);
});

it('never credits rejected or merely submitted requests', function (): void {
    $reseller = billingReseller();
    $root = billingRoot();
    $id = app(RequestTopup::class)->handle($reseller->id, 900000, 'tg:121212:1002');

    app(SubmitTopupReference::class)->handle($reseller->id, $id, 'PROOF55555');
    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0);
    expect(app(ReviewTopup::class)->handle($root->id, $id, false, 'PROOF55555'))->toBeTrue();
    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_verified_receipts')->count())->toBe(0);

    expect(fn () => app(ReviewTopup::class)->handle($root->id, $id, true, 'PROOF55555'))
        ->toThrow(ValidationException::class);
});

it('rejects reuse of an already approved bank transfer reference by another reseller', function (): void {
    $one = billingReseller();
    $two = billingReseller();
    $root = billingRoot();

    $first = app(RequestTopup::class)->handle($one->id, 1000000, 'tg:121212:1003');
    $second = app(RequestTopup::class)->handle($two->id, 2000000, 'tg:121212:1004');

    app(SubmitTopupReference::class)->handle($one->id, $first, 'DUPLICATE999');
    app(SubmitTopupReference::class)->handle($two->id, $second, 'duplicate999');
    app(ReviewTopup::class)->handle($root->id, $first, true, 'DUPLICATE999');

    expect(fn () => app(ReviewTopup::class)->handle($root->id, $second, true, 'DUPLICATE999'))
        ->toThrow(ValidationException::class);

    expect(app(ViewWallet::class)->handle($one->id)->balanceRials)->toBe(1000000)
        ->and(app(ViewWallet::class)->handle($two->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_postings')->count())->toBe(2);
});

it('checks ownership, identity and stale proof state on every wallet action', function (): void {
    $one = billingReseller();
    $other = billingReseller();
    $id = app(RequestTopup::class)->handle($one->id, 1200000, 'tg:121212:1005');

    expect(fn () => app(SubmitTopupReference::class)->handle($other->id, $id, 'ALPHA123'))->toThrow(ValidationException::class);
    app(SubmitTopupReference::class)->handle($one->id, $id, 'ALPHA123');
    app(SubmitTopupReference::class)->handle($one->id, $id, 'alpha123'); // idempotent identical reference

    expect(fn () => app(SubmitTopupReference::class)->handle($one->id, $id, 'OTHER123'))->toThrow(ValidationException::class);
    expect(fn () => app(RequestTopup::class)->handle($one->id, -10, 'tg:121212:1006'))->toThrow(ValidationException::class);
});

it('requires current allowlisted root privileges to review even if a review button is old', function (): void {
    $reseller = billingReseller();
    $root = billingRoot();
    $id = app(RequestTopup::class)->handle($reseller->id, 2500000, 'tg:121212:1007');
    app(SubmitTopupReference::class)->handle($reseller->id, $id, 'CHECK123456');

    $ordinary = User::factory()->admin()->create();
    expect(fn () => app(ListTopupReviews::class)->handle($ordinary->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ReviewTopup::class)->handle($ordinary->id, $id, true, 'CHECK123456'))->toThrow(AuthorizationException::class);

    config()->set('super_admin.telegram_ids', ['858585', '999999']);
    expect(fn () => app(ReviewTopup::class)->handle($root->id, $id, true, 'CHECK123456'))->toThrow(AuthorizationException::class);
    expect(app(ViewWallet::class)->handle($reseller->id)->balanceRials)->toBe(0);
});

it('records a member top-up photo without credit and only credits after a real reviewed bank reference', function (): void {
    config()->set('billing.topups_enabled', true);
    $member = User::factory()->create();
    $root = billingRoot();
    $id = app(RequestTopup::class)->handle($member->id, 500000, 'member:topup:123');
    app(SubmitTopupPhoto::class)->handle($member->id, $id, 900001, 'tg-photo-file-one', 'tg-unique-one', 71);

    expect(DB::table('billing_topup_photos')->count())->toBe(1)
        ->and(DB::table('billing_topups')->where('id', $id)->value('status'))->toBe('submitted')
        ->and(app(ViewWallet::class)->handle($member->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0)
        ->and(DB::table('billing_topup_photos')->where('topup_id', $id)->value('file_encrypted'))->not->toBe('tg-photo-file-one');

    app(SubmitTopupPhoto::class)->handle($member->id, $id, 900001, 'tg-photo-file-one', 'tg-unique-one', 71);
    expect(DB::table('billing_topup_photos')->count())->toBe(1);

    app(ReviewTopup::class)->handle($root->id, $id, true, 'REALBANK123');
    expect(app(ViewWallet::class)->handle($member->id)->balanceRials)->toBe(500000)
        ->and(DB::table('billing_bank_reference_claims')->where('payment_reference', 'REALBANK123')->count())->toBe(1)
        ->and(DB::table('billing_topups')->where('id', $id)->value('payment_reference'))->toBe('REALBANK123')
        ->and(DB::table('billing_postings')->count())->toBe(2);
    expect(fn () => app(ReviewTopup::class)->handle($root->id, $id, true, 'REALBANK123'))->toThrow(ValidationException::class);
});

it('blocks foreign, repeated and cross-purpose photo reuse without creating credit', function (): void {
    config()->set('billing.topups_enabled', true);
    $one = User::factory()->create();
    $two = User::factory()->create();
    $first = app(RequestTopup::class)->handle($one->id, 500000, 'one:topup:101');
    $second = app(RequestTopup::class)->handle($two->id, 700000, 'two:topup:101');

    expect(fn () => app(SubmitTopupPhoto::class)->handle($two->id, $first, 900002, 'foreign-file-id', 'foreign-unique-id', 71))->toThrow(ValidationException::class);
    app(SubmitTopupPhoto::class)->handle($one->id, $first, 900001, 'valid-file-id', 'shared-unique-id', 72);
    expect(fn () => app(SubmitTopupPhoto::class)->handle($two->id, $second, 900002, 'another-file-id', 'shared-unique-id', 73))->toThrow(ValidationException::class);

    expect(DB::table('billing_journals')->count())->toBe(0)
        ->and(app(ViewWallet::class)->handle($two->id)->balanceRials)->toBe(0);
});
it('requires a positive top-up flag before accepting customer photo', function (): void {
    $member = User::factory()->create();
    $id = app(RequestTopup::class)->handle($member->id, 500000, 'member:topup:999');
    config()->set('billing.topups_enabled', false);
    expect(fn () => app(SubmitTopupPhoto::class)->handle($member->id, $id, 900001, 'valid-photo-file', 'valid-unique-id', 72))
        ->toThrow(AuthorizationException::class);
    expect(DB::table('billing_topup_photos')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});


it('rejects recharge requests over 10 million tomans without creating a financial record', function (): void {
    $reseller = billingReseller();
    expect(fn () => app(RequestTopup::class)->handle($reseller->id, 100000010, 'limit:topup:over'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_topups')->count())->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(0);
});
