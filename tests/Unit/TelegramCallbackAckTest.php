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
