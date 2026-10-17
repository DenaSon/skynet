<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** NODE-01: private Super Admin Node read and one-time guided CREATE. */
final readonly class AdminNodesUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminNodes $nodes,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $requested = '1'): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $page = ctype_digit($requested) && strlen($requested) <= 3 ? (int) $requested : 0;
        if ($page < 1 || $page > 100) {
            $this->render($bot, 'صفحه معتبر نیست.', $this->home());

            return;
        }

        try {
            $nodes = $this->nodes->all($root);
        } catch (Throwable) {
            $this->render($bot, '🖥️ مدیریت نودها'."\n\n".'دریافت فهرست ممکن نیست.', $this->home());

            return;
        }

        $pages = max(1, (int) ceil(count($nodes) / 10));
        $page = min($page, $pages);
        $keyboard = InlineKeyboardMarkup::make();
        $enabled = count(array_filter($nodes, static fn (array $node): bool => ! $node['disabled']));
        $online = count(array_filter($nodes, static fn (array $node): bool => ! $node['disabled'] && $node['connected']));
        $lines = ['🖥️ مدیریت نودها',
            'کل: '.count($nodes).' | فعال: '.$enabled.' | متصل: '.$online,
            "صفحه {$page} از {$pages}", '',
            $nodes === [] ? 'هنوز سروری اضافه نشده است.' : 'برای مشاهده جزئیات، یک سرور را انتخاب کنید.',
        ];
        foreach (array_slice($nodes, ($page - 1) * 10, 10) as $node) {
            $status = $node['disabled'] ? '⏸️' : ($node['connected'] ? '🟢' : '🔴');
            $keyboard->addRow(InlineKeyboardButton::make(
                $status.' '.$this->safe($node['name']), callback_data: 'node1:view:'.$node['uuid'],
            ));
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'node1:list:'.($page - 1));
        }
        if ($page < $pages) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'node1:list:'.($page + 1));
        }
        if ($nav !== []) {
            $keyboard->addRow(...$nav);
        }
        $keyboard->addRow(InlineKeyboardButton::make('➕ افزودن نود', callback_data: 'node1:begin'));
        $keyboard->addRow(InlineKeyboardButton::make('🏷️ تگ‌ها', callback_data: 'node1:tags'));
        $keyboard->addRow(InlineKeyboardButton::make('⚙️ عملیات گروهی', callback_data: 'node3:list'));
        $keyboard->addRow(InlineKeyboardButton::make('🌐 مدیریت هاست‌ها', callback_data: 'host1:list:1'));
        $keyboard->addRow(InlineKeyboardButton::make('🧩 پروفایل‌های اتصال', callback_data: 'cfg1:list:1'));
        $keyboard->addRow(InlineKeyboardButton::make('📊 مانیتورینگ', callback_data: 'metrics:refresh'));
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'node1:list:'.$page));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function detail(Nutgram $bot, string $uuid): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $node = $this->nodes->one($root, $uuid);
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات این نود در دسترس نیست.', $this->listKeyboard());

            return;
        }
        $status = $node['disabled'] ? '⏸️ غیرفعال' : '🟢 فعال';
        $connected = $node['connected'] ? '🟢 متصل' : '🔴 قطع';
        $keyboard = InlineKeyboardMarkup::make();
        $keyboard->addRow(InlineKeyboardButton::make('✏️ ویرایش مشخصات', callback_data: 'node2:prepare:'.$node['uuid'].':edit'));
        $keyboard->addRow(InlineKeyboardButton::make(
            $node['disabled'] ? '▶️ فعال‌سازی' : '⏸️ غیرفعال‌سازی',
            callback_data: 'node2:prepare:'.$node['uuid'].($node['disabled'] ? ':enable' : ':disable'),
        ));
        $keyboard->addRow(
            InlineKeyboardButton::make('🔄 راه‌اندازی مجدد', callback_data: 'node2:prepare:'.$node['uuid'].':restart'),
            InlineKeyboardButton::make('📊 صفرکردن ترافیک', callback_data: 'node2:prepare:'.$node['uuid'].':reset'),
        );
        if ($node['disabled']) {
            $keyboard->addRow(InlineKeyboardButton::make('🗑️ حذف سرور', callback_data: 'node2:prepare:'.$node['uuid'].':delete'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'node1:view:'.$node['uuid']));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'node1:list:1'));
        $this->render($bot,
            '🖥️ جزئیات سرور'."\n\n"
            .'نام: '.$this->safe($node['name'])."\n"
            .'وضعیت: '.$status."\n"
            .'اتصال: '.$connected,
            $keyboard,
        );
    }

    public function tags(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $tags = $this->nodes->tags($root);
            $text = "🏷️ تگ‌های نودها\n\n".($tags === []
                ? 'تگی ثبت نشده است.'
                : implode("\n", array_map(fn (string $tag): string => '• '.$this->safe($tag), $tags)));
        } catch (Throwable) {
            $text = 'دریافت تگ‌ها ممکن نیست.';
        }
        $this->render($bot, $text, $this->listKeyboard());
    }

    public function begin(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, "➕ افزودن نود\n\nافزودن سرور فعلاً غیرفعال است.", $this->listKeyboard());

            return;
        }
        // A single pending Node text wizard per operator avoids misrouting input.
        Cache::forget(TelegramBotContext::current()->cacheKey('host01:draft:'.$root));
        Cache::forget(TelegramBotContext::current()->cacheKey('node02:draft:'.$root));
        Cache::forget(TelegramBotContext::current()->cacheKey('node03:draft:'.$root));
        $this->save($root, ['stage' => 'info']);
        $this->render($bot,
            "➕ افزودن نود — گام ۱ از ۳\n\nنام و آدرس سرور را در یک پیام بفرستید:\n\n"
            ."Germany-01 | node.example.com\n\nآدرس باید IP یا نام دامنه باشد؛ بدون http و پورت.",
            $this->cancelKeyboard());
    }

    /** Consume only a pending NODE-01 name/address input; preserve all original text handlers otherwise. */
    public function text(Nutgram $bot, string $input): bool
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
        $state = $this->draft($root);
        if ($state === null || ($state['stage'] ?? null) !== 'info') {
            return false;
        }

        $parts = explode('|', $input);
        if (count($parts) !== 2 || strlen($input) > 330) {
            $this->render($bot, 'نام و آدرس را با یک | جدا کنید. نمونه: Germany-01 | node.example.com', $this->cancelKeyboard());

            return true;
        }
        $name = trim($parts[0]);
        $address = trim($parts[1]);
        try {
            $this->nodes->validateIdentity($root, $name, $address);
            $profiles = $this->nodes->profiles($root);
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات معتبر نیست یا دریافت پروفایل‌ها ممکن نشد. دوباره بررسی کنید.', $this->cancelKeyboard());

            return true;
        }
        $this->save($root, ['stage' => 'profile', 'name' => $name, 'address' => $address, 'profiles' => $profiles]);
        $this->showProfiles($bot, $profiles);

        return true;
    }

    public function profile(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        $i = ctype_digit($index) && strlen($index) < 3 ? (int) $index : -1;
        if (($state['stage'] ?? null) !== 'profile' || ! isset($state['profiles'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }
        $selected = $state['profiles'][$i];
        try {
            $inbounds = $this->nodes->inbounds($root, $selected['uuid']);
        } catch (Throwable) {
            $this->render($bot, 'دریافت ورودی‌های این پروفایل ممکن نیست.', $this->cancelKeyboard());

            return;
        }
        $state['stage'] = 'inbounds';
        $state['profile'] = $selected;
        $state['inbounds'] = $inbounds;
        $state['selected'] = [];
        $this->save($root, $state);
        $this->showInbounds($bot, $state);
    }

    public function toggle(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        $i = ctype_digit($index) && strlen($index) < 3 ? (int) $index : -1;
        if (($state['stage'] ?? null) !== 'inbounds' || ! isset($state['inbounds'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }
        $uuid = $state['inbounds'][$i]['uuid'];
        $selected = $state['selected'];
        if (in_array($uuid, $selected, true)) {
            $selected = array_values(array_filter($selected, static fn (string $item): bool => $item !== $uuid));
        } else {
            $selected[] = $uuid;
        }
        $state['selected'] = $selected;
        $this->save($root, $state);
        $this->showInbounds($bot, $state);
    }

    public function preview(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        if (($state['stage'] ?? null) !== 'inbounds' || ($state['selected'] ?? []) === []) {
            $this->render($bot, 'حداقل یک ورودی را انتخاب کنید.', $this->cancelKeyboard());

            return;
        }
        $token = bin2hex(random_bytes(16));
        $state['stage'] = 'preview';
        $state['token'] = $token;
        $this->save($root, $state);
        $this->render($bot,
            "➕ افزودن نود — گام ۳ از ۳\n\n"
            .'نام: '.$this->safe($state['name'])."\n"
            .'آدرس: '.$this->safe($state['address'])."\n"
            .'پروفایل: '.$this->safe($state['profile']['name'])."\n"
            .'ورودی‌های انتخاب‌شده: '.count($state['selected'])."\n\n"
            .'با تأیید، درخواست ساخت نود ارسال می‌شود. از تأیید دوباره خودداری کنید.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید ساخت', callback_data: 'node1:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node1:cancel')),
        );
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
            if (! is_array($draft) || ($draft['stage'] ?? null) !== 'preview'
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
            $uuid = $this->nodes->create(
                $root, $state['name'], $state['address'],
                $state['profile']['uuid'], $state['selected'],
            );
            $this->render($bot, "✅ نود در پنل ثبت شد.\n\nاتصال واقعی سرور را جداگانه بررسی کنید.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('📄 جزئیات نود', callback_data: 'node1:view:'.$uuid))
                    ->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'node1:list:1')));
        } catch (ValidationException) {
            $this->render($bot, "درخواست اجرا نشد؛ مشخصات یا دسترسی تغییر کرده است.\n\nفهرست سرورها را تازه‌سازی کنید.",
                $this->listKeyboard());
        } catch (LockTimeoutException) {
            $this->render($bot, "عملیات دیگری روی سرورها در حال انجام است.\n\nابتدا فهرست را بررسی کنید.",
                $this->listKeyboard());
        } catch (Throwable) {
            // The remote POST might have succeeded despite a timeout or malformed
            // response. The consumed token MUST NEVER be reused to retry it.
            $this->render($bot, "نتیجه ثبت سرور مشخص نیست.\n\nپیش از هر اقدام، فهرست سرورها را بررسی کنید.",
                $this->listKeyboard());
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root));
        $this->browse($bot);
    }

    /** @param list<array{uuid:string,name:string}> $profiles */
    private function showProfiles(Nutgram $bot, array $profiles): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($profiles as $i => $profile) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $this->safe($profile['name']), callback_data: 'node1:profile:'.$i,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node1:cancel'));
        $this->render($bot, "➕ افزودن نود — گام ۲ از ۳\n\nپروفایل را انتخاب کنید."
            .($profiles === [] ? "\n\nهنوز پروفایلی در پنل وجود ندارد." : ''), $keyboard);
    }

    /** @param array<string, mixed> $state */
    private function showInbounds(Nutgram $bot, array $state): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($state['inbounds'] as $i => $inbound) {
            $marked = in_array($inbound['uuid'], $state['selected'], true) ? '✅' : '▫️';
            $keyboard->addRow(InlineKeyboardButton::make(
                $marked.' '.$this->safe($inbound['name']), callback_data: 'node1:inbound:'.$i,
            ));
        }
        if ($state['selected'] !== []) {
            $keyboard->addRow(InlineKeyboardButton::make('✅ بررسی نهایی', callback_data: 'node1:preview'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node1:cancel'));
        $this->render($bot, "🔌 انتخاب ورودی‌ها\n\nیک یا چند ورودی را انتخاب کنید."
            .($state['inbounds'] === [] ? "\n\nاین پروفایل ورودی ندارد؛ انصراف دهید و پروفایل دیگری انتخاب کنید." : ''), $keyboard);
    }

    private function root(Nutgram $bot): ?int
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    /** @return array<string, mixed>|null */
    private function draft(int $root): ?array
    {
        $value = Cache::get($this->key($root));

        return is_array($value) ? $value : null;
    }

    /** @param array<string, mixed> $value */
    private function save(int $root, array $value): void
    {
        Cache::put($this->key($root), $value, now()->addMinutes(10));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('node01:draft:'.$root);
    }

    private function safe(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 80);
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->listKeyboard());
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '❌ انصراف', callback_data: 'node1:cancel',
        ));
    }

    private function listKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ فهرست نودها', callback_data: 'node1:list:1',
        ));
    }

    private function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ مدیریت', callback_data: 'menu:admin',
        ));
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
