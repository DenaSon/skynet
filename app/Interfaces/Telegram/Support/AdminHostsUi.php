<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminHosts;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** HOST-01: private Super Admin browsing and a disabled-by-default Host wizard. */
final readonly class AdminHostsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminHosts $hosts,
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
            $hosts = $this->hosts->all($root);
        } catch (Throwable) {
            $this->render($bot, "🌐 مدیریت هاست‌ها\n\nدریافت فهرست ممکن نیست.", $this->home());

            return;
        }

        $pages = max(1, (int) ceil(count($hosts) / 10));
        $page = min($page, $pages);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_slice($hosts, ($page - 1) * 10, 10) as $host) {
            $status = $host['disabled'] === true ? '⏸️' : ($host['disabled'] === false ? '🟢' : '❔');
            $keyboard->addRow(InlineKeyboardButton::make(
                $status.' '.self::safe($host['remark']), callback_data: 'host1:view:'.$host['uuid'],
            ));
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'host1:list:'.($page - 1));
        }
        if ($page < $pages) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'host1:list:'.($page + 1));
        }
        if ($nav !== []) {
            $keyboard->addRow(...$nav);
        }
        $keyboard->addRow(InlineKeyboardButton::make('➕ افزودن هاست', callback_data: 'host1:begin'));
        $keyboard->addRow(InlineKeyboardButton::make('⚙️ عملیات گروهی', callback_data: 'host2:batch:begin'));
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'host1:list:'.$page));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));
        $this->render($bot,
            "🌐 مدیریت هاست‌ها\n\nکل: ".count($hosts)." | صفحه {$page} از {$pages}\n\n"
            .($hosts === [] ? 'هنوز هاستی تعریف نشده است.' : 'برای مشاهده مشخصات، یک هاست را انتخاب کنید.'),
            $keyboard,
        );
    }

    public function detail(Nutgram $bot, string $uuid): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $host = $this->hosts->one($root, $uuid);
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات این هاست در دسترس نیست.', $this->listKeyboard());

            return;
        }
        $status = match ($host['disabled']) {
            true => '⏸️ غیرفعال',
            false => '🟢 فعال',
            default => 'نامشخص',
        };
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✏️ ویرایش', callback_data: 'host2:prepare:'.$host['uuid'].':edit'));
        if ($host['disabled'] !== null) {
            $mode = $host['disabled'] ? 'enable' : 'disable';
            $keyboard->addRow(InlineKeyboardButton::make(
                $mode === 'enable' ? '▶️ فعال‌سازی' : '⏸️ غیرفعال‌سازی',
                callback_data: 'host2:prepare:'.$host['uuid'].':'.$mode,
            ));
        }
        if ($host['disabled'] === true) {
            $keyboard->addRow(InlineKeyboardButton::make('🗑️ حذف هاست', callback_data: 'host2:prepare:'.$host['uuid'].':delete'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'host1:view:'.$host['uuid']));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ فهرست هاست‌ها', callback_data: 'host1:list:1'));
        $this->render($bot,
            "🌐 جزئیات هاست\n\n"
            .'نام: '.self::safe($host['remark'])."\n"
            .'آدرس: '.self::safe($host['address'])."\n"
            .'پورت: '.$host['port']."\n"
            .'وضعیت: '.$status."\n"
            .'شناسه پروفایل: '.($host['profileUuid'] ?? 'نامشخص')."\n"
            .'شناسه ورودی: '.($host['inboundUuid'] ?? 'نامشخص')."\n\n"
            .'ثبت هاست به‌معنای تأیید اتصال کاربران نیست.',
            $keyboard,
        );
    }

    public function begin(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, "➕ افزودن هاست\n\nافزودن هاست فعلاً غیرفعال است.", $this->listKeyboard());

            return;
        }

        // Retire pending Node text handlers; never let an old wizard consume Host details.
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$root));
        }
        $this->save($root, ['stage' => 'info']);
        $this->render($bot,
            "➕ افزودن هاست — گام ۱ از ۳\n\n"
            ."نام | آدرس | پورت را در یک پیام بفرستید:\n"
            ."Germany-TLS | edge.example.com | 443\n\n"
            .'آدرس باید دامنه یا IP و پورت بین ۱ تا ۶۵۵۳۵ باشد.',
            $this->cancelKeyboard(),
        );
    }

    /** Consume only an active HOST-01 text draft; leave all other text routing intact. */
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
        if (($state['stage'] ?? null) !== 'info') {
            return false;
        }
        $parts = array_map('trim', explode('|', $input));
        if (count($parts) !== 3 || strlen($input) > 340
            || ! ctype_digit($parts[2]) || strlen($parts[2]) > 5) {
            $this->render($bot, 'فرمت درست: Germany-TLS | edge.example.com | 443', $this->cancelKeyboard());

            return true;
        }
        try {
            $this->hosts->validateIdentity($root, $parts[0], $parts[1], (int) $parts[2]);
            $profiles = $this->nodes->profiles($root);
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات یا پروفایل‌ها معتبر نیستند. دوباره بررسی کنید.', $this->cancelKeyboard());

            return true;
        }
        $this->save($root, [
            'stage' => 'profile', 'remark' => $parts[0], 'address' => $parts[1],
            'port' => (int) $parts[2], 'profiles' => $profiles,
        ]);
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($profiles as $i => $profile) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🧩 '.self::safe($profile['name']), callback_data: 'host1:profile:'.$i,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host1:cancel'));
        $this->render($bot,
            "➕ افزودن هاست — گام ۲ از ۳\n\n"
            .($profiles === [] ? 'پروفایلی وجود ندارد؛ ابتدا در پنل تعریف کنید.' : 'پروفایل اتصال را انتخاب کنید.'),
            $keyboard,
        );

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
        $profile = $state['profiles'][$i];
        try {
            $inbounds = $this->nodes->inbounds($root, $profile['uuid']);
        } catch (Throwable) {
            $this->render($bot, 'دریافت ورودی‌های این پروفایل ممکن نیست.', $this->cancelKeyboard());

            return;
        }
        $state['stage'] = 'inbound';
        $state['profile'] = $profile;
        $state['inbounds'] = $inbounds;
        $this->save($root, $state);
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($inbounds as $j => $inbound) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '📥 '.self::safe($inbound['name']), callback_data: 'host1:inbound:'.$j,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host1:cancel'));
        $this->render($bot,
            "➕ افزودن هاست — گام ۲ از ۳\n\n"
            .($inbounds === [] ? 'ورودی‌ای وجود ندارد؛ پروفایل دیگری انتخاب کنید.' : 'ورودی اتصال هاست را انتخاب کنید.'),
            $keyboard,
        );
    }

    public function inbound(Nutgram $bot, string $index): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        $i = ctype_digit($index) && strlen($index) < 3 ? (int) $index : -1;
        if (($state['stage'] ?? null) !== 'inbound' || ! isset($state['inbounds'][$i]['uuid'])) {
            $this->expired($bot);

            return;
        }

        $state['inbound'] = $state['inbounds'][$i];
        $state['stage'] = 'preview';
        $state['token'] = bin2hex(random_bytes(16));
        $this->save($root, $state);
        $this->render($bot,
            "➕ افزودن هاست — گام ۳ از ۳\n\n"
            .'نام: '.self::safe($state['remark'])."\n"
            .'آدرس: '.self::safe($state['address'])."\n"
            .'پورت: '.$state['port']."\n"
            .'پروفایل: '.self::safe($state['profile']['name'])."\n"
            .'ورودی: '.self::safe($state['inbound']['name'])."\n\n"
            ."هاست در حالت غیرفعال ثبت می‌شود و فعلاً به کاربران ارائه نخواهد شد.\n"
            .'با تأیید، فقط یک درخواست ثبت ارسال می‌شود.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید ساخت', callback_data: 'host1:apply:'.$state['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host1:cancel')),
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
        try {
            $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key, $token): mixed {
                $draft = Cache::get($key);
                if (! is_array($draft) || ($draft['stage'] ?? null) !== 'preview'
                    || ! is_string($draft['token'] ?? null) || ! hash_equals($draft['token'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $draft;
            });
        } catch (LockTimeoutException) {
            $this->render($bot, 'درخواست دیگری در حال بررسی است. فهرست هاست‌ها را بررسی کنید.', $this->listKeyboard());

            return;
        }

        if (! is_array($state)) {
            $this->expired($bot);

            return;
        }

        try {
            $uuid = $this->hosts->create(
                $root, $state['remark'], $state['address'], $state['port'],
                $state['profile']['uuid'], $state['inbound']['uuid'],
            );
            $this->render($bot, "✅ هاست در پنل ثبت شد و غیرفعال است.\n\nفعال‌سازی و آزمون اتصال جداگانه انجام می‌شوند.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('📄 جزئیات هاست', callback_data: 'host1:view:'.$uuid))
                    ->addRow(InlineKeyboardButton::make('◀️ فهرست هاست‌ها', callback_data: 'host1:list:1')),
            );
        } catch (ValidationException) {
            $this->render($bot, 'درخواست اجرا نشد؛ تنظیمات یا دسترسی تغییر کرده است.', $this->listKeyboard());
        } catch (LockTimeoutException) {
            $this->render($bot, 'عملیات دیگری در حال انجام است؛ ابتدا فهرست را بررسی کنید.', $this->listKeyboard());
        } catch (Throwable) {
            // POST could have succeeded remotely. The consumed token is not reusable.
            $this->render($bot, "نتیجه ثبت هاست مشخص نیست.\n\nقبل از هر اقدام دوباره، فهرست هاست‌ها را بررسی کنید.",
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
        $this->render($bot, 'افزودن هاست لغو شد.', $this->listKeyboard());
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
    private function draft(int $root): ?array
    {
        $value = Cache::get($this->key($root));

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $state */
    private function save(int $root, array $state): void
    {
        Cache::put($this->key($root), $state, now()->addMinutes(10));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('host01:draft:'.$root);
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host1:cancel'));
    }

    private function listKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست هاست‌ها', callback_data: 'host1:list:1'));
    }

    private function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->listKeyboard());
    }

    private static function safe(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 65);
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}
