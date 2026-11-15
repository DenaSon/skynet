<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Support\SalesNativeMenu;

it('shows only three primary Sales commands while retaining old slash handlers', function (): void {
    $commands = SalesNativeMenu::commands();

    expect(array_map(static fn ($item): string => $item->command, $commands))
        ->toBe(['buy', 'subscriptions', 'help'])
        ->and(array_map(static fn ($item): string => $item->description, $commands))
        ->toBe(['🛒 خرید سرویس', '📋 سرویس‌های من', '💬 پشتیبانی']);
});
