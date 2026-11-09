<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\DenixGroupMessage;
use SergiX44\Nutgram\Nutgram;

/** @var Nutgram $bot */
// Each route calls the same server-side gate; it never sends from the webhook.
$bot->onCommand('ai', function (Nutgram $bot): void {
    app(DenixGroupMessage::class)->handle(request());
});

// Keep Denix-only controls separate from Admin and Sales handler registries.
$bot->onCommand('aimodel', function (Nutgram $bot): void {
    app(DenixGroupMessage::class)->handle(request());
});

$bot->onCommand('ainew', function (Nutgram $bot): void {
    app(DenixGroupMessage::class)->handle(request());
});

$bot->onCommand('aistatus', function (Nutgram $bot): void {
    app(DenixGroupMessage::class)->handle(request());
});

// Nutgram may also match a command as text. The message fence prevents double enqueue.
// Explicit replies and addressed /ai@denixobot commands are classified by the gate.
$bot->onText('{text}', function (Nutgram $bot, string $text): void {
    app(DenixGroupMessage::class)->handle(request());
});
