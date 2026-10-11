<?php

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use App\Modules\Referral\Application\GetReferralInvite;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('registers a Telegram user as a linked member without verified email or usable web credentials', function (): void {
    $joined = app(JoinFromTelegram::class)->handle(501001, "Welcome\nMember");
    $user = User::findOrFail($joined->userId);

    expect($joined->created)->toBeTrue()
        ->and($joined->referred)->toBeFalse()
        ->and($user->getRoleNames()->all())->toBe(['member'])
        ->and($user->name)->toBe('Welcome Member')
        ->and($user->is_active)->toBeTrue()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->email)->toEndWith('@accounts.skynet.invalid')
        ->and(TelegramAccount::query()->where('user_id', $user->id)->value('telegram_user_id'))->toBe(501001)
        ->and(app(ResolveTelegramActor::class)->handle(501001)?->userId)->toBe($user->id)
        ->and(Hash::check('password', $user->password))->toBeFalse();

    expect(DB::table('identity_audit_events')->where('event', 'identity.telegram.member_registered')->count())->toBe(1);
});

it('attributes one valid referral at signup and never reattributes on repeated start', function (): void {
    $referrer = app(JoinFromTelegram::class)->handle(501002, 'Referrer');
    $code = app(GetReferralInvite::class)->handle($referrer->userId)->code;

    $joined = app(JoinFromTelegram::class)->handle(501003, 'Friend', $code);
    expect($joined->created)->toBeTrue()->and($joined->referred)->toBeTrue();

    $other = app(JoinFromTelegram::class)->handle(501004, 'Other');
    $secondCode = app(GetReferralInvite::class)->handle($other->userId)->code;

    $repeat = app(JoinFromTelegram::class)->handle(501003, 'Friend renamed', $secondCode);
    expect($repeat->created)->toBeFalse()->and($repeat->referred)->toBeFalse()
        ->and($repeat->userId)->toBe($joined->userId)
        ->and(DB::table('referrals')->count())->toBe(1)
        ->and(DB::table('referrals')->where('invited_user_id', $joined->userId)->value('referrer_user_id'))->toBe($referrer->userId)
        ->and(app(GetReferralInvite::class)->handle($referrer->userId)->joinedCount)->toBe(1)
        ->and(app(GetReferralInvite::class)->handle($other->userId)->joinedCount)->toBe(0);
});

it('generates one stable high-entropy referral code per user', function (): void {
    $first = app(JoinFromTelegram::class)->handle(501005, 'Alice');
    $second = app(JoinFromTelegram::class)->handle(501006, 'Bob');

    $one = app(GetReferralInvite::class)->handle($first->userId);
    $again = app(GetReferralInvite::class)->handle($first->userId);
    $other = app(GetReferralInvite::class)->handle($second->userId);

    expect(strlen($one->code))->toBe(24)
        ->and($one->code)->toBe($again->code)
        ->and($one->code)->not->toBe($other->code)
        ->and(DB::table('referral_codes')->count())->toBe(2);
});

it('does not attribute invalid or unknown invite codes', function (): void {
    $first = app(JoinFromTelegram::class)->handle(501007, 'Invalid', 'injection');
    $second = app(JoinFromTelegram::class)->handle(501008, 'Unknown', str_repeat('a', 24));

    expect($first->created)->toBeTrue()
        ->and($first->referred)->toBeFalse()
        ->and($second->referred)->toBeFalse()
        ->and(DB::table('referrals')->count())->toBe(0);
});

it('never changes a pre-linked admin account or reactivates a disabled user', function (): void {
    $admin = User::factory()->admin()->create();
    TelegramAccount::query()->create(['user_id' => $admin->id, 'telegram_user_id' => 501009]);
    $code = app(GetReferralInvite::class)->handle($admin->id)->code;

    $result = app(JoinFromTelegram::class)->handle(501009, 'new name', $code);

    expect($result->created)->toBeFalse()
        ->and($admin->fresh()->hasRole('admin'))->toBeTrue()
        ->and($admin->fresh()->name)->not->toBe('new name')
        ->and(DB::table('referrals')->count())->toBe(0);

    $admin->forceFill(['is_active' => false])->save();
    $repeat = app(JoinFromTelegram::class)->handle(501009, 'another name');
    expect($repeat->created)->toBeFalse()
        ->and($admin->fresh()->is_active)->toBeFalse()
        ->and(User::query()->count())->toBe(1);

    expect(fn () => app(GetReferralInvite::class)->handle($admin->id))
        ->toThrow(AuthorizationException::class);
});

it('rejects invalid numeric Telegram IDs and never creates an account', function (): void {
    expect(fn () => app(JoinFromTelegram::class)->handle(-5, 'Invalid'))
        ->toThrow(ValidationException::class);
    expect(User::query()->count())->toBe(0);
});
