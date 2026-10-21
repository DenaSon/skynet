<?php

declare(strict_types=1);

use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupPhoto;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('billing.topups_enabled', true);
    config()->set('super_admin.telegram_ids', ['889901']);
});

function fin01Root(): User
{
    $root = User::factory()->create();
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 889901]);

    return $root;
}

it('reads bounded customer wallets and journals as a root, including nonactive customers, without balance edits', function (): void {
    $root = fin01Root();
    $buyer = User::factory()->create(['name' => 'Wallet customer']);
    app(ViewWallet::class)->handle($buyer->id);
    $initial = app(BrowseCustomerWallets::class)->detail($root->id, $buyer->id);
    expect($initial['balance_rials'])->toBe(0)
        ->and($initial['entries'])->toBe([])
        ->and($initial['topups'])->toBe([]);

    $request = app(RequestTopup::class)->handle($buyer->id, 550000, 'wallet:fin01:one');
    app(SubmitTopupPhoto::class)->handle($buyer->id, $request, 999002, 'wallet-photo-file', 'fin01-photo-one', 17);
    $queued = app(BrowsePhotoTopups::class)->pending($root->id);
    expect($queued)->toHaveCount(1)
        ->and($queued[0]['user_id'])->toBe($buyer->id)
        ->and($queued[0]['amount_rials'])->toBe(550000)
        ->and(app(BrowseCustomerWallets::class)->detail($root->id, $buyer->id)['balance_rials'])->toBe(0);

    $photo = app(BrowsePhotoTopups::class)->photo($root->id, $request);
    expect($photo['file_id'])->toBe('wallet-photo-file');
    app(ReviewTopup::class)->handle($root->id, $request, true, 'REALDEPOSIT889901');
    $details = app(BrowseCustomerWallets::class)->detail($root->id, $buyer->id);
    expect($details['balance_rials'])->toBe(550000)
        ->and($details['entries'])->toHaveCount(1)
        ->and($details['entries'][0]['side'])->toBe('credit')
        ->and($details['entries'][0]['amount_rials'])->toBe(550000)
        ->and($details['entries'][0]['journal'])->toBeGreaterThan(0)
        ->and($details['topups'][0]['reference'])->toBe('REALDEPOSIT889901')
        ->and($details['topups'][0]['status'])->toBe('approved')
        ->and(app(BrowsePhotoTopups::class)->pending($root->id))->toHaveCount(0)
        ->and(DB::table('billing_postings')->count())->toBe(2);

    $buyer->forceFill(['is_active' => false])->save();
    expect(app(BrowseCustomerWallets::class)->detail($root->id, $buyer->id)['active'])->toBeFalse();
    expect(app(BrowseCustomerWallets::class)->page($root->id)[0]['id'])->toBe($buyer->id);
    expect(fn () => app(ReviewTopup::class)->handle($root->id, $request, true, 'REALDEPOSIT889901'))
        ->toThrow(ValidationException::class);
    expect(DB::table('billing_journals')->count())->toBe(1);
});

it('refuses untrusted finance reads and photo access after allowlist revocation', function (): void {
    $root = fin01Root();
    $member = User::factory()->create();
    app(ViewWallet::class)->handle($member->id);
    $request = app(RequestTopup::class)->handle($member->id, 500000, 'wallet:fin01:two');
    app(SubmitTopupPhoto::class)->handle($member->id, $request, 999002, 'photo-file-fin01', 'photo-id-fin01', 18);

    foreach ([$member->id, User::factory()->admin()->create()->id] as $forgedId) {
        expect(fn () => app(BrowseCustomerWallets::class)->page($forgedId))->toThrow(AuthorizationException::class);
        expect(fn () => app(BrowseCustomerWallets::class)->detail($forgedId, $member->id))->toThrow(AuthorizationException::class);
        expect(fn () => app(BrowsePhotoTopups::class)->pending($forgedId))->toThrow(AuthorizationException::class);
        expect(fn () => app(BrowsePhotoTopups::class)->photo($forgedId, $request))->toThrow(AuthorizationException::class);
    }

    config()->set('super_admin.telegram_ids', ['889902']);
    expect(fn () => app(BrowsePhotoTopups::class)->photo($root->id, $request))->toThrow(AuthorizationException::class);
    expect(fn () => app(BrowseCustomerWallets::class)->page($root->id))->toThrow(AuthorizationException::class);
    expect(app(ViewWallet::class)->handle($member->id)->balanceRials)->toBe(0);
});

it('blocks stale, missing or reused deposit approvals and leaves rejected photos uncredited', function (): void {
    $root = fin01Root();
    $first = User::factory()->create();
    $second = User::factory()->create();
    $a = app(RequestTopup::class)->handle($first->id, 600000, 'wallet:fin01:three');
    $b = app(RequestTopup::class)->handle($second->id, 600000, 'wallet:fin01:four');
    app(SubmitTopupPhoto::class)->handle($first->id, $a, 999003, 'topup-a-file', 'fin01-id-a', 31);
    app(SubmitTopupPhoto::class)->handle($second->id, $b, 999004, 'topup-b-file', 'fin01-id-b', 32);

    expect(fn () => app(BrowsePhotoTopups::class)->photo($root->id, 999999))->toThrow(ValidationException::class);
    app(ReviewTopup::class)->handle($root->id, $a, true, 'BANK-REF-UNIQUE');
    expect(fn () => app(ReviewTopup::class)->handle($root->id, $b, true, 'BANK-REF-UNIQUE'))
        ->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
    expect(app(ViewWallet::class)->handle($second->id)->balanceRials)->toBe(0);
    app(ReviewTopup::class)->handle($root->id, $b, false, 'PHOTO-REJECT');
    expect(app(ViewWallet::class)->handle($second->id)->balanceRials)->toBe(0)
        ->and(DB::table('billing_journals')->count())->toBe(1)
        ->and(DB::table('billing_bank_reference_claims')->count())->toBe(1);
});
