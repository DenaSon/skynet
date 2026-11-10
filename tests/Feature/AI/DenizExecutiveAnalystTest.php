<?php

declare(strict_types=1);

use App\Interfaces\Telegram\Jobs\AnswerDenixMessage;
use App\Interfaces\Telegram\Processing\DenixConversationState;
use App\Interfaces\Telegram\Processing\DenixGroupAccess;
use App\Interfaces\Telegram\Processing\DenixMessageSender;
use App\Modules\AI\Application\AskAssistant;
use App\Modules\AI\Application\DenixAssistant;
use App\Modules\AI\Application\PrepareDenizExecutiveAnalysis;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-10 15:30', 'Asia/Tehran')->utc());
    config()->set('denagate_ai.enabled', true);
    config()->set('telegram.denix.enabled', true);
    config()->set('cache.default', 'database');
    config()->set('telegram.denix.token', '100003:denix-executive-test');
    config()->set('telegram.denix.webhook_secret', 'exec-secret');
    config()->set('nutgram.token', '100001:admin');
    config()->set('telegram.webhook_secret', 'admin-secret');
    config()->set('telegram.sales.token', '100002:sales');
    config()->set('telegram.sales.webhook_secret', 'sales-secret');
    config()->set('telegram.denix.group_chat_id', -100987654321);
    config()->set('ai.providers.gapgpt.key', 'test-only-key');
    config()->set('ai.providers.gapgpt.url', 'https://api.gapgpt.app/v1');
    config()->set('super_admin.telegram_ids', ['180001', '180002']);
    Cache::flush();
    Http::preventStrayRequests();
});

function executive02Root(): User
{
    Role::findOrCreate('super-admin', 'web');
    $root = User::factory()->create(['is_active' => true]);
    $root->syncRoles(['super-admin']);
    TelegramAccount::query()->create(['user_id' => $root->id, 'telegram_user_id' => 180001]);

    return $root;
}

function executive02Sale(User $root, int $amount): void
{
    $buyer = User::factory()->create(['name' => 'Never Export This Name']);
    $order = (int) DB::table('sales_orders')->insertGetId([
        'buyer_user_id' => $buyer->id,
        'buyer_category' => 'direct',
        'idempotency_key' => bin2hex(random_bytes(16)),
        'product_slug' => 'executive02',
        'product_name' => 'Private plan name',
        'duration_days' => 30,
        'traffic_gb' => 20,
        'public_price_rials' => $amount,
        'discount_percent' => 0,
        'discount_rials' => 0,
        'total_price_rials' => $amount,
        'status' => 'fulfilled',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $journal = (int) DB::table('billing_journals')->insertGetId([
        'kind' => 'direct_bank_sale',
        'idempotency_key' => 'executive02:bank:'.$order,
        'created_at' => now(),
    ]);
    $at = Carbon::parse('2026-11-08 10:00:00', 'Asia/Tehran')->utc();
    $claim = (int) DB::table('sales_bank_payment_claims')->insertGetId([
        'sales_order_id' => $order,
        'buyer_user_id' => $buyer->id,
        'photo_encrypted' => 'private-receipt',
        'photo_fingerprint' => hash('sha256', 'exec02-'.$order),
        'telegram_message_key' => 'exec02-'.$order,
        'status' => 'approved',
        'reviewed_by' => $root->id,
        'reviewed_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
    DB::table('sales_bank_settlements')->insert([
        'sales_order_id' => $order,
        'payment_claim_id' => $claim,
        'billing_journal_id' => $journal,
        'verified_by' => $root->id,
        'payment_reference' => 'PRIVATEBANKREFERENCE'.$order,
        'amount_rials' => $amount,
        'settled_at' => $at,
    ]);
}

function executive02Response(string $text = '🧠 تحلیل: فروش قابل بررسی است. 💡 اقدام پیشنهادی: روند خرید مجدد را پایش کنید.'): array
{
    return [
        'id' => 'executive02-chat',
        'object' => 'chat.completion',
        'model' => 'gpt-6-luna',
        'choices' => [
            ['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop'],
        ],
        'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30],
    ];
}

function executive02Job(int $messageId, string $question): void
{
    (new AnswerDenixMessage(-100987654321, 180001, $messageId, 12, $question))->handle(
        app(DenixGroupAccess::class),
        app(AskAssistant::class),
        app(DenixMessageSender::class),
        app(DenixConversationState::class),
    );
}

it('selects only explicit trusted executive requests with correct temporal semantics', function (): void {
    executive02Root();
    $prepare = app(PrepareDenizExecutiveAnalysis::class);

    foreach ([
        ['تحلیل فروش امروز', '"dataset":"sales","period":"day"'],
        ['تحلیل فروش ۲۴ ساعت اخیر', '"dataset":"sales","period":"24h"'],
        ['گزارش نمایندگی هفتگی', '"dataset":"sales","period":"week"'],
        ['تحلیل فروش ماهانه', '"dataset":"sales","period":"month"'],
        ['گزارش نقدینگی ۳۰ روز', '"dataset":"cash","period":"30"'],
        ['گزارش دریافتی‌ها از ابتدا', '"dataset":"cash","period":"all"'],
        ['تحلیل شارژ کیف پول', '"dataset":"cash","period":"7"'],
        ['گزارش حسابداری ۷ روز', '"dataset":"accounting","period":"7"'],
        ['تحلیل مالی ماه جاری', '"dataset":"accounting","period":"month"'],
        ['گزارش کیف پول', '"dataset":"wallet","period":"current"'],
    ] as [$question, $expected]) {
        $prepared = $prepare->handle(180001, $question);
        expect($prepared)->not->toBeNull()
            ->and($prepared['prompt'])->toContain($expected)
            ->and(mb_strlen($prepared['prompt']))->toBeLessThanOrEqual(2000)
            ->and($prepared['headline'])->toContain('🕒');
    }

    expect($prepare->handle(180001, 'سلام به دنیز'))->toBeNull()
        ->and($prepare->handle(180001, 'اینترنت قطع شده'))->toBeNull();
    Http::assertNothingSent();
});

it('rejects misleading time ranges instead of silently mapping current-month sales or cash to a different period', function (): void {
    executive02Root();
    $prepare = app(PrepareDenizExecutiveAnalysis::class);

    foreach (['تحلیل فروش این ماه', 'گزارش فروش ماه جاری', 'گزارش نقدینگی این ماه', 'تحلیل کیف پول ماه قبل'] as $question) {
        expect(fn () => $prepare->handle(180001, $question))
            ->toThrow(InvalidArgumentException::class);
    }

    Http::assertNothingSent();
});

it('sends canonical sales evidence plus an executive-only prompt and never exports customer details', function (): void {
    $root = executive02Root();
    executive02Sale($root, 2_500_000);
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response()),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]]),
    ]);

    executive02Job(101, 'تحلیل فروش هفتگی');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['model'] === 'gpt-6-luna'
        && str_contains($request['messages'][0]['content'], 'مشاور ارشد حسابداری')
        && str_contains($request['messages'][1]['content'], '"revenueRials":2500000')
        && str_contains($request['messages'][1]['content'], '"reseller"')
        && ! str_contains(json_encode($request['messages'], JSON_THROW_ON_ERROR), 'PRIVATEBANKREFERENCE')
        && ! str_contains(json_encode($request['messages'], JSON_THROW_ON_ERROR), 'Never Export This Name')
        && ! str_contains(json_encode($request['messages'], JSON_THROW_ON_ERROR), 'buyerUserId')
        && ! str_contains(json_encode($request['messages'], JSON_THROW_ON_ERROR), 'Private plan name'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && $request['chat_id'] === -100987654321
        && $request['message_thread_id'] === 12
        && str_contains($request['text'], '250,000 تومان')
        && str_contains($request['text'], '🧠 تحلیل')
        && str_contains($request['text'], '۷ روز کامل')
        && ! str_contains($request['text'], 'PRIVATEBANKREFERENCE'));

    // Finance is deliberately absent from the ordinary group conversation cache.
    expect(app(DenixConversationState::class)->history(-100987654321, 12, 180001))->toBeEmpty();
});

it('keeps ordinary group chat and customer-support prompts distinct', function (): void {
    executive02Root();
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response('جواب عادی')),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 902]]),
    ]);

    executive02Job(102, 'سلام');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && $request['messages'][1]['content'] === 'سلام'
        && ! str_contains($request['messages'][0]['content'], 'مشاور ارشد حسابداری')
        && str_contains($request['messages'][0]['content'], 'دستیار هوش مصنوعی دناگیت'));

    expect(app(DenixConversationState::class)->history(-100987654321, 12, 180001))->toHaveCount(2);

    expect(app(DenixAssistant::class)->instructions())->not->toContain('مشاور ارشد حسابداری');
    expect((new DenixAssistant(customerSupport: true))->instructions())
        ->toContain('اکنون در گفتگوی خصوصی حساب پشتیبانی')
        ->not->toContain('مشاور ارشد حسابداری');

    expect(fn () => app(AskAssistant::class)->handle('سؤال', customerSupport: true, executive: true))
        ->toThrow(ValidationException::class);
});

it('does not issue paid AI calls on unsupported financial windows or denied identities', function (): void {
    executive02Root();
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response()),
        'https://api.telegram.org/*' => Http::response(['ok' => true]),
    ]);

    executive02Job(103, 'تحلیل فروش این ماه');
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], 'آخرین ماه کامل'));

    DB::table('users')->where('id', TelegramAccount::query()->where('telegram_user_id', 180001)->firstOrFail()->user_id)
        ->update(['is_active' => false]);
    executive02Job(104, 'تحلیل فروش هفتگی');

    Http::assertSentCount(1);
    expect(app(DenixConversationState::class)->history(-100987654321, 12, 180001))->toBeEmpty();
});

it('does not repeat paid executive requests or cache financial evidence after an uncertain send', function (): void {
    $root = executive02Root();
    executive02Sale($root, 1_000_000);
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response()),
        'https://api.telegram.org/*' => Http::response(['ok' => false], 503),
    ]);

    expect(fn () => executive02Job(105, 'تحلیل فروش هفتگی'))->toThrow(RuntimeException::class, 'Denix response delivery failed.');
    executive02Job(105, 'تحلیل فروش هفتگی');
    Http::assertSentCount(2);
    expect(app(DenixConversationState::class)->history(-100987654321, 12, 180001))->toBeEmpty();
});

it('provides an exact rolling 24-hour statement to the Deniz executive model and group reply', function (): void {
    $this->travelTo(Carbon::parse('2026-11-08 15:30:00', 'Asia/Tehran')->utc());
    $root = executive02Root();
    executive02Sale($root, 2_500_000); // settled at 10:00 Tehran today

    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response()),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 907]]),
    ]);

    executive02Job(107, 'تحلیل فروش ۲۴ ساعت اخیر');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.gapgpt.app/v1/chat/completions'
        && str_contains($request['messages'][1]['content'], '"period":"24h"')
        && str_contains($request['messages'][1]['content'], '"from":"2026-11-07 15:30:00"')
        && str_contains($request['messages'][1]['content'], '"until":"2026-11-08 15:30:00"')
        && str_contains($request['messages'][1]['content'], '"revenueRials":2500000'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
        && str_contains($request['text'], '۲۴ ساعت اخیر')
        && str_contains($request['text'], '250,000 تومان')
        && ! str_contains($request['text'], 'PRIVATEBANKREFERENCE'));

    expect(app(DenixConversationState::class)->history(-100987654321, 12, 180001))->toBeEmpty();
});

it('answers natural sales questions and same-topic follow-ups from freshly re-read settlement data', function (): void {
    $this->travelTo(Carbon::parse('2026-11-08 15:30:00', 'Asia/Tehran')->utc());
    $root = executive02Root();
    executive02Sale($root, 1_000_000);
    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response('🧠 تحلیل: مقایسه ثبت شده است.')),
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 925]]),
    ]);

    executive02Job(220, 'فروش ۲۴ ساعت اخیر چطور بوده؟');
    $state = app(DenixConversationState::class);
    expect($state->executiveSelection(-100987654321, 12, 180001))
        ->toBe(['dataset' => 'sales', 'period' => '24h'])
        ->and($state->history(-100987654321, 12, 180001))->toBeEmpty();

    // New settlement after the first analysis: do not reuse a cached number.
    executive02Sale($root, 700_000);
    executive02Job(221, 'چرا بیشتر شده؟');

    $calls = Http::recorded()->map(fn (array $pair): Request => $pair[0])
        ->filter(fn (Request $request): bool => str_contains($request->url(), 'gapgpt.app/v1/chat/completions'))
        ->values();
    expect($calls)->toHaveCount(2)
        ->and($calls[0]['messages'][1]['content'])->toContain('"revenueRials":1000000')
        ->and($calls[1]['messages'][1]['content'])->toContain('"revenueRials":1700000')
        ->and($calls[1]['messages'][1]['content'])->toContain('چرا بیشتر شده؟');

    Http::assertSentCount(4);
    expect($state->history(-100987654321, 12, 180001))->toBeEmpty();
});

it('selects common natural-language finance intents and refuses mixed evidence without charging AI', function (): void {
    executive02Root();
    $prepare = app(PrepareDenizExecutiveAnalysis::class);

    foreach ([
        ['امروز فروش چقدر شده؟', '"dataset":"sales","period":"day"'],
        ['نمایندگی‌ها چطور فروش داشتن؟', '"dataset":"sales","period":"week"'],
        ['در ۳۰ روز اخیر چقدر شارژ داشتیم؟', '"dataset":"cash","period":"30"'],
        ['موجودی کیف پول‌ها چقدره؟', '"dataset":"wallet","period":"current"'],
        ['هزینه‌های این ماه چطور بوده؟', '"dataset":"accounting","period":"month"'],
    ] as [$question, $expected]) {
        expect($prepare->handle(180001, $question)['prompt'])->toContain($expected);
    }

    foreach ([
        'فروش و شارژ کیف پول را باهم تحلیل کن',
        'میزان فروش در برابر سود چقدر است؟',
        'فروش این ماه چطور بوده؟',
        'فروش دیروز چقدر بود؟',
    ] as $question) {
        expect(fn () => $prepare->handle(180001, $question))
            ->toThrow(InvalidArgumentException::class);
    }

    Http::assertNothingSent();
});

it('stores only a per-actor per-topic report selector and clears it on reset or model change', function (): void {
    executive02Root();
    $state = app(DenixConversationState::class);
    $state->rememberExecutiveSelection(-100987654321, 12, 180001, 'sales', 'week');

    expect($state->executiveSelection(-100987654321, 12, 180001))
        ->toBe(['dataset' => 'sales', 'period' => 'week'])
        ->and($state->executiveSelection(-100987654321, 13, 180001))->toBeNull()
        ->and($state->executiveSelection(-100987654321, 12, 180002))->toBeNull();

    expect(app(PrepareDenizExecutiveAnalysis::class)->handle(
        180001, 'سلام', $state->executiveSelection(-100987654321, 12, 180001),
    ))->toBeNull();

    $state->clearHistory(-100987654321, 12, 180001);
    expect($state->executiveSelection(-100987654321, 12, 180001))->toBeNull();

    $state->rememberExecutiveSelection(-100987654321, 12, 180001, 'sales', 'week');
    expect($state->selectModel(-100987654321, 12, 180001, 2))
        ->toBe('gapgpt-deepseek-v4.1-flash')
        ->and($state->executiveSelection(-100987654321, 12, 180001))->toBeNull();

    Http::assertNothingSent();
});

it('does not remember executive follow-ups when Telegram delivery is uncertain', function (): void {
    $this->travelTo(Carbon::parse('2026-11-08 15:30:00', 'Asia/Tehran')->utc());
    $root = executive02Root();
    executive02Sale($root, 600_000);

    Http::fake([
        'https://api.gapgpt.app/*' => Http::response(executive02Response()),
        'https://api.telegram.org/*' => Http::response(['ok' => false], 503),
    ]);

    expect(fn () => executive02Job(223, 'فروش ۲۴ ساعت اخیر چطوره؟'))
        ->toThrow(RuntimeException::class, 'Denix response delivery failed.');

    expect(app(DenixConversationState::class)->executiveSelection(-100987654321, 12, 180001))
        ->toBeNull();
    Http::assertSentCount(2);
});


it('reads registered and active users only from local Identity and settled Sales data, never Remnawave', function (): void {
    $root = executive02Root();
    User::factory()->create(['is_active' => true]);
    $localCount = User::query()->count();

    $prepared = app(PrepareDenizExecutiveAnalysis::class)->handle(180001, 'تعداد کاربران چند نفر است؟');
    expect($prepared['dataset'])->toBe('users')
        ->and($prepared['period'])->toBe('current')
        ->and($prepared['headline'])->toContain('کاربران محلی دناگیت', 'ثبت‌شده: '.$localCount)
        ->and($prepared['prompt'])->toContain('"dataset":"users","period":"current"')
        ->and($prepared['prompt'])->toContain('"registeredUsers":'.$localCount)
        ->and($prepared['prompt'])->toContain('"activeAccounts":2')
        ->and($prepared['prompt'])->not->toContain('Never Export This Name', 'PRIVATEBANKREFERENCE');

    $last30 = app(PrepareDenizExecutiveAnalysis::class)->handle(180001, 'ثبت نام ۳۰ روز اخیر چقدر بوده؟');
    expect($last30['dataset'])->toBe('users');

    foreach (['کاربران آنلاین چقدرند؟', 'تعداد اشتراک فعال چنده؟', 'گزارش کاربران این ماه'] as $question) {
        // Online and RW subscription metrics cannot be inferred from local users.
        if ($question === 'تعداد اشتراک فعال چنده؟') {
            expect(app(PrepareDenizExecutiveAnalysis::class)->handle(180001, $question))->toBeNull();

            continue;
        }

        expect(fn () => app(PrepareDenizExecutiveAnalysis::class)->handle(180001, $question))
            ->toThrow(InvalidArgumentException::class);
    }

    Http::assertNothingSent();
});

it('maps full money received to corrected cash-all report rather than stale accounting context', function (): void {
    $root = executive02Root();
    executive02Sale($root, 2_500_000);

    $prepared = app(PrepareDenizExecutiveAnalysis::class)->handle(
        180001, 'کل ورود پول چقدر بوده؟', ['dataset' => 'accounting', 'period' => 'month'],
    );

    expect($prepared['dataset'])->toBe('cash')
        ->and($prepared['period'])->toBe('all')
        ->and($prepared['prompt'])->toContain('"dataset":"cash","period":"all"')
        ->and($prepared['prompt'])->toContain('"cashInRials":2500000')
        ->and($prepared['headline'])->toContain('ورودی نقدی: 250,000 تومان')
        ->and($prepared['headline'])->not->toContain('سود برآوردی');

    Http::assertNothingSent();
});

it('renders canonical Jalali report boundaries and deterministic insight signals without model conversion', function (): void {
    $root = executive02Root();
    executive02Sale($root, 3_000_000);

    $accounting = app(PrepareDenizExecutiveAnalysis::class)->handle(180001, 'تحلیل مالی ماه جاری');
    expect($accounting['headline'])->toContain('بازه شمسی: ۱۴۰۵/۰۸/۰۱ تا ۱۴۰۵/۰۸/۱۹')
        ->and($accounting['prompt'])->toContain('"windowFa":{"start":"۱۴۰۵/۰۸/۰۱","endInclusive":"۱۴۰۵/۰۸/۱۹"}')
        ->and($accounting['prompt'])->toContain('"recordedExpenseSharePercent":0');

    $sales = app(PrepareDenizExecutiveAnalysis::class)->handle(180001, 'تحلیل فروش هفتگی');
    expect($sales['prompt'])->toContain('"averageOrderRials":3000000')
        ->and($sales['prompt'])->toContain('"renewalSharePercent":0')
        ->and($sales['prompt'])->toContain('"windowFa"')
        ->and(mb_strlen($sales['prompt']))->toBeLessThanOrEqual(2000);

    Http::assertNothingSent();
});

it('keeps the higher token allowance executive-only and does not grant tools or new models', function (): void {
    $executive = new ReflectionClass(\App\Modules\AI\Application\DenizExecutiveAssistant::class);
    $standard = new ReflectionClass(DenixAssistant::class);
    $attribute = \Laravel\Ai\Attributes\MaxTokens::class;

    expect($executive->getAttributes($attribute))->toHaveCount(1)
        ->and($standard->getAttributes($attribute))->toHaveCount(1)
        ->and($executive->getAttributes($attribute)[0]->getArguments()[0])->toBe(1100)
        ->and($standard->getAttributes($attribute)[0]->getArguments()[0])->toBe(700)
        ->and(iterator_to_array((new \App\Modules\AI\Application\DenizExecutiveAssistant)->tools()))->toBeEmpty()
        ->and((new \App\Modules\AI\Application\DenizExecutiveAssistant)->instructions())->toContain('مشاور ارشد مالی');

    Http::assertNothingSent();
});
