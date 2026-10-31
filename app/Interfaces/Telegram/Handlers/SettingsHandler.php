<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Settings\ResellerServiceStatusSettings;
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
            ->addRow(InlineKeyboardButton::make('🔐 مدیریت سرویس توسط نماینده', callback_data: 'admin:reseller-status:show'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, '<b>⚙️ تنظیمات</b>'."\n\nبخش موردنظر را انتخاب کنید.", $keyboard);
    }


    /** Root-only control for the reseller Mini App; no Provider operation here. */
    public function resellerStatus(Nutgram $bot): void
    {
        if (! $this->authorized($bot)) {
            return;
        }

        try {
            $enabled = app(ResellerServiceStatusSettings::class)->enabled;
        } catch (Throwable $exception) {
            report($exception);
            $this->render($bot, 'تنظیمات فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.', $this->resellerStatusBack());

            return;
        }

        $status = $enabled ? '🟢 فعال' : '⚪ غیرفعال';
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ فعال', callback_data: 'admin:reseller-status:prepare:on'),
                InlineKeyboardButton::make('⏸️ غیرفعال', callback_data: 'admin:reseller-status:prepare:off'),
            )
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات', callback_data: 'admin:welcome:show'));

        $this->render($bot, '<b>🔐 مدیریت سرویس توسط نماینده</b>'
            ."\n\nوضعیت فعلی: ".$status
            ."\n\nبا فعال‌سازی، نمایندگان می‌توانند سرویس‌های مشتریان خود را موقتاً غیرفعال و در شرایط مجاز دوباره فعال کنند."
            ."\nزمان انقضا و حجم سرویس تغییر نمی‌کند.",
            $keyboard);
    }

    public function prepareResellerStatus(Nutgram $bot, string $state): void
    {
        if (! $this->authorized($bot) || ! in_array($state, ['on', 'off'], true)) {
            return;
        }

        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return;
        }

        try {
            $current = app(ResellerServiceStatusSettings::class)->enabled;
        } catch (Throwable $exception) {
            report($exception);
            $this->render($bot, 'تنظیمات فعلاً در دسترس نیست.', $this->resellerStatusBack());

            return;
        }

        $desired = $state === 'on';
        if ($current === $desired) {
            $this->resellerStatus($bot);

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->resellerStatusTokenKey($id, $token), $state, now()->addMinutes(3));

        $action = $desired ? 'فعال‌سازی' : 'غیرفعال‌سازی';
        $this->render($bot, '<b>تأیید '.$action.'</b>'
            ."\n\nآیا تغییر دسترسی نمایندگان به مدیریت وضعیت سرویس را تأیید می‌کنید؟"
            ."\nاین اقدام وضعیت سرویس‌های موجود را تغییر نمی‌دهد.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید', callback_data: 'admin:reseller-status:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'admin:reseller-status:show')));
    }

    public function applyResellerStatus(Nutgram $bot, string $token): void
    {
        if (! $this->authorized($bot) || preg_match('/\\A[a-f0-9]{32}\\z/D', $token) !== 1) {
            return;
        }

        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return;
        }

        $key = $this->resellerStatusTokenKey($id, $token);
        try {
            $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key): mixed {
                return Cache::pull($key);
            });

            if (! in_array($state, ['on', 'off'], true)) {
                $this->render($bot, 'این تأیید قبلاً استفاده شده یا منقضی است.', $this->resellerStatusBack());

                return;
            }

            $settings = app(ResellerServiceStatusSettings::class);
            $settings->enabled = $state === 'on';
            $settings->save();
        } catch (Throwable $exception) {
            report($exception);
            $this->render($bot, 'ذخیره تنظیمات موفق نبود. دوباره تلاش کنید.', $this->resellerStatusBack());

            return;
        }

        $this->resellerStatus($bot);
    }

    private function resellerStatusTokenKey(int $id, string $token): string
    {
        return 'admin:reseller-status:confirmation:'.$id.':'.$token;
    }

    private function resellerStatusBack(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات', callback_data: 'admin:welcome:show'));
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
