<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SalesNativeMenu;

it('defines exactly the original four Sales destinations for the native command menu', function (): void {
    $commands = SalesNativeMenu::commands();

    expect(array_map(static fn ($item): string => $item->command, $commands))
        ->toBe(['buy', 'subscriptions', 'account', 'help'])
        ->and(array_map(static fn ($item): string => $item->description, $commands))
        ->toBe(['🛒 خرید سرویس', '📋 سرویس‌های من', '👤 حساب کاربری', '💬 پشتیبانی']);
});
