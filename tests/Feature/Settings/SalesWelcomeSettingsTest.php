<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SalesWelcomeMessage;
use App\Modules\Identity\Application\TelegramActor;
use App\Settings\SalesWelcomeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads the seeded welcome messages and selects them independently by role', function (): void {
    $settings = app(SalesWelcomeSettings::class);

    expect($settings->member)->toContain('{name}')
        ->and($settings->reseller)->toContain('{account_type}');

    $settings->member = "سلام {name}\nکاربر {account_type}";
    $settings->reseller = "نماینده {name}\n{account_type}";
    $settings->save();

    $member = new TelegramActor(1, 'علی', ['member'], false);
    $reseller = new TelegramActor(2, 'رضا', ['reseller'], false);

    expect(app(SalesWelcomeMessage::class)->forActor($member))->toContain('سلام علی')
        ->toContain('کاربر 👤 کاربر عادی')
        ->and(app(SalesWelcomeMessage::class)->forActor($reseller))->toContain('نماینده رضا')
        ->toContain('🧩 پنل فروش');
});

it('escapes operator-authored markup and user names', function (): void {
    $settings = app(SalesWelcomeSettings::class);
    $settings->member = '<b>bad</b> {name}';
    $settings->save();

    $actor = new TelegramActor(1, '<script>', ['member'], false);

    expect(app(SalesWelcomeMessage::class)->forActor($actor))
        ->toContain('&lt;b&gt;bad&lt;/b&gt;')
        ->toContain('&lt;script&gt;')
        ->not->toContain('<script>');
});
