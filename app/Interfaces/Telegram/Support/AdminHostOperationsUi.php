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

/** HOST-02: one-Host edit, toggle or disabled-only deletion. */
final readonly class AdminHostOperationsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminHosts $hosts,
        private MenuRenderer $menus,
    ) {}

    public function prepare(Nutgram $bot, string $uuid, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! in_array($mode, ['edit', 'enable', 'disable', 'delete'], true)) {
            $this->render($bot, 'این عملیات معتبر نیست.', $this->back());

            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, 'تغییرات هاست‌ها فعلاً غیرفعال است.', $this->back());

            return;
        }
        try {
            $host = $this->hosts->one($root, $uuid);
        } catch (Throwable) {
            $this->render($bot, 'دریافت اطلاعات هاست ممکن نیست.', $this->back());

            return;
        }
        if (($mode === 'delete' || $mode === 'enable') && $host['disabled'] !== true
            || $mode === 'disable' && $host['disabled'] !== false) {
            $this->render($bot, 'برای این تغییر، وضعیت هاست باید مشخص و مناسب باشد.', $this->hostBack($host['uuid']));

            return;
        }
        // Prevent old text handlers and old confirmations acting on this new form.
        foreach (['host01:draft:', 'host02:batch:', 'node01:draft:', 'node02:draft:', 'node03:draft:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$root));
        }
        $state = ['stage' => $mode === 'edit' ? 'edit' : 'preview', 'mode' => $mode, 'host' => $host];
        if ($mode === 'edit') {
            $this->store($root, $state);
            $this->render($bot,
                "✏️ ویرایش هاست\n\n"
                ."نام | آدرس | پورت جدید را بفرستید:\n"
                ."TLS-Host | edge.example.com | 443\n\n"
                .'مقادیر فعلی: '.self::safe($host['remark']).' | '.self::safe($host['address']).' | '.$host['port'],
                $this->cancelKeyboard(),
            );

            return;
        }
        $state['token'] = bin2hex(random_bytes(16));
        $this->store($root, $state);
        $this->preview($bot, $state);
    }

    public function text(Nutgram $bot, string $input): bool
    {
        $root = $this->rootSilent($bot);
        if ($root === null) {
            return false;
        }
        $state = $this->draft($root);
        if (($state['stage'] ?? null) !== 'edit' || ($state['mode'] ?? null) !== 'edit') {
            return false;
        }
        $parts = array_map('trim', explode('|', $input));
        if (count($parts) !== 3 || strlen($input) > 340 || ! ctype_digit($parts[2]) || strlen($parts[2]) > 5) {
            $this->render($bot, 'فرمت درست: TLS-Host | edge.example.com | 443', $this->cancelKeyboard());

            return true;
        }
        try {
            $this->hosts->validateIdentity($root, $parts[0], $parts[1], (int) $parts[2]);
        } catch (Throwable) {
            $this->render($bot, 'نام، آدرس یا پورت معتبر نیست.', $this->cancelKeyboard());

            return true;
        }
        $state['fields'] = ['remark' => $parts[0], 'address' => $parts[1], 'port' => (int) $parts[2]];
        if ($state['fields']['remark'] === $state['host']['remark']
            && $state['fields']['address'] === $state['host']['address']
            && $state['fields']['port'] === $state['host']['port']) {
            $this->render($bot, 'هیچ مشخصاتی تغییر نکرده است.', $this->cancelKeyboard());

            return true;
        }
        $state['stage'] = 'preview';
        $state['token'] = bin2hex(random_bytes(16));
        $this->store($root, $state);
        $this->preview($bot, $state);

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->render($bot, 'این تأیید معتبر نیست.', $this->back());

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
            $this->render($bot, 'درخواست دیگری در حال بررسی است. ابتدا هاست را تازه‌سازی کنید.', $this->back());

            return;
        }
        if ($state === null) {
            $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->back());

            return;
        }
        try {
            $this->hosts->change($root, $state['host']['uuid'], $state['mode'], $state['host'], $state['fields'] ?? []);
            $this->render($bot, "✅ درخواست تغییر ثبت شد.\n\nوضعیت جدید را از فهرست هاست‌ها بررسی کنید.",
                $this->hostBack($state['host']['uuid']));
        } catch (ValidationException) {
            $this->render($bot, 'درخواست انجام نشد؛ تنظیمات یا مجوز تغییر کرده است. دوباره بررسی کنید.', $this->back());
        } catch (LockTimeoutException) {
            $this->render($bot, 'عملیات دیگری روی هاست‌ها در جریان است.', $this->back());
        } catch (Throwable) {
            $this->render($bot,
                "نتیجه عملیات مشخص نیست.\n\nدرخواست تکرار نشد؛ قبل از اقدام دوباره وضعیت هاست را بررسی کنید.",
                $this->back());
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = $this->draft($root);
        Cache::forget($this->key($root));
        $uuid = is_array($state) ? ($state['host']['uuid'] ?? null) : null;
        $this->render($bot, 'تغییر هاست لغو شد.', is_string($uuid) ? $this->hostBack($uuid) : $this->back());
    }

    /** @param array<string,mixed> $state */
    private function preview(Nutgram $bot, array $state): void
    {
        $host = $state['host'];
        $mode = $state['mode'];
        $action = match ($mode) {
            'edit' => 'ویرایش مشخصات',
            'enable' => 'فعال‌سازی',
            'disable' => 'غیرفعال‌سازی',
            'delete' => 'حذف دائمی',
        };
        $detail = '';
        if ($mode === 'edit') {
            $fields = $state['fields'];
            $detail = "\nمشخصات جدید:\n"
                .self::safe($fields['remark'])."\n"
                .self::safe($fields['address']).':'.$fields['port']."\n";
        }
        $warning = match ($mode) {
            'enable' => 'فعال‌سازی ممکن است این هاست را در اشتراک مشتریان قرار دهد.',
            'disable' => 'غیرفعال‌سازی ممکن است اتصال مشتریان را مختل کند.',
            'delete' => 'این حذف دائمی است؛ هاست باید پیشاپیش غیرفعال باشد.',
            default => 'مشخصات اتصال مشتریان ممکن است تغییر کند.',
        };
        $this->render($bot,
            "⚠️ تأیید {$action}\n\n"
            .'هاست: '.self::safe($host['remark'])."\n"
            .'آدرس فعلی: '.self::safe($host['address']).':'.$host['port']."\n"
            .$detail."\n".$warning."\n\n".'این اقدام فقط یک‌بار ارسال می‌شود.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'host2:apply:'.$state['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host2:cancel')),
        );
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

    /** @return array<string,mixed>|null */
    private function draft(int $root): ?array
    {
        $draft = Cache::get($this->key($root));

        return is_array($draft) ? $draft : null;
    }

    /** @param array<string,mixed> $state */
    private function store(int $root, array $state): void
    {
        Cache::put($this->key($root), $state, now()->addMinutes(10));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('host02:draft:'.$root);
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'host2:cancel'));
    }

    private function hostBack(string $uuid): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات هاست', callback_data: 'host1:view:'.$uuid))
            ->addRow(InlineKeyboardButton::make('📋 فهرست هاست‌ها', callback_data: 'host1:list:1'));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست هاست‌ها', callback_data: 'host1:list:1'));
    }

    private static function safe(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)), 0, 65);
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
