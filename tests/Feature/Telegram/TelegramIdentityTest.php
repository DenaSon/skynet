<?php

use App\Modules\Identity\Application\IssueTelegramLinkCode;
use App\Modules\Identity\Application\LinkTelegramAccount;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Domain\UserRole;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\TelegramLinkToken;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('links an existing active user once via an expiring hashed code', function (): void {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $code = app(IssueTelegramLinkCode::class)->handle($user->id);

    expect(strlen($code))->toBe(48);
    expect(TelegramLinkToken::query()->firstOrFail()->token_hash)->toBe(hash('sha256', $code));

    $link = app(LinkTelegramAccount::class);
    expect($link->handle($code, 112233445566))->toBeTrue()
        ->and($link->handle($code, 998877))->toBeFalse()
        ->and(TelegramAccount::query()->count())->toBe(1)
        ->and(TelegramLinkToken::query()->count())->toBe(0);

    $actor = app(ResolveTelegramActor::class)->handle(112233445566);
    expect($actor?->userId)->toBe($user->id);
    expect($actor?->isAdmin())->toBeTrue();
});

it('expires codes, invalidates old codes on reissue and rejects invalid ids', function (): void {
    $user = User::factory()->create();
    $issue = app(IssueTelegramLinkCode::class);
    $link = app(LinkTelegramAccount::class);

    $first = $issue->handle($user->id);
    $second = $issue->handle($user->id);

    expect($link->handle($first, 1234))->toBeFalse();
    expect($link->handle($second, -1))->toBeFalse();
    expect($link->handle('invalid', 1234))->toBeFalse();

    $this->travel(11)->minutes();
    expect($link->handle($second, 1234))->toBeFalse();
});

it('does not allow one Telegram identity or one user account to be linked twice', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $link = app(LinkTelegramAccount::class);
    $issue = app(IssueTelegramLinkCode::class);

    $firstCode = $issue->handle($first->id);
    $secondCode = $issue->handle($second->id);

    expect($link->handle($firstCode, 56789))->toBeTrue()
        ->and($link->handle($secondCode, 56789))->toBeFalse();

    $this->expectException(ValidationException::class);
    $issue->handle($first->id);
});

it('does not resolve deactivated accounts even when a Telegram mapping exists', function (): void {
    $user = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $user->id, 'telegram_user_id' => 43210]);

    expect(app(ResolveTelegramActor::class)->handle(43210))->not->toBeNull();

    $user->forceFill(['is_active' => false])->save();

    expect(app(ResolveTelegramActor::class)->handle(43210))->toBeNull();
});

it('refuses to issue links for disabled users', function (): void {
    $user = User::factory()->create(['is_active' => false]);

    $this->expectException(\Illuminate\Validation\ValidationException::class);
    app(IssueTelegramLinkCode::class)->handle($user->id);
});
