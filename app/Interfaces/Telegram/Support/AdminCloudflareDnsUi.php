<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageCloudflareDns;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** CF-02: minimal private Super Admin menu; every operation uses the CF-01 Action. */
final readonly class AdminCloudflareDnsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageCloudflareDns $dns,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $this->forgetForm($id);
        try {
            $zones = $this->dns->zones($id);
            Cache::put($this->key($id, 'zones'), $zones, now()->addMinutes(10));
            $buttons = InlineKeyboardMarkup::make();
            foreach ($zones as $index => $zone) {
                $buttons->addRow(InlineKeyboardButton::make('🌐 '.$zone['name'],
                    callback_data: 'cf2:zone:'.$index, style: ButtonStyle::PRIMARY));
            }
            $buttons->addRow(InlineKeyboardButton::make('◀️ مدیریت سرورها', callback_data: 'menu:admin:servers'));
            $this->show($bot, $zones === [] ? 'دامنه مجازی یافت نشد. اتصال و دسترسی دامنه‌ها را بررسی کنید.'
                : "🌐 مدیریت DNS\n\nدامنه موردنظر را انتخاب کنید.", $buttons);
        } catch (Throwable) {
            $this->show($bot, 'دریافت دامنه‌ها ممکن نیست؛ تنظیمات اتصال را بررسی کنید.', $this->home());
        }
    }

    public function selectZone(Nutgram $bot, string $choice): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $zones = Cache::get($this->key($id, 'zones'));
        $index = ctype_digit($choice) && strlen($choice) <= 2 ? (int) $choice : -1;
        if (! is_array($zones) || ! is_array($zones[$index] ?? null)) {
            $this->expired($bot);

            return;
        }
        Cache::put($this->key($id, 'zone'), $zones[$index], now()->addMinutes(10));
        $this->records($bot, '1');
    }

    public function records(Nutgram $bot, string $requested): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $zone = $this->zone($id);
        $page = ctype_digit($requested) && strlen($requested) <= 3 ? (int) $requested : 0;
        if ($zone === null || $page < 1 || $page > 100) {
            $this->expired($bot);

            return;
        }
        $this->forgetForm($id);
        try {
            $result = $this->dns->page($id, $zone['id'], $zone['name'], $page);
            if ($page > $result['pages']) {
                throw new \RuntimeException('Invalid page.');
            }
            $token = bin2hex(random_bytes(4));
            Cache::put($this->key($id, 'page'), [
                'items' => $result['items'], 'token' => $token, 'page' => $page,
            ], now()->addMinutes(10));
            $buttons = InlineKeyboardMarkup::make();
            foreach ($result['items'] as $index => $record) {
                $buttons->addRow(InlineKeyboardButton::make(
                    ($record['type'] === 'A' ? '🌐 ' : '📄 ').$record['name'].' · '.$record['type'],
                    callback_data: 'cf2:view:'.$index.':'.$token,
                ));
            }
            $nav = [];
            if ($page > 1) {
                $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'cf2:list:'.($page - 1));
            }
            if ($page < $result['pages']) {
                $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'cf2:list:'.($page + 1));
            }
            if ($nav !== []) {
                $buttons->addRow(...$nav);
            }
            if ($this->dns->wizardEnabled()) {
                $buttons->addRow(InlineKeyboardButton::make('➕ افزودن رکورد A',
                    callback_data: 'cf2:create', style: ButtonStyle::PRIMARY));
            }
            $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'cf2:list:'.$page));
            $buttons->addRow(InlineKeyboardButton::make('◀️ دامنه‌ها', callback_data: 'cf2:zones'));
            $this->show($bot, '🌐 رکوردهای '.$zone['name']."\n\nصفحه {$page} از ".$result['pages']."\n"
                .($result['items'] === [] ? "\nرکوردی وجود ندارد." : "\nرکورد موردنظر را انتخاب کنید.")
                .($this->dns->wizardEnabled() ? '' : "\n\n🔒 تغییرات غیرفعال است."), $buttons);
        } catch (Throwable) {
            $this->show($bot, 'فهرست رکوردها در دسترس نیست؛ دوباره تلاش کنید.', $this->listBack());
        }
    }

    public function detail(Nutgram $bot, string $index, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $zone = $this->zone($id);
        $list = Cache::get($this->key($id, 'page'));
        $i = ctype_digit($index) && strlen($index) <= 2 ? (int) $index : -1;
        if ($zone === null || ! is_array($list) || ! is_string($list['token'] ?? null)
            || ! hash_equals($list['token'], $token) || ! is_array($list['items'][$i] ?? null)) {
            $this->expired($bot);

            return;
        }
        try {
            $row = $this->dns->one($id, $zone['id'], $zone['name'], $list['items'][$i]['id']);
            $token = bin2hex(random_bytes(4));
            Cache::put($this->key($id, 'selected'), ['token' => $token, 'row' => $row, 'zoneId' => $zone['id']], now()->addMinutes(10));
            $buttons = InlineKeyboardMarkup::make();
            if ($row['editable'] && $this->dns->wizardEnabled()) {
                $buttons->addRow(InlineKeyboardButton::make('✏️ تغییر IP',
                    callback_data: 'cf2:edit:ip:'.$token, style: ButtonStyle::PRIMARY));
                $buttons->addRow(InlineKeyboardButton::make($row['proxied'] ? '⚪ DNS Only' : '🟠 فعال‌کردن Proxy',
                    callback_data: 'cf2:edit:proxy:'.$token));
            }
            $buttons->addRow(InlineKeyboardButton::make('◀️ رکوردها', callback_data: 'cf2:list:'.$list['page']));
            $this->show($bot, "🌐 جزئیات رکورد\n\n".'نام: '.TelegramUi::code($row['name'])
                ."\nنوع: ".TelegramUi::code($row['type'])
                .($row['type'] === 'A'
                    ? "\nIP: ".TelegramUi::code($row['content'])
                    ."\nوضعیت: ".($row['proxied'] ? '🟠 Proxied' : '⚪ DNS Only')
                    : "\nمقدار این نوع رکورد در بات نمایش داده نمی‌شود.")
                .($row['editable'] ? '' : "\n\nاین رکورد فقط قابل مشاهده است."), $buttons, true);
        } catch (Throwable) {
            $this->show($bot, 'دریافت مشخصات رکورد ممکن نیست.', $this->listBack());
        }
    }

    public function create(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $zone = $this->zone($id);
        if ($zone === null || ! $this->dns->wizardEnabled() || $this->conflict($id)) {
            $this->show($bot, 'ابتدا فرم قبلی را ببندید یا دسترسی DNS را بررسی کنید.', $this->listBack());

            return;
        }
        $this->save($id, ['mode' => 'create', 'step' => 'name', 'zone' => $zone]);
        $example = 'usa.'.$zone['name'];
        $this->show($bot, "➕ افزودن رکورد A\n\nزیردامنه را وارد کنید:\n".TelegramUi::code($example)
            ."\n\nحالت پیش‌فرض: DNS Only.",
            InlineKeyboardMarkup::make()->addRow(TelegramUi::copy($example))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cf2:cancel')), true);
    }

    public function edit(Nutgram $bot, string $mode, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $zone = $this->zone($id);
        $selected = Cache::get($this->key($id, 'selected'));
        if ($zone === null || ! $this->dns->wizardEnabled() || $this->conflict($id)
            || ! is_array($selected) || ($selected['zoneId'] ?? null) !== $zone['id']
            || ! is_string($selected['token'] ?? null)
            || ! hash_equals($selected['token'], $token) || ! in_array($mode, ['ip', 'proxy'], true)) {
            $this->expired($bot);

            return;
        }
        try {
            $old = $selected['row'];
            $fresh = $this->dns->one($id, $zone['id'], $zone['name'], $old['id']);
            if ($old !== $fresh || $fresh['editable'] !== true) {
                throw new \RuntimeException('Stale DNS record.');
            }
            $state = ['mode' => 'edit', 'zone' => $zone, 'old' => $old];
            if ($mode === 'ip') {
                $state['step'] = 'ip';
                $this->save($id, $state);
                $this->show($bot, "✏️ تغییر IP\n\nIPv4 عمومی جدید را ارسال کنید.\nمقدار فعلی: "
                    .TelegramUi::code($old['content']),
                    InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cf2:cancel')),
                    true);

                return;
            }
            if (! $old['proxied'] && ! $old['proxiable']) {
                throw new \RuntimeException('Proxy unsupported.');
            }
            $this->confirm($bot, $id, [...$state, 'ip' => $old['content'], 'proxied' => ! $old['proxied']]);
        } catch (Throwable) {
            $this->show($bot, 'رکورد تغییر کرده یا قابل ویرایش نیست. فهرست را تازه‌سازی کنید.', $this->listBack());
        }
    }

    public function text(Nutgram $bot, string $input): bool
    {
        $id = $this->rootSilent($bot);
        if ($id === null) {
            return false;
        }
        $state = Cache::get($this->key($id, 'form'));
        if (! is_array($state) || ! in_array($state['step'] ?? null, ['name', 'ip'], true)) {
            return false;
        }
        if ($this->conflict($id)) {
            $this->forgetForm($id);

            return false;
        }
        if (! $this->dns->wizardEnabled()) {
            $this->forgetForm($id);
            $this->show($bot, 'تغییرات DNS غیرفعال شده است.', $this->listBack());

            return true;
        }
        $zone = $state['zone'];
        try {
            if ($state['step'] === 'name') {
                $state['name'] = $this->dns->hostname($id, $zone['name'], $input);
                $state['step'] = 'ip';
                $this->save($id, $state);
                $this->show($bot, "🌐 IP سرور\n\nIPv4 عمومی را وارد کنید.",
                    InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cf2:cancel')));

                return true;
            }
            $ip = $this->dns->validatePublicIp($id, $input);
            if ($state['mode'] === 'create') {
                if ($this->dns->inspectA($id, $zone['id'], $zone['name'], $state['name'], $ip) !== 'missing') {
                    throw new \RuntimeException('Existing A record.');
                }
            } elseif ($state['old']['content'] === $ip) {
                throw new \RuntimeException('No changes.');
            }
            $this->confirm($bot, $id, [...$state, 'ip' => $ip,
                'proxied' => $state['mode'] === 'create' ? false : $state['old']['proxied']]);
        } catch (Throwable) {
            $this->show($bot, 'مقدار معتبر نیست یا این رکورد وجود دارد. لطفاً دوباره وارد کنید.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cf2:cancel')));
        }

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->expired($bot);

            return;
        }
        try {
            $key = $this->key($id, 'form');
            $state = Cache::lock($key.':lock', 10)->block(1, static function () use ($key, $token): ?array {
                $state = Cache::get($key);
                if (! is_array($state) || ($state['step'] ?? null) !== 'confirm'
                    || ! is_string($state['token'] ?? null) || ! hash_equals($state['token'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $state;
            });
            if ($state === null) {
                $this->expired($bot);

                return;
            }
            $zone = $state['zone'];
            if ($state['mode'] === 'create') {
                $this->dns->createManagedA($id, $zone['id'], $zone['name'], $state['name'], $state['ip']);
            } else {
                $this->dns->changeA($id, $zone['id'], $zone['name'], $state['old'], $state['ip'], $state['proxied']);
            }
            $this->show($bot, '✅ نتیجه تغییر DNS تأیید شد. فهرست را تازه‌سازی کنید.', $this->listBack());
        } catch (Throwable) {
            $this->show($bot, 'نتیجه عملیات مشخص نیست یا رکورد تغییر کرده است. درخواست تکرار نشد؛ ابتدا رکوردها را بررسی کنید.',
                $this->listBack());
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $this->forgetForm($id);
        $this->show($bot, 'فرم DNS لغو شد.', $this->listBack());
    }

    private function confirm(Nutgram $bot, int $id, array $state): void
    {
        $state['step'] = 'confirm';
        $state['token'] = bin2hex(random_bytes(16));
        $this->save($id, $state);
        $name = $state['mode'] === 'create' ? $state['name'] : $state['old']['name'];
        $before = $state['mode'] === 'create' ? 'رکورد جدید' : $state['old']['content'];
        $warning = $state['proxied'] ? '⚠️ Proxy ممکن است اتصال مستقیم کاربران را قطع کند.' : 'اتصال مستقیم با DNS Only انجام می‌شود.';
        $this->show($bot, "🌐 تأیید تغییر DNS\n\nدامنه: ".TelegramUi::code($name)
            ."\nIP فعلی: ".TelegramUi::code($before)
            ."\nIP جدید: ".TelegramUi::code($state['ip'])
            ."\nحالت جدید: ".($state['proxied'] ? '🟠 Proxied' : '⚪ DNS Only')
            ."\n\n".$warning."\nاین درخواست فقط یک‌بار اجرا می‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'cf2:apply:'.$state['token'], style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cf2:cancel')), true);
    }

    private function save(int $id, array $state): void
    {
        Cache::put($this->key($id, 'form'), $state, now()->addMinutes(10));
    }

    private function zone(int $id): ?array
    {
        $data = Cache::get($this->key($id, 'zone'));

        return is_array($data) && is_string($data['id'] ?? null) && is_string($data['name'] ?? null) ? $data : null;
    }

    private function conflict(int $id): bool
    {
        if (Cache::has('admin:accounting:draft:'.$id)) {
            return true;
        }
        foreach (['nw01:draft:', 'node01:draft:', 'node02:draft:', 'node03:draft:',
            'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:',
            'telegram:finance:wallet:', 'telegram:bankreview:reference:'] as $prefix) {
            if (Cache::has(TelegramBotContext::current()->cacheKey($prefix.$id))) {
                return true;
            }
        }

        return false;
    }

    private function forgetForm(int $id): void
    {
        Cache::forget($this->key($id, 'form'));
    }

    private function key(int $id, string $part): string
    {
        return TelegramBotContext::current()->cacheKey('cf02:'.$part.':'.$id);
    }

    private function rootSilent(Nutgram $bot): ?int
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);

        return $actor?->canManageRoles() === true ? $actor->userId : null;
    }

    private function root(Nutgram $bot): ?int
    {
        $id = $this->rootSilent($bot);
        if ($id === null && $this->private->id($bot) !== null) {
            $this->menus->denyAccess($bot);
        }

        return $id;
    }

    private function listBack(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ فهرست رکوردها', callback_data: 'cf2:list:1'));
    }

    private function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ مدیریت سرورها', callback_data: 'menu:admin:servers'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->show($bot, 'این مرحله منقضی شده است. فهرست را تازه‌سازی کنید.', $this->listBack());
    }

    private function show(Nutgram $bot, string $message, InlineKeyboardMarkup $buttons, bool $html = false): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $buttons, $html ? 'HTML' : null);

            return;
        }
        $bot->sendMessage(text: $message, reply_markup: $buttons, parse_mode: $html ? 'HTML' : null);
    }
}
