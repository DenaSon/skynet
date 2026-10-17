<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminConfigProfiles;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** CFG-02: root-only, one-use profile create/rename/delete without an Xray editor. */
final readonly class AdminConfigProfileOperationsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminConfigProfiles $profiles,
        private MenuRenderer $menus,
    ) {}

    public function begin(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! $this->writesEnabled($bot)) {
            return;
        }
        $this->clearCompeting($root);
        $this->save($root, ['stage' => 'input', 'mode' => 'create']);
        $this->render($bot, "➕ افزودن پروفایل\n\n"
            ."نام پروفایل جدید را بفرستید.\n"
            ."نمونه: Europe\n\n"
            .'پروفایل جدید بدون ورودی اتصال ساخته می‌شود. تنظیم ورودی‌ها بعداً در پنل انجام می‌شود.',
            $this->cancelKeyboard());
    }

    public function prepare(Nutgram $bot, string $uuid, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! in_array($mode, ['rename', 'delete'], true)) {
            $this->render($bot, 'این عملیات معتبر نیست.', $this->back());

            return;
        }
        if (! $this->writesEnabled($bot)) {
            return;
        }

        try {
            $profile = $this->profiles->existing($root, $uuid);
        } catch (Throwable) {
            $this->render($bot, 'این پروفایل دیگر در دسترس نیست.', $this->back());

            return;
        }
        $this->clearCompeting($root);
        $state = ['stage' => $mode === 'rename' ? 'input' : 'preview', 'mode' => $mode, 'profile' => $profile];
        if ($mode === 'rename') {
            $this->save($root, $state);
            $this->render($bot, "✏️ تغییر نام پروفایل\n\n"
                .'نام فعلی: '.self::safe($profile['name'])."\n\n"
                .'نام جدید را در یک پیام بفرستید.',
                $this->cancelKeyboard());

            return;
        }

        $state['token'] = bin2hex(random_bytes(16));
        $this->save($root, $state);
        $this->preview($bot, $state);
    }

    /** Consume only an active CFG-02 name prompt and preserve older text routing. */
    public function text(Nutgram $bot, string $text): bool
    {
        $root = $this->rootSilent($bot);
        if ($root === null) {
            return false;
        }
        $state = $this->draft($root);
        if (($state['stage'] ?? null) !== 'input' || ! in_array($state['mode'] ?? null, ['create', 'rename'], true)) {
            return false;
        }

        try {
            $this->profiles->validateName($root, $text);
            if ($state['mode'] === 'rename' && $state['profile']['name'] === $text) {
                throw ValidationException::withMessages(['profile' => 'Name unchanged.']);
            }
        } catch (ValidationException) {
            $this->render($bot, 'نام معتبر نیست یا تغییری نکرده است. یک نام ساده تا ۶۴ نویسه بفرستید.',
                $this->cancelKeyboard());

            return true;
        }

        $state['name'] = $text;
        $state['stage'] = 'preview';
        $state['token'] = bin2hex(random_bytes(16));
        $this->save($root, $state);
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
            $this->render($bot, 'عملیات دیگری در حال بررسی است. فهرست را تازه‌سازی کنید.', $this->back());

            return;
        }
        if ($state === null) {
            $this->expired($bot);

            return;
        }

        try {
            $uuid = null;
            switch ($state['mode']) {
                case 'create':
                    $uuid = $this->profiles->create($root, $state['name']);
                    break;
                case 'rename':
                    $this->profiles->rename($root, $state['profile'], $state['name']);
                    $uuid = $state['profile']['uuid'];
                    break;
                case 'delete':
                    $this->profiles->delete($root, $state['profile']);
                    break;
                default:
                    throw ValidationException::withMessages(['profile' => 'Invalid operation.']);
            }
            $keyboard = InlineKeyboardMarkup::make();
            if ($uuid !== null) {
                $keyboard->addRow(InlineKeyboardButton::make('🧩 جزئیات پروفایل',
                    callback_data: 'cfg1:view:'.$uuid.':1'));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ فهرست پروفایل‌ها', callback_data: 'cfg1:list:1'));
            $message = $state['mode'] === 'create'
                ? "✅ درخواست ساخت پروفایل پذیرفته شد.\n\nاین پروفایل هنوز ورودی اتصال ندارد؛ برای استفاده ابتدا در پنل آن را تنظیم کنید."
                : "✅ درخواست تغییر ارسال شد.\n\nوضعیت نهایی را از فهرست بررسی کنید.";
            $this->render($bot, $message, $keyboard);
        } catch (ValidationException) {
            $this->render($bot, 'درخواست اجرا نشد؛ اطلاعات، وابستگی‌ها یا مجوز تغییر کرده است.', $this->back());
        } catch (LockTimeoutException) {
            $this->render($bot, 'عملیات دیگری در حال انجام است. ابتدا فهرست را بررسی کنید.', $this->back());
        } catch (Throwable) {
            $this->render($bot,
                "نتیجه درخواست مشخص نیست.\n\nدرخواست خودکار تکرار نشد؛ قبل از هر اقدام فهرست را بررسی کنید.",
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
        $this->render($bot, 'عملیات لغو شد.', $this->back());
    }

    /** @param array<string,mixed> $state */
    private function preview(Nutgram $bot, array $state): void
    {
        $mode = $state['mode'];
        $title = match ($mode) {
            'create' => 'ساخت پروفایل',
            'rename' => 'تغییر نام',
            'delete' => 'حذف پروفایل',
            default => 'بررسی عملیات',
        };
        $description = match ($mode) {
            'create' => 'نام: '.self::safe($state['name'])."\n".'پروفایل پایه بدون ورودی اتصال یا کلید محرمانه ساخته می‌شود.',
            'rename' => 'نام قبلی: '.self::safe($state['profile']['name'])."\n"
                .'نام جدید: '.self::safe($state['name'])."\n".'تنظیمات اتصال تغییر نمی‌کنند.',
            'delete' => 'نام: '.self::safe($state['profile']['name'])."\n"
                .'حذف دائمی است و فقط پس از تأیید نبود وابستگی Node و Host انجام می‌شود.',
            default => '',
        };
        $this->render($bot, "⚠️ تأیید {$title}\n\n".$description
            ."\n\n".'فقط یک درخواست ارسال خواهد شد.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'cfg2:apply:'.$state['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cfg2:cancel')));
    }

    private function writesEnabled(Nutgram $bot): bool
    {
        if (config('services.remnawave.writes_enabled') === true) {
            return true;
        }
        $this->render($bot, 'تغییرات پروفایل‌ها فعلاً غیرفعال است.', $this->back());

        return false;
    }

    private function clearCompeting(int $root): void
    {
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:',
            'host01:draft:', 'host02:draft:', 'host02:batch:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$root));
        }
        Cache::forget($this->key($root));
    }

    private function rootSilent(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);

        return $actor?->canManageRoles() === true ? $actor->userId : null;
    }

    private function root(Nutgram $bot): ?int
    {
        $root = $this->rootSilent($bot);
        if ($root === null && $this->private->id($bot) !== null) {
            $this->menus->denyAccess($bot);
        }

        return $root;
    }

    /** @return array<string,mixed>|null */
    private function draft(int $root): ?array
    {
        $draft = Cache::get($this->key($root));

        return is_array($draft) ? $draft : null;
    }

    /** @param array<string,mixed> $state */
    private function save(int $root, array $state): void
    {
        Cache::put($this->key($root), $state, now()->addMinutes(10));
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('cfg02:draft:'.$root);
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پروفایل‌ها', callback_data: 'cfg1:list:1'));
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'cfg2:cancel'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا قبلاً انجام شده است.', $this->back());
    }

    private static function safe(string $text): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text)), 0, 64);
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
