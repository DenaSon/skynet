<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Settings\DenizBusinessSupportSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

final readonly class DenizBusinessSupportSettingsHandler
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot): void
    {
        if ($this->root($bot) === null) {
            return;
        }

        try {
            $active = DenizBusinessSupportSettings::isEnabled();
        } catch (Throwable) {
            $this->notice($bot, 'تنظیمات فعلاً در دسترس نیست.');

            return;
        }

        $ready = config('denagate_ai.business_replies_enabled') === true;
        $status = $active && $ready ? '🟢 فعال' : ($active ? '🟠 در انتظار راه‌اندازی' : '⚪ غیرفعال');
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ فعال‌سازی', callback_data: 'admin:deniz-support:prepare:on'),
                InlineKeyboardButton::make('⏸️ غیرفعال‌سازی', callback_data: 'admin:deniz-support:prepare:off'),
            )
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات سیستم', callback_data: 'admin:welcome:show'));

        $plain = '<b>🤖 پشتیبانی هوشمند دنیز</b>'
            ."\n\nوضعیت مجوز پاسخ‌گویی: <b>{$status}</b>"
            ."\n\nاین گزینه فقط پاسخ‌گویی در حساب پشتیبانی را کنترل می‌کند و گفتگوی مدیریتی دنیز را تغییر نمی‌دهد."
            ."\n\n<blockquote>پاسخ‌گویی فقط پس از تکمیل اتصال حساب پشتیبانی فعال می‌شود.</blockquote>";
        $html = '<h3>🤖 پشتیبانی هوشمند دنیز</h3>'
            .'<table bordered striped compact><tr><td>مجوز پاسخ‌گویی</td><td>'.TelegramUi::escape($status).'</td></tr></table>'
            .'<p>این گزینه فقط حساب پشتیبانی را کنترل می‌کند؛ گفتگوی مدیریتی بدون تغییر باقی می‌ماند.</p>'
            .'<footer>پاسخ‌گویی فقط پس از تکمیل اتصال حساب پشتیبانی فعال می‌شود.</footer>';

        $this->render($bot, $plain, $buttons, $html);
    }

    public function prepare(Nutgram $bot, string $state): void
    {
        $root = $this->root($bot);
        if ($root === null || ! in_array($state, ['on', 'off'], true)) {
            return;
        }

        try {
            $old = DenizBusinessSupportSettings::isEnabled();
        } catch (Throwable) {
            $this->notice($bot, 'تنظیمات فعلاً در دسترس نیست.');

            return;
        }

        $next = $state === 'on';
        if ($old === $next) {
            $this->show($bot);

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($root), [
            'token' => $token, 'old' => $old, 'next' => $next,
        ], now()->addMinutes(3));

        $oldLabel = $old ? 'فعال' : 'غیرفعال';
        $nextLabel = $next ? 'فعال' : 'غیرفعال';
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید تغییر', callback_data: 'admin:deniz-support:apply:'.$token, style: ButtonStyle::SUCCESS))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:deniz-support:show'));

        $plain = '<b>تأیید تنظیمات دنیز</b>'
            ."\n\nوضعیت فعلی: <b>{$oldLabel}</b>"
            ."\nوضعیت جدید: <b>{$nextLabel}</b>"
            ."\n\nاین تغییر به گفتگوی مدیریتی دنیز مربوط نیست.";
        $html = '<h3>تأیید تنظیمات دنیز</h3>'
            .'<table bordered striped compact>'
            .'<tr><td>وضعیت فعلی</td><td>'.$oldLabel.'</td></tr>'
            .'<tr><td>وضعیت جدید</td><td>'.$nextLabel.'</td></tr>'
            .'</table><footer>تغییر فقط پس از تأیید اعمال می‌شود.</footer>';

        $this->render($bot, $plain, $buttons, $html);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            return;
        }

        $key = $this->key($root);
        $telegramId = $this->private->id($bot);

        try {
            // A single-use confirmation cannot be replayed by concurrent callbacks.
            $draft = Cache::lock($key.':confirm', 10)->block(2, static function () use ($key, $token): ?array {
                $value = Cache::get($key);

                if (! is_array($value) || ($value['token'] ?? null) !== $token
                    || ! is_bool($value['old'] ?? null) || ! is_bool($value['next'] ?? null)) {
                    return null;
                }

                Cache::forget($key);

                return $value;
            });

            if ($draft === null) {
                $this->notice($bot, 'این تأیید استفاده شده یا منقضی شده است.');

                return;
            }

            Cache::lock('admin:deniz-support:write', 10)->block(2, function () use ($root, $draft, $telegramId): void {
                $settings = app(DenizBusinessSupportSettings::class);
                $settings->refresh();
                if ($settings->enabled !== $draft['old']) {
                    throw new RuntimeException('Deniz Business setting changed before confirmation.');
                }

                // Deny mutations after revocation, even if a valid confirmation exists.
                $actor = $telegramId !== null ? $this->actors->handle($telegramId) : null;
                if ($actor === null || ! $actor->canManageRoles()) {
                    throw new AuthorizationException;
                }

                $settings->enabled = $draft['next'];
                $settings->save();
                Log::notice('Trusted root changed Deniz Business support setting.', [
                    'actor_id' => $root,
                    'previous' => $draft['old'],
                    'current' => $draft['next'],
                ]);
            });

            $this->show($bot);
        } catch (Throwable) {
            $this->notice($bot, 'تغییر تأیید نشد؛ لطفاً وضعیت فعلی را بررسی کنید.');
        }
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor === null || ! $actor->canManageRoles()) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('admin:deniz-support:confirm:'.$root);
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پشتیبانی هوشمند', callback_data: 'admin:deniz-support:show'));

        $this->render($bot, '<b>🤖 دنیز</b>'."\n\n".TelegramUi::escape($message), $buttons);
    }

    private function render(Nutgram $bot, string $plain, InlineKeyboardMarkup $buttons, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $buttons);

                return;
            } catch (TelegramException) {
                // The native Telegram layout retains the same controls.
            }
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $plain, $buttons, 'HTML');

            return;
        }

        $bot->sendMessage(text: $plain, reply_markup: $buttons, parse_mode: 'HTML');
    }
}
