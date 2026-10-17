<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminHosts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** HOST-02: bounded multi-page selection for enable/disable only. */
final readonly class AdminHostBatchUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminHosts $hosts,
        private MenuRenderer $menus,
    ) {}

    public function begin(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, 'تغییرات هاست‌ها فعلاً غیرفعال است.', $this->back());

            return;
        }
        try {
            $hosts = $this->hosts->all($root);
        } catch (Throwable) {
            $this->render($bot, 'دریافت فهرست هاست‌ها ممکن نیست.', $this->back());

            return;
        }
        foreach (['host01:draft:', 'host02:draft:', 'node01:draft:', 'node02:draft:', 'node03:draft:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$root));
        }
        $state = ['stage' => 'select', 'hosts' => $hosts, 'selected' => [], 'page' => 1];
        $this->store($root, $state);
        $this->selection($bot, $state);
    }

    public function page(Nutgram $bot, string $raw): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        $page = ctype_digit($raw) && strlen($raw) < 4 ? (int) $raw : 0;
        $max = is_array($state) ? max(1, (int) ceil(count($state['hosts'] ?? []) / 10)) : 0;
        if (($state['stage'] ?? null) !== 'select' || $page < 1 || $page > $max) {
            $this->expired($bot);

            return;
        }
        $state['page'] = $page;
        $this->store($root, $state);
        $this->selection($bot, $state);
    }

    public function toggle(Nutgram $bot, string $raw): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        $index = ctype_digit($raw) && strlen($raw) < 4 ? (int) $raw : -1;
        if (($state['stage'] ?? null) !== 'select' || ! isset($state['hosts'][$index])) {
            $this->expired($bot);

            return;
        }
        // A malicious callback may not pick a Host outside the visible page.
        if (intdiv($index, 10) + 1 !== $state['page']) {
            $this->expired($bot);

            return;
        }
        $selected = $state['selected'];
        if (in_array($index, $selected, true)) {
            $selected = array_values(array_filter($selected, static fn (int $i): bool => $i !== $index));
        } elseif (count($selected) < 20) {
            $selected[] = $index;
        } else {
            $this->render($bot, 'حداکثر ۲۰ هاست را می‌توان هم‌زمان انتخاب کرد.', $this->selectBack($state['page']));

            return;
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
        $state = $this->draft($root);
        if (($state['stage'] ?? null) !== 'select'
            || ! in_array($mode, ['enable', 'disable'], true)
            || ($state['selected'] ?? []) === []) {
            $this->expired($bot);

            return;
        }
        $hosts = [];
        foreach ($state['selected'] as $index) {
            if (! isset($state['hosts'][$index])) {
                $this->expired($bot);

                return;
            }
            $host = $state['hosts'][$index];
            if ($host['disabled'] !== ($mode === 'enable')) {
                $this->render($bot,
                    'انتخاب شامل هاستی با وضعیت نامناسب یا نامشخص است. انتخاب را اصلاح کنید.',
                    $this->selectBack($state['page']));

                return;
            }
            $hosts[] = $host;
        }
        $state['stage'] = 'preview';
        $state['mode'] = $mode;
        $state['chosen'] = $hosts;
        $state['token'] = bin2hex(random_bytes(16));
        $this->store($root, $state);

        $action = $mode === 'enable' ? 'فعال‌سازی' : 'غیرفعال‌سازی';
        $lines = array_map(static fn (array $host): string => '• '.self::safe($host['remark']).' · '.self::safe($host['address']).':'.$host['port'], $hosts);
        $warning = $mode === 'enable'
            ? 'فعال‌سازی ممکن است این هاست‌ها را در اشتراک مشتریان قرار دهد.'
            : 'غیرفعال‌سازی ممکن است اتصال مشتریان را قطع کند.';
        $this->render($bot, "⚠️ تأیید {$action} گروهی\n\n"
            .implode("\n", $lines)."\n\n"
            .'تعداد: '.count($hosts)."\n".$warning."\n"
            .'با تأیید، دقیقاً یک درخواست گروهی ارسال می‌شود.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'host2:batch:apply:'.$state['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host2:batch:cancel')),
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
        try {
            $state = Cache::lock($this->key($root).':lock', 10)->block(2, function () use ($root, $token): ?array {
                $draft = $this->draft($root);
                if (($draft['stage'] ?? null) !== 'preview'
                    || ! is_string($draft['token'] ?? null)
                    || ! hash_equals($draft['token'], $token)) {
                    return null;
                }
                Cache::forget($this->key($root));

                return $draft;
            });
        } catch (LockTimeoutException) {
            $this->render($bot, 'درخواست دیگری در جریان است. فهرست را بررسی کنید.', $this->back());

            return;
        }
        if ($state === null) {
            $this->expired($bot);

            return;
        }

        try {
            $this->hosts->batch($root, $state['mode'], $state['chosen']);
            $this->render($bot, "✅ درخواست گروهی ثبت شد.\n\nوضعیت همه هاست‌های انتخاب‌شده را بررسی کنید.", $this->back());
        } catch (ValidationException) {
            $this->render($bot, 'درخواست اجرا نشد؛ دسترسی یا وضعیت یکی از هاست‌ها تغییر کرده است.', $this->back());
        } catch (LockTimeoutException) {
            $this->render($bot, 'عملیات دیگری روی هاست‌ها در جریان است.', $this->back());
        } catch (Throwable) {
            $this->render($bot,
                "نتیجه عملیات گروهی مشخص نیست.\n\nتکرار خودکار انجام نشد؛ فهرست را بررسی کنید.",
                $this->back());
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root));
        $this->render($bot, 'عملیات گروهی لغو شد.', $this->back());
    }

    /** @param array<string,mixed> $state */
    private function selection(Nutgram $bot, array $state): void
    {
        $page = $state['page'];
        $hosts = $state['hosts'];
        $pages = max(1, (int) ceil(count($hosts) / 10));
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_slice($hosts, ($page - 1) * 10, 10, true) as $index => $host) {
            $checked = in_array($index, $state['selected'], true) ? '✅' : '▫️';
            $keyboard->addRow(InlineKeyboardButton::make(
                $checked.' '.self::safe($host['remark']),
                callback_data: 'host2:batch:toggle:'.$index,
            ));
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'host2:batch:page:'.($page - 1));
        }
        if ($page < $pages) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'host2:batch:page:'.($page + 1));
        }
        if ($nav !== []) {
            $keyboard->addRow(...$nav);
        }
        $keyboard->addRow(
            InlineKeyboardButton::make('▶️ فعال‌سازی', callback_data: 'host2:batch:choose:enable'),
            InlineKeyboardButton::make('⏸️ غیرفعال‌سازی', callback_data: 'host2:batch:choose:disable'),
        );
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host2:batch:cancel'));
        $this->render($bot,
            "⚙️ عملیات گروهی هاست‌ها\n\n"
            .'انتخاب‌شده: '.count($state['selected'])." از ۲۰ | صفحه {$page} از {$pages}\n"
            ."فقط هاست‌هایی با وضعیت مناسب را انتخاب کنید.\n\n"
            .'برای ادامه، نوع عملیات را انتخاب کنید.',
            $keyboard);
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

    /** @return array<string,mixed>|null */
    private function draft(int $root): ?array
    {
        $value = Cache::get($this->key($root));

        return is_array($value) ? $value : null;
    }

    /** @param array<string,mixed> $state */
    private function store(int $root, array $state): void
    {
        Cache::put($this->key($root), $state, now()->addMinutes(10));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('host02:batch:'.$root);
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست هاست‌ها', callback_data: 'host1:list:1'));
    }

    private function selectBack(int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ انتخاب هاست‌ها', callback_data: 'host2:batch:page:'.$page))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host2:batch:cancel'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->back());
    }

    private static function safe(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 48);
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
