<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminNodeBatch;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** NODE-03: small private wizard for bounded, confirmed Node batch actions. */
final readonly class AdminNodeBatchUi
{
    private const FIELDS = [
        'countryCode' => 'کشور (DE)',
        'note' => 'یادداشت',
        'tags' => 'تگ‌ها (با ویرگول)',
        'consumptionMultiplier' => 'ضریب مصرف',
        'nodeConsumptionMultiplier' => 'ضریب مصرف نود',
        'providerUuid' => 'شناسه تأمین‌کننده',
        'activePluginUuid' => 'شناسه افزونه',
        'integrationUuids' => 'شناسه‌های اتصال (با ویرگول)',
    ];

    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminNodes $nodes,
        private ManageAdminNodeBatch $batch,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $nodes = $this->nodes->all($root);
        } catch (Throwable) {
            $this->render($bot, 'دریافت فهرست نودها ممکن نیست.', $this->back());

            return;
        }
        if ($nodes === [] || count($nodes) > 20) {
            $this->render($bot, count($nodes) > 20
                ? 'مدیریت گروهی برای حداکثر ۲۰ نود در یک مرحله در دسترس است.'
                : 'هنوز نودی برای مدیریت وجود ندارد.', $this->back());

            return;
        }

        // Keep only one Node wizard active so free-text replies reach the visible form.
        Cache::forget(TelegramBotContext::current()->cacheKey('node01:draft:'.$root));
        Cache::forget(TelegramBotContext::current()->cacheKey('node02:draft:'.$root));
        $state = ['stage' => 'select', 'nodes' => $nodes, 'selected' => [], 'mode' => '', 'data' => []];
        $this->store($root, $state);
        $this->selection($bot, $state);
    }

    public function toggle(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'select');
        $i = $this->index($index);
        if ($state === null || $i === null || ! isset($state['nodes'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }
        $uuid = $state['nodes'][$i]['uuid'];
        $selected = $state['selected'];
        if (in_array($uuid, $selected, true)) {
            $selected = array_values(array_filter($selected, static fn (string $id): bool => $id !== $uuid));
        } else {
            $selected[] = $uuid;
        }
        $state['selected'] = $selected;
        $this->store($root, $state);
        $this->selection($bot, $state);
    }

    public function choose(Nutgram $bot, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'select');
        if ($state === null || ! in_array($mode, [
            'enable', 'disable', 'restart', 'reset', 'profile', 'update', 'reorder', 'restart-all',
        ], true)) {
            $this->expired($bot);

            return;
        }

        if (! in_array($mode, ['reorder', 'restart-all'], true) && $state['selected'] === []) {
            $this->selection($bot, $state);

            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, 'تغییرات گروهی سرورها فعلاً غیرفعال است.', $this->back());

            return;
        }

        $state['mode'] = $mode;
        if ($mode === 'reorder') {
            $state['stage'] = 'reorder';
            $state['selected'] = array_column($state['nodes'], 'uuid');
            $this->store($root, $state);
            $this->ordering($bot, $state);

            return;
        }
        if ($mode === 'restart-all') {
            $state['selected'] = array_column($state['nodes'], 'uuid');
            $this->confirm($bot, $root, $state);

            return;
        }
        if ($mode === 'profile') {
            try {
                $profiles = $this->nodes->profiles($root);
            } catch (Throwable) {
                $this->render($bot, 'دریافت پروفایل‌ها ممکن نیست.', $this->back());

                return;
            }
            $state['stage'] = 'profile';
            $state['profiles'] = $profiles;
            $this->store($root, $state);
            $keyboard = InlineKeyboardMarkup::make();
            foreach ($profiles as $i => $profile) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    $this->safe($profile['name']), callback_data: 'node3:profile:'.$i,
                ));
            }
            $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel'));
            $this->render($bot, "🔌 انتخاب پروفایل جدید\n\n"
                .($profiles === [] ? 'پروفایلی در دسترس نیست.' : 'پروفایل موردنظر را انتخاب کنید.'), $keyboard);

            return;
        }
        if ($mode === 'update') {
            $state['stage'] = 'field';
            $this->store($root, $state);
            $keyboard = InlineKeyboardMarkup::make();
            foreach (array_keys(self::FIELDS) as $i => $field) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    self::FIELDS[$field], callback_data: 'node3:field:'.$i,
                ));
            }
            $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel'));
            $this->render($bot, "✏️ ویرایش گروهی\n\nیک فیلد را برای تغییر انتخاب کنید.", $keyboard);

            return;
        }

        $this->confirm($bot, $root, $state);
    }

    public function move(Nutgram $bot, string $index, string $direction): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'reorder');
        $i = $this->index($index);
        if ($state === null || $i === null || ! isset($state['selected'][$i]) || ! in_array($direction, ['up', 'down'], true)) {
            $this->expired($bot);

            return;
        }
        $next = $i + ($direction === 'up' ? -1 : 1);
        if (isset($state['selected'][$next])) {
            [$state['selected'][$i], $state['selected'][$next]] = [$state['selected'][$next], $state['selected'][$i]];
            $this->store($root, $state);
        }
        $this->ordering($bot, $state);
    }

    public function profile(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'profile');
        $i = $this->index($index);
        if ($state === null || $i === null || ! isset($state['profiles'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }
        $profile = $state['profiles'][$i];
        try {
            $inbounds = $this->nodes->inbounds($root, $profile['uuid']);
        } catch (Throwable) {
            $this->render($bot, 'دریافت ورودی‌های پروفایل ممکن نیست.', $this->back());

            return;
        }
        $state['stage'] = 'inbounds';
        $state['profile'] = $profile;
        $state['inbounds'] = $inbounds;
        $state['chosen'] = [];
        $this->store($root, $state);
        $this->inbounds($bot, $state);
    }

    public function inbound(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'inbounds');
        $i = $this->index($index);
        if ($state === null || $i === null || ! isset($state['inbounds'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }
        $uuid = $state['inbounds'][$i]['uuid'];
        $chosen = $state['chosen'];
        if (in_array($uuid, $chosen, true)) {
            $chosen = array_values(array_filter($chosen, static fn (string $id): bool => $id !== $uuid));
        } else {
            $chosen[] = $uuid;
        }
        $state['chosen'] = $chosen;
        $this->store($root, $state);
        $this->inbounds($bot, $state);
    }

    public function field(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->state($root, 'field');
        $i = $this->index($index);
        $fields = array_keys(self::FIELDS);
        if ($state === null || $i === null || ! isset($fields[$i])) {
            $this->expired($bot);

            return;
        }
        $state['stage'] = 'value';
        $state['field'] = $fields[$i];
        $this->store($root, $state);
        $this->render($bot, '✏️ '.self::FIELDS[$fields[$i]]."\n\nمقدار جدید را در یک پیام بفرستید."
            ."\nبرای چند مقدار، از ویرگول استفاده کنید.", $this->cancelKeyboard());
    }

    /** Only a pending batch-update text input is consumed. */
    public function text(Nutgram $bot, string $value): bool
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            return false;
        }
        $root = $actor->userId;
        $state = $this->state($root, 'value');
        if ($state === null) {
            return false;
        }
        if (strlen($value) > 500 || preg_match('/[\r\n\x00-\x1F\x7F]/u', $value) === 1) {
            $this->render($bot, 'مقدار نامعتبر است. یک پیام کوتاه بفرستید.', $this->cancelKeyboard());

            return true;
        }
        $field = $state['field'];
        $raw = trim($value);
        $parsed = match ($field) {
            'countryCode' => strtoupper($raw),
            'consumptionMultiplier', 'nodeConsumptionMultiplier' => preg_match('/\A[0-9]{1,3}(?:\.[0-9]{1,4})?\z/D', $raw) === 1 ? (float) $raw : null,
            'tags', 'integrationUuids' => array_map(trim(...), explode(',', $raw)),
            default => $raw,
        };
        if ($parsed === null) {
            $this->render($bot, 'مقدار عددی معتبر نیست.', $this->cancelKeyboard());

            return true;
        }
        $state['data'] = [$field => $parsed];
        $this->confirm($bot, $root, $state);

        return true;
    }

    public function preview(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = Cache::get($this->key($root));
        if (! is_array($state) || ! in_array($state['stage'] ?? '', ['inbounds', 'reorder'], true)) {
            $this->expired($bot);

            return;
        }
        if ($state['stage'] === 'inbounds') {
            if ($state['chosen'] === []) {
                $this->render($bot, 'حداقل یک ورودی انتخاب کنید.', $this->cancelKeyboard());

                return;
            }
            $state['data'] = ['configProfile' => [
                'activeConfigProfileUuid' => $state['profile']['uuid'],
                'activeInbounds' => $state['chosen'],
            ]];
        } elseif ($state['selected'] === array_column($state['nodes'], 'uuid')) {
            $this->render($bot, 'برای ذخیره ترتیب، ابتدا یک نود را جابه‌جا کنید.', $this->cancelKeyboard());

            return;
        }
        $this->confirm($bot, $root, $state);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->expired($bot);

            return;
        }
        $key = $this->key($root);
        $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key, $token): mixed {
            $draft = Cache::get($key);
            if (! is_array($draft) || ($draft['stage'] ?? null) !== 'confirm'
                || ! is_string($draft['token'] ?? null) || ! hash_equals($draft['token'], $token)) {
                return null;
            }
            Cache::forget($key);

            return $draft;
        });
        if (! is_array($state)) {
            $this->expired($bot);

            return;
        }
        try {
            $this->batch->perform($root, $state['mode'], $state['nodes'], $state['selected'], $state['data']);
            $this->render($bot, "✅ درخواست گروهی ثبت شد.\n\nنتیجه نهایی را از فهرست نودها بررسی کنید.", $this->back());
        } catch (\Illuminate\Validation\ValidationException) {
            $this->render($bot, "درخواست اجرا نشد؛ سرورهای انتخاب‌شده یا تنظیمات تغییر کرده‌اند.\n\nفهرست را تازه‌سازی کنید.", $this->back());
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $this->render($bot, "عملیات دیگری روی سرورها در حال انجام است.\n\nابتدا وضعیت سرورها را بررسی کنید.", $this->back());
        } catch (Throwable) {
            $this->render($bot, "نتیجه عملیات قطعی نیست.\n\nابتدا وضعیت سرورها را تازه‌سازی کنید؛ درخواست خودکار تکرار نمی‌شود.", $this->back());
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root));
        app(AdminNodesUi::class)->browse($bot);
    }

    /** @param array<string,mixed> $state */
    private function selection(Nutgram $bot, array $state): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($state['nodes'] as $i => $node) {
            $checked = in_array($node['uuid'], $state['selected'], true) ? '✅' : '▫️';
            $keyboard->addRow(InlineKeyboardButton::make(
                $checked.' '.$this->safe($node['name']), callback_data: 'node3:toggle:'.$i,
            ));
        }
        if ($state['selected'] !== []) {
            $keyboard->addRow(
                InlineKeyboardButton::make('▶️ فعال‌سازی', callback_data: 'node3:choose:enable'),
                InlineKeyboardButton::make('⏸️ غیرفعال‌سازی', callback_data: 'node3:choose:disable'),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('🔄 ری‌استارت', callback_data: 'node3:choose:restart'),
                InlineKeyboardButton::make('📊 صفرکردن ترافیک', callback_data: 'node3:choose:reset'),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('🔌 تغییر پروفایل', callback_data: 'node3:choose:profile'),
                InlineKeyboardButton::make('✏️ ویرایش گروهی', callback_data: 'node3:choose:update'),
            );
        }
        $keyboard->addRow(
            InlineKeyboardButton::make('↕️ تغییر ترتیب', callback_data: 'node3:choose:reorder'),
            InlineKeyboardButton::make('⚠️ ری‌استارت همه', callback_data: 'node3:choose:restart-all'),
        );
        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'node3:cancel'));
        $this->render($bot, '⚙️ عملیات گروهی نودها'."\n\n".'انتخاب‌شده: '.count($state['selected'])
            ."\nیک یا چند نود را انتخاب کنید؛ برای تغییر ترتیب یا ری‌استارت همه، انتخاب لازم نیست.", $keyboard);
    }

    /** @param array<string,mixed> $state */
    private function ordering(Nutgram $bot, array $state): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        $names = array_column($state['nodes'], 'name', 'uuid');
        $lines = ["↕️ ترتیب نمایش سرورها", '', 'با دکمه‌های شماره‌دار، جای سرورها را تغییر دهید.', ''];
        foreach ($state['selected'] as $i => $uuid) {
            $lines[] = ($i + 1).' · '.$this->safe($names[$uuid]);
            $buttons = [];
            if ($i > 0) {
                $buttons[] = InlineKeyboardButton::make('⬆️ '.($i + 1), callback_data: 'node3:move:'.$i.':up');
            }
            if ($i < count($state['selected']) - 1) {
                $buttons[] = InlineKeyboardButton::make('⬇️ '.($i + 1), callback_data: 'node3:move:'.$i.':down');
            }
            if ($buttons !== []) {
                $keyboard->addRow(...$buttons);
            }
        }
        $keyboard->addRow(InlineKeyboardButton::make('✅ بررسی ترتیب', callback_data: 'node3:preview'));
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    /** @param array<string,mixed> $state */
    private function inbounds(Nutgram $bot, array $state): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($state['inbounds'] as $i => $inbound) {
            $keyboard->addRow(InlineKeyboardButton::make(
                (in_array($inbound['uuid'], $state['chosen'], true) ? '✅ ' : '▫️ ').$this->safe($inbound['name']),
                callback_data: 'node3:inbound:'.$i,
            ));
        }
        if ($state['chosen'] !== []) {
            $keyboard->addRow(InlineKeyboardButton::make('✅ بررسی پروفایل', callback_data: 'node3:preview'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel'));
        $this->render($bot, "🔌 ورودی‌های پروفایل\n\nحداقل یک ورودی انتخاب کنید.", $keyboard);
    }

    /** @param array<string,mixed> $state */
    private function confirm(Nutgram $bot, int $root, array $state): void
    {
        $labels = [
            'enable' => 'فعال‌سازی', 'disable' => 'غیرفعال‌سازی', 'restart' => 'ری‌استارت',
            'reset' => 'صفرکردن ترافیک', 'profile' => 'تغییر پروفایل',
            'update' => 'ویرایش گروهی', 'reorder' => 'تغییر ترتیب', 'restart-all' => 'ری‌استارت تمام نودها',
        ];
        $names = array_column($state['nodes'], 'name', 'uuid');
        $targetLines = [];
        foreach ($state['selected'] as $index => $uuid) {
            $targetLines[] = ($index + 1).'. '.$this->safe($names[$uuid]);
        }
        $details = '';
        if ($state['mode'] === 'profile') {
            $details = "\nپروفایل جدید: ".$this->safe($state['profile']['name'])
                ."\nتعداد ورودی‌ها: ".count($state['chosen']);
        } elseif ($state['mode'] === 'update') {
            $field = array_key_first($state['data']);
            $value = $state['data'][$field];
            $display = is_array($value) ? implode(', ', $value) : (string) $value;
            $display = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $display);
            $details = "\nفیلد: ".self::FIELDS[$field]."\nمقدار جدید: ".$display;
        }
        $warning = match ($state['mode']) {
            'restart', 'restart-all', 'disable' => '⚠️ ممکن است اتصال کاربران قطع شود.',
            'reset' => '⚠️ شمارنده مصرف ترافیک سرورهای انتخاب‌شده صفر می‌شود.',
            default => 'نتیجه تغییرات را پس از ثبت بررسی کنید.',
        };
        $state['stage'] = 'confirm';
        $state['token'] = bin2hex(random_bytes(16));
        $this->store($root, $state);
        $this->render($bot, "⚠️ تأیید عملیات گروهی\n\n".$labels[$state['mode']]
            ."\nتعداد سرورها: ".count($state['selected'])
            ."\n\nسرورهای هدف".($state['mode'] === 'reorder' ? ' به ترتیب جدید' : '').":\n".implode("\n", $targetLines)
            .$details."\n\n".$warning."\nاعتبار تأیید: ۳ دقیقه.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'node3:apply:'.$state['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel')),
        );
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    /** @return array<string,mixed>|null */
    private function state(int $root, string $stage): ?array
    {
        $state = Cache::get($this->key($root));

        return is_array($state) && ($state['stage'] ?? null) === $stage ? $state : null;
    }

    /** @param array<string,mixed> $state */
    private function store(int $root, array $state): void
    {
        Cache::put($this->key($root), $state, now()->addMinutes(3));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('node03:draft:'.$root);
    }

    private function index(string $raw): ?int
    {
        return ctype_digit($raw) && strlen($raw) < 3 && (int) $raw < 30 ? (int) $raw : null;
    }

    private function safe(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 50);
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->back());
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node3:cancel'));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'node1:list:1'));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }
        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
