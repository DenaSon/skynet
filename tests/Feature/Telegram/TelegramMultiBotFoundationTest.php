<?php

use App\Interfaces\Telegram\Processing\ClaimState;
use App\Interfaces\Telegram\Processing\ProcessSalesTelegramUpdate;
use App\Interfaces\Telegram\Processing\ProcessTelegramUpdate;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Processing\TelegramUpdateReceipts;
use App\Interfaces\Telegram\Support\PlanAdminDrafts;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Identity\Infrastructure\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('telegram.webhook_secret', 'secret_admin_1234');
    config()->set('telegram.sales.enabled', false);
    config()->set('telegram.sales.token', '12345678:sales-secret');
    config()->set('telegram.sales.webhook_secret', 'secret_sales_5678');
    config()->set('nutgram.token', '87654321:admin-secret');
});

function adminHeaders(): array
{
    return ['X-Telegram-Bot-Api-Secret-Token' => 'secret_admin_1234'];
}

function salesHeaders(): array
{
    return ['X-Telegram-Bot-Api-Secret-Token' => 'secret_sales_5678'];
}

it('keeps sales disabled and does not record updates or linked chats by default', function (): void {
    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 500], salesHeaders())
        ->assertServiceUnavailable();

    expect(DB::table('telegram_updates')->count())->toBe(0)
        ->and(DB::table('telegram_bot_contacts')->count())->toBe(0);
});

it('rejects reused sales token and webhook secret even when enabled', function (): void {
    config()->set('telegram.sales.enabled', true);
    config()->set('telegram.sales.token', config('nutgram.token'));

    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 501], salesHeaders())
        ->assertServiceUnavailable();

    config()->set('telegram.sales.token', '12345678:sales-secret');
    config()->set('telegram.sales.webhook_secret', config('telegram.webhook_secret'));

    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 501], adminHeaders())
        ->assertServiceUnavailable();

    expect(DB::table('telegram_updates')->count())->toBe(0);
});

it('authenticates admin and sales independently before claiming updates', function (): void {
    config()->set('telegram.sales.enabled', true);

    $this->postJson('/api/telegram/admin/webhook', ['update_id' => 502], salesHeaders())->assertForbidden();
    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 502], adminHeaders())->assertForbidden();
    $this->postJson('/api/telegram/webhook', ['update_id' => 502])->assertForbidden();
    expect(DB::table('telegram_updates')->count())->toBe(0);
});

it('shares admin receipts across legacy and new admin URLs while isolating sales IDs', function (): void {
    config()->set('telegram.sales.enabled', true);

    $admin = new class implements ProcessTelegramUpdate
    {
        public int $count = 0;

        public array $contexts = [];

        public function handle(): void
        {
            $this->count++;
            $this->contexts[] = TelegramBotContext::current()->key;
        }
    };
    $sales = new class implements ProcessSalesTelegramUpdate
    {
        public int $count = 0;

        public array $contexts = [];

        public function handle(): void
        {
            $this->count++;
            $this->contexts[] = TelegramBotContext::current()->key;
        }
    };

    $this->app->instance(ProcessTelegramUpdate::class, $admin);
    $this->app->instance(ProcessSalesTelegramUpdate::class, $sales);

    $this->postJson('/api/telegram/webhook', ['update_id' => 503], adminHeaders())->assertNoContent();
    $this->postJson('/api/telegram/admin/webhook', ['update_id' => 503], adminHeaders())->assertNoContent();
    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 503], salesHeaders())->assertNoContent();
    $this->postJson('/api/telegram/sales/webhook', ['update_id' => 503], salesHeaders())->assertNoContent();

    expect($admin->count)->toBe(1)
        ->and($sales->count)->toBe(1)
        ->and($admin->contexts)->toBe(['admin'])
        ->and($sales->contexts)->toBe(['sales'])
        ->and(DB::table('telegram_updates')->where('update_id', 503)->count())->toBe(2)
        ->and(DB::table('telegram_updates')->where('bot_key', 'admin')->value('status'))->toBe('processed')
        ->and(DB::table('telegram_updates')->where('bot_key', 'sales')->value('status'))->toBe('processed');
});

it('isolates retry leases and failed processing by bot', function (): void {
    $receipts = app(TelegramUpdateReceipts::class);

    expect($receipts->claim(504, 'admin'))->toBe(ClaimState::Claimed)
        ->and($receipts->claim(504, 'admin'))->toBe(ClaimState::Busy)
        ->and($receipts->claim(504, 'sales'))->toBe(ClaimState::Claimed);

    $receipts->failed(504, 'sales');
    $receipts->processed(504, 'admin');

    expect($receipts->claim(504, 'sales'))->toBe(ClaimState::Claimed)
        ->and($receipts->claim(504, 'admin'))->toBe(ClaimState::Processed);

    expect(fn () => $receipts->claim(504, 'unknown'))->toThrow(InvalidArgumentException::class);
});

it('keeps existing admin-form cache keys and isolates equivalent sales state', function (): void {
    $admin = new TelegramBotContext('admin');
    $sales = new TelegramBotContext('sales');

    expect($admin->cacheKey('telegram:planadmin:draft:7'))->toBe('telegram:planadmin:draft:7')
        ->and($sales->cacheKey('telegram:planadmin:draft:7'))->toBe('telegram:sales:telegram:planadmin:draft:7');

    $state = ['type' => 'edit', 'plan_id' => 1, 'plan_revision' => 1, 'price_revision' => 1, 'form' => 'x', 'stage' => 'editing', 'token' => 'abc'];
    app(PlanAdminDrafts::class)->store(7, $state);
    expect(app(PlanAdminDrafts::class)->peek(7))->toBe($state);
});

it('creates bot contacts only for active linked private-chat users and keeps them per bot', function (): void {
    config()->set('telegram.sales.enabled', true);

    $actor = User::factory()->create();
    TelegramAccount::query()->create(['user_id' => $actor->id, 'telegram_user_id' => 50505]);

    $admin = new class implements ProcessTelegramUpdate
    {
        public function handle(): void {}
    };
    $sales = new class implements ProcessSalesTelegramUpdate
    {
        public function handle(): void {}
    };
    $this->app->instance(ProcessTelegramUpdate::class, $admin);
    $this->app->instance(ProcessSalesTelegramUpdate::class, $sales);

    $payload = ['update_id' => 505, 'message' => [
        'message_id' => 1, 'from' => ['id' => 50505],
        'chat' => ['id' => 50505, 'type' => 'private'], 'text' => '/start',
    ]];

    $this->postJson('/api/telegram/webhook', $payload, adminHeaders())->assertNoContent();
    $this->postJson('/api/telegram/sales/webhook', $payload, salesHeaders())->assertNoContent();

    expect(DB::table('telegram_bot_contacts')->where('user_id', $actor->id)->count())->toBe(2)
        ->and(DB::table('telegram_bot_contacts')->where('bot_key', 'sales')->value('chat_id'))->toBe(50505);
});

it('does not treat groups, spoofed private chat IDs, or unlinked actors as notification contacts', function (): void {
    $admin = new class implements ProcessTelegramUpdate
    {
        public function handle(): void {}
    };
    $this->app->instance(ProcessTelegramUpdate::class, $admin);

    foreach ([
        ['id' => -700, 'type' => 'group'],
        ['id' => 60002, 'type' => 'private'],
        ['id' => 60001, 'type' => 'private'],
    ] as $index => $chat) {
        $this->postJson('/api/telegram/webhook', [
            'update_id' => 510 + $index,
            'message' => ['from' => ['id' => 60001], 'chat' => $chat, 'text' => '/start'],
        ], adminHeaders())->assertNoContent();
    }

    expect(DB::table('telegram_bot_contacts')->count())->toBe(0);
});

it('rejects malformed IDs for each enabled webhook and preserves historical rows by schema', function (): void {
    config()->set('telegram.sales.enabled', true);
    foreach (['/api/telegram/admin/webhook' => adminHeaders(), '/api/telegram/sales/webhook' => salesHeaders()] as $url => $headers) {
        $this->postJson($url, ['update_id' => '6'], $headers)->assertUnprocessable();
        $this->postJson($url, ['update_id' => -6], $headers)->assertUnprocessable();
    }

    expect(Schema::hasColumn('telegram_updates', 'bot_key'))->toBeTrue()
        ->and(Schema::hasTable('telegram_bot_contacts'))->toBeTrue()
        ->and(DB::table('telegram_updates')->count())->toBe(0);
});

it('does not register a Sales webhook while disabled', function (): void {
    $this->artisan('telegram:webhook:set', ['--bot' => 'sales'])->assertFailed();
});

it('requires explicit bot selection for webhook registration', function (): void {
    $this->artisan('telegram:webhook:set', ['--bot' => 'untrusted'])->assertFailed();
});
