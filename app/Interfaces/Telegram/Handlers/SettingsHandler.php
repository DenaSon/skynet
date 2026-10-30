<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Settings\SalesWelcomeSettings;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

final class SettingsHandler
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly MenuRenderer $menus,
    ) {}

    public function __invoke(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (! $actor->canManageRoles()) {
            $this->menus->showSettings($bot);

            return;
        }

        $this->show($bot);
    }

    public function show(Nutgram $bot): void
    {
        if (! $this->authorized($bot)) {
            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('👤 خوشامدگویی کاربر عادی', callback_data: 'admin:welcome:edit:member'))
            ->addRow(InlineKeyboardButton::make('🧩 خوشامدگویی نماینده', callback_data: 'admin:welcome:edit:reseller'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, '<b>⚙️ تنظیمات</b>'."\n\nمتن خوشامدگویی موردنظر را برای ویرایش انتخاب کنید.", $keyboard);
    }

    public function edit(Nutgram $bot, string $kind): void
    {
        if (! $this->authorized($bot) || ! in_array($kind, ['member', 'reseller'], true)) {
            return;
        }

        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return;
        }

        Cache::put($this->draftKey($id), $kind, now()->addMinutes(10));
        try {
            $settings = app(SalesWelcomeSettings::class);
            $value = $kind === 'member' ? $settings->member : $settings->reseller;
        } catch (Throwable $exception) {
            report($exception);
            $this->render($bot, 'تنظیمات فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.', $this->back());

            return;
        }

        $label = $kind === 'member' ? 'کاربر عادی' : 'نماینده';
        $this->render($bot, '<b>✏️ خوشامدگویی '.$label.'</b>'."\n\nمتن فعلی:\n<blockquote>"
            .TelegramUi::escape($value).'</blockquote>'
            ."\n\nمتن جدید را به‌صورت پیام متنی ارسال کنید. حداکثر ۱۵۰۰ نویسه."
            ."\n{name} نام کاربر و {account_type} نوع حساب را نمایش می‌دهد."
            ."\nاین ویرایش فقط پیام خوشامدگویی آغاز گفتگو را تغییر می‌دهد.",
            $this->back());
    }

    public function cancel(Nutgram $bot): void
    {
        if (! $this->authorized($bot)) {
            return;
        }
        $id = $this->privateUser->id($bot);
        if ($id !== null) {
            Cache::forget($this->draftKey($id));
        }
        $this->show($bot);
    }

    public function text(Nutgram $bot, string $text): bool
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return false;
        }
        $key = $this->draftKey($id);
        $kind = Cache::get($key);
        if (! in_array($kind, ['member', 'reseller'], true)) {
            return false;
        }
        if (! $this->authorized($bot)) {
            Cache::forget($key);

            return true;
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 1500 || str_starts_with($text, '/')) {
            $bot->sendMessage(text: 'متن باید بین ۱ تا ۱۵۰۰ نویسه باشد و با دستور شروع نشود.');

            return true;
        }

        try {
            $settings = app(SalesWelcomeSettings::class);
            if ($kind === 'member') {
                $settings->member = $text;
            } else {
                $settings->reseller = $text;
            }
            $settings->save();
        } catch (Throwable $exception) {
            report($exception);
            $bot->sendMessage(text: 'ذخیره متن موفق نبود. دوباره تلاش کنید.');

            return true;
        }

        Cache::forget($key);
        $bot->sendMessage(text: '✅ متن خوشامدگویی ذخیره شد.');
        $this->show($bot);

        return true;
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);
        if ($actor === null) {
            $this->menus->showHome($bot, null);
        }

        return $actor;
    }

    private function authorized(Nutgram $bot): bool
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return false;
        }
        if (! $actor->canManageRoles()) {
            $this->render($bot, 'دسترسی به تنظیمات مدیریتی مجاز نیست.', $this->back());

            return false;
        }

        return true;
    }

    private function draftKey(int $id): string
    {
        return 'admin:sales-welcome:draft:'.$id;
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:welcome:cancel'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
