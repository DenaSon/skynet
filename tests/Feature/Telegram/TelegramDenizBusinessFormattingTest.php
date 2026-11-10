<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Processing\DenizBusinessMessageFormatter;
use App\Interfaces\Telegram\Processing\DenizBusinessMessageSender;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('telegram.denix.token', '100003:deniz-only-test-token');
    Http::preventStrayRequests();
});

it('renders Persian headings, bold, italic, inline code and approved clickable links', function (): void {
    $raw = "### راهنمای نمایندگی\n"
        ."**نمایندگی دناگیت** و *راهنمای ثبت‌نام*\n"
        ."__اعتبار اولیه__، _موجودی_ و `نام کاربری`\n"
        .'[ورود به بات](https://t.me/denaGateBot) 🌿';

    $html = app(DenizBusinessMessageFormatter::class)->format($raw);

    expect($html)->toBe(
        "<b>راهنمای نمایندگی</b>\n"
        ."<b>نمایندگی دناگیت</b> و <i>راهنمای ثبت‌نام</i>\n"
        ."<b>اعتبار اولیه</b>، <i>موجودی</i> و <code>نام کاربری</code>\n"
        .'<a href="https://t.me/denaGateBot">ورود به بات</a> 🌿',
    );
});

it('renders nested emphasis and preserves literal Markdown in code fences', function (): void {
    $raw = "**سلام *دوست* من**\n```php\n\$x = '**literal** <tag> & value';\n```";

    expect(app(DenizBusinessMessageFormatter::class)->format($raw))
        ->toBe("<b>سلام <i>دوست</i> من</b>\n<pre>\$x = &#039;**literal** &lt;tag&gt; &amp; value&#039;;</pre>");
});

it('does not interpret link markup nested inside inline code', function (): void {
    $raw = '`[ورود به بات](https://t.me/denaGateBot)`';

    expect(app(DenizBusinessMessageFormatter::class)->format($raw))
        ->toBe('<code>[ورود به بات](https://t.me/denaGateBot)</code>');
});

it('escapes raw HTML and unsafe link attributes without allowing arbitrary HTML', function (): void {
    $raw = '<script>alert("x")</script> & <b>fake</b> '
        .'[مراجعه](https://example.org/plan?a=1&b=2) '
        .'[bad](javascript:alert(1))';

    $html = app(DenizBusinessMessageFormatter::class)->format($raw);

    expect($html)->toContain('&lt;script&gt;', '&lt;b&gt;fake&lt;/b&gt;')
        ->toContain('<a href="https://example.org/plan?a=1&amp;b=2">مراجعه</a>')
        ->toContain('[bad](javascript:alert(1))')
        ->not->toContain('<script>', '<b>fake</b>', 'href="javascript:');
});

it('does not create Telegram links with embedded credentials or unescaped labels', function (): void {
    $raw = '[نام کاربری](https://test:secret@example.org/path) [نام <ویژه>](https://t.me/denaGateBot)';

    expect(app(DenizBusinessMessageFormatter::class)->format($raw))
        ->toBe('[نام کاربری](https://test:secret@example.org/path) <a href="https://t.me/denaGateBot">نام &lt;ویژه&gt;</a>');
});

it('does not invent formatting for malformed or escaped Markdown or identifiers', function (): void {
    $raw = "این **متن ناقص است\n"
        .'sample_variable_name and 2*2=4 and \*not italic\*';

    expect(app(DenizBusinessMessageFormatter::class)->format($raw))->toBe($raw);
});

it('truncates before HTML rendering so formatted tags remain balanced', function (): void {
    $raw = str_repeat('ز', 3490).' **'.str_repeat('م', 100).'**';
    $html = app(DenizBusinessMessageFormatter::class)->format($raw);

    expect($html)->toBe(str_repeat('ز', 3490).' **'.str_repeat('م', 7))
        ->and(mb_strlen($html))->toBe(3500)
        ->and(substr_count($html, '<b>'))->toBe(0);
});

it('sends HTML-formatted text with the exact Business connection and reply target', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 777]]),
    ]);

    app(DenizBusinessMessageSender::class)->send(
        'trustedConnection', 190011, 74,
        '**نمایندگی دناگیت** [ورود به بات](https://t.me/denaGateBot)',
    );

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $req): bool => str_ends_with($req->url(), '/sendMessage')
        && $req['business_connection_id'] === 'trustedConnection'
        && $req['chat_id'] === 190011
        && $req['text'] === '<b>نمایندگی دناگیت</b> <a href="https://t.me/denaGateBot">ورود به بات</a>'
        && $req['parse_mode'] === 'HTML'
        && json_decode($req['reply_parameters'], true)['message_id'] === 74
        && json_decode($req['reply_parameters'], true)['allow_sending_without_reply'] === false
        && ! isset($req['entities']));
});

it('falls back exactly once to plain text only on a certain Telegram HTML entity parse rejection', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => "Bad Request: can't parse entities: Can't find end tag"], 400)
            ->push(['ok' => true, 'result' => ['message_id' => 777]], 200),
    ]);

    app(DenizBusinessMessageSender::class)->send('trustedConnection', 190011, 74, '**نمایندگی دناگیت**');

    Http::assertSentCount(2);

    $requests = collect(Http::recorded())->map(fn (array $pair): Request => $pair[0])->values();

    expect($requests[0]['parse_mode'])->toBe('HTML')
        ->and($requests[0]['text'])->toBe('<b>نمایندگی دناگیت</b>')
        ->and($requests[1]['text'])->toBe('**نمایندگی دناگیت**')
        ->and(isset($requests[1]['parse_mode']))->toBeFalse()
        ->and($requests[1]['business_connection_id'])->toBe('trustedConnection')
        ->and($requests[1]['reply_parameters'])->toBe($requests[0]['reply_parameters']);
});

it('never retries an ambiguous Telegram network failure', function (): void {
    $attempts = 0;
    Http::fake([
        'https://api.telegram.org/*' => function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('private network details');
        },
    ]);

    expect(fn () => app(DenizBusinessMessageSender::class)->send('trustedConnection', 190011, 74, '**سلام**'))
        ->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');

    expect($attempts)->toBe(1);
});


it('classifies Telegram delivery failures without storing request bodies or credentials in logs', function (): void {
    Log::spy();
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'PRIVATE-TELEGRAM-DETAIL'], 403),
    ]);

    expect(fn () => app(DenizBusinessMessageSender::class)->send(
        'privateConnectionId', 190011, 77, 'PRIVATE-QUESTION',
    ))->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');

    Log::shouldHaveReceived('warning')->with('Deniz Business delivery failed.',
        \Mockery::on(static fn (array $context): bool => ($context['http_status'] ?? null) === 403
            && ($context['phase'] ?? null) === 'html'
            && ($context['exception_type'] ?? null) === RuntimeException::class
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'PRIVATE')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), '190011')))->once();
});

it('never retries an unrelated HTTP 400 rejection', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
    ]);

    expect(fn () => app(DenizBusinessMessageSender::class)->send('trustedConnection', 190011, 75, '**سلام**'))
        ->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');

    Http::assertSentCount(1);
});

it('never retries an HTTP 5xx delivery error', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => false], 503),
    ]);

    expect(fn () => app(DenizBusinessMessageSender::class)->send('trustedConnection', 190011, 76, '**سلام**'))
        ->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');

    Http::assertSentCount(1);
});

it('tries the plain fallback only once even if that fallback fails', function (): void {
    Http::fake([
        'https://api.telegram.org/*' => Http::sequence()
            ->push(['ok' => false, 'description' => "Bad Request: can't parse entities"], 400)
            ->push(['ok' => false, 'description' => 'Bad Request: wrong reply'], 400),
    ]);

    expect(fn () => app(DenizBusinessMessageSender::class)->send('trustedConnection', 190011, 77, '**سلام**'))
        ->toThrow(RuntimeException::class, 'Deniz Business delivery failed.');

    Http::assertSentCount(2);
});
