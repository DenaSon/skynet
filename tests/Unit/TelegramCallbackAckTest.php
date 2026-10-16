<?php

use App\Interfaces\Telegram\Support\TelegramUi;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;

it('treats only expired Telegram callback confirmations as nonfatal', function (): void {
    foreach ([
        'Bad Request: query is too old and response timeout expired or query ID is invalid',
        'Bad Request: query ID is invalid',
    ] as $message) {
        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('isCallbackQuery')->once()->andReturn(true);
        $bot->shouldReceive('answerCallbackQuery')->once()
            ->andThrow(new TelegramException($message, 400));

        TelegramUi::answerCallback($bot);
    }
});

it('preserves actionable Telegram errors and never silently accepts arbitrary 400 responses', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('isCallbackQuery')->once()->andReturn(true);
    $bot->shouldReceive('answerCallbackQuery')->once()
        ->andThrow(new TelegramException('Bad Request: chat not found', 400));

    expect(fn () => TelegramUi::answerCallback($bot))
        ->toThrow(TelegramException::class, 'chat not found');
});

it('does not swallow temporary Telegram errors or acknowledge ordinary commands', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('isCallbackQuery')->once()->andReturn(false);
    $bot->shouldNotReceive('answerCallbackQuery');
    TelegramUi::answerCallback($bot);

    $callback = Mockery::mock(Nutgram::class);
    $callback->shouldReceive('isCallbackQuery')->once()->andReturn(true);
    $callback->shouldReceive('answerCallbackQuery')->once()
        ->andThrow(new TelegramException('Service Unavailable', 503));

    expect(fn () => TelegramUi::answerCallback($callback))
        ->toThrow(TelegramException::class, 'Service Unavailable');
});

it('keeps existing optional callback denial text without altering its message', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('isCallbackQuery')->once()->andReturn(true);
    $bot->shouldReceive('answerCallbackQuery')->once()->with(null, 'دسترسی مجاز نیست.');

    TelegramUi::answerCallback($bot, 'دسترسی مجاز نیست.');
});

it('accepts only a confirmed unchanged Telegram message edit as an already-rendered success', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('editMessageText')->once()->andThrow(
        new TelegramException(
            'Bad Request: message is not modified: specified new message content and reply markup are exactly the same',
            400,
        ),
    );

    TelegramUi::editText($bot, '🧾 رسیدهای در انتظار بررسی');
});

it('preserves actionable edit errors, including other HTTP 400 and non-400 unchanged responses', function (): void {
    foreach ([
        new TelegramException('Bad Request: message to edit not found', 400),
        new TelegramException('Bad Request: message is not modified', 403),
        new TelegramException('Too Many Requests: retry after 5', 429),
    ] as $exception) {
        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('editMessageText')->once()->andThrow($exception);

        expect(fn () => TelegramUi::editText($bot, 'متن جدید'))
            ->toThrow(TelegramException::class, $exception->getMessage());
    }
});

it('keeps normal Telegram message edits intact', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('editMessageText')->once()->andReturn(null);

    TelegramUi::editText($bot, '◀️ مدیریت');
});

it('accepts repeated HTML-mode edits without changing the original formatting contract', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('editMessageText')->once()->andThrow(
        new TelegramException('Bad Request: message is not modified', 400),
    );

    TelegramUi::editText($bot, '<b>مدیریت پلن‌ها</b>', null, 'HTML');
});

it('accepts only an unchanged caption edit and preserves all other caption failures', function (): void {
    $alreadyShown = Mockery::mock(Nutgram::class);
    $alreadyShown->shouldReceive('editMessageCaption')->once()->andThrow(
        new TelegramException('Bad Request: message is not modified', 400),
    );

    TelegramUi::editCaption($alreadyShown, 'رسید خرید');

    foreach ([
        new TelegramException('Bad Request: message to edit not found', 400),
        new TelegramException('Bad Request: message is not modified', 403),
        new TelegramException('Too Many Requests: retry after 5', 429),
    ] as $exception) {
        $bot = Mockery::mock(Nutgram::class);
        $bot->shouldReceive('editMessageCaption')->once()->andThrow($exception);

        expect(fn () => TelegramUi::editCaption($bot, 'رسید خرید'))
            ->toThrow(TelegramException::class, $exception->getMessage());
    }
});

it('preserves explicit receipt-photo edit targets for committed payment navigation', function (): void {
    $bot = Mockery::mock(Nutgram::class);
    $bot->shouldReceive('editMessageCaption')->once()
        ->withArgs(static fn (...$arguments): bool => in_array(1729, $arguments, true)
            && in_array(317, $arguments, true))
        ->andThrow(new TelegramException('Bad Request: message is not modified', 400));

    TelegramUi::editCaption($bot, 'رسید تأیید شد', null, 1729, 317);
});
