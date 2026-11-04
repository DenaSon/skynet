<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Handlers;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Interfaces\Telegram\Support\MenuRenderer;
use App\Interfaces\Telegram\Support\PrivateTelegramUser;
use App\Interfaces\Telegram\Support\ReplyMenuLabels;
use App\Interfaces\Telegram\Support\TelegramUi;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Settings\OperationalSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** Trusted Admin-only presentation for three non-secret operator policies. */
final readonly class OperationalSettingsHandler
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private AssertBillingAccess $access,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root));
        try {
            $settings = $this->loaded();
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'تنظیمات فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.');

            return;
        }

        $panel = number_format($settings->panel_minimum_wallet_toman).' تومان';
        $trial = $settings->trial_enabled ? '🟢 فعال' : '⚪ غیرفعال';
        $limit = number_format($settings->trial_daily_limit).' تست';
        $keys = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💰 حداقل اعتبار نمایندگی',
                callback_data: 'admin:ops:edit:panel'))
            ->addRow(InlineKeyboardButton::make('🧪 تست رایگان',
                callback_data: 'admin:ops:edit:trial'))
            ->addRow(InlineKeyboardButton::make('📅 سقف روزانه تست',
                callback_data: 'admin:ops:edit:limit'))
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات', callback_data: 'admin:welcome:show'));

        $plain = '<b>⚙️ تنظیمات عملیاتی</b>'
            ."\n\n💰 حداقل اعتبار نمایندگی: <b>".$panel.'</b>'
            ."\n🧪 تست رایگان: <b>".$trial.'</b>'
            ."\n📅 سقف روزانه: <b>".$limit.'</b>'
            ."\n\nبرای تغییر هر گزینه، دکمه آن را انتخاب کنید.";
        $html = '<h3>⚙️ تنظیمات عملیاتی</h3><table bordered striped compact>'
            .self::cell('حداقل اعتبار نمایندگی', $panel)
            .self::cell('تست رایگان', $trial)
            .self::cell('سقف روزانه تست', $limit)
            .'</table><footer>💡 تغییرها پس از تأیید ذخیره می‌شوند و برای درخواست‌های جدید اعمال می‌شوند.</footer>';
        $this->render($bot, $plain, $keys, $html);
    }

    public function edit(Nutgram $bot, string $kind): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! in_array($kind, ['panel', 'trial', 'limit'], true)) {
            $this->notice($bot, 'گزینه انتخاب‌شده معتبر نیست.');

            return;
        }
        try {
            $settings = $this->loaded();
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'تنظیمات در دسترس نیست.');

            return;
        }
        // Entering an operational form retires older Admin text drafts, so
        // earlier finance/wizard handlers cannot consume its next input.
        $this->clearCompetingDrafts($root, (int) $this->private->id($bot));
        $key = $this->key($root);
        Cache::forget($key);

        if ($kind === 'trial') {
            $status = $settings->trial_enabled ? '🟢 فعال' : '⚪ غیرفعال';
            $this->render($bot, '<b>🧪 تست رایگان</b>'
                ."\n\nوضعیت فعلی: {$status}"
                ."\n\nغیرفعال‌سازی فقط پذیرش تست‌های جدید را متوقف می‌کند؛ تست‌های موجود همچنان قابل پیگیری‌اند.",
                InlineKeyboardMarkup::make()
                    ->addRow(
                        InlineKeyboardButton::make('✅ فعال', callback_data: 'admin:ops:trial:on'),
                        InlineKeyboardButton::make('⏸️ غیرفعال', callback_data: 'admin:ops:trial:off'),
                    )
                    ->addRow(InlineKeyboardButton::make('◀️ تنظیمات عملیاتی', callback_data: 'admin:ops:show')));

            return;
        }

        Cache::put($key, ['kind' => $kind, 'step' => 'input'], now()->addMinutes(10));
        $panel = $kind === 'panel';
        $title = $panel ? '💰 حداقل اعتبار نمایندگی' : '📅 سقف روزانه تست';
        $current = $panel ? number_format($settings->panel_minimum_wallet_toman).' تومان'
            : number_format($settings->trial_daily_limit).' تست';
        $hint = $panel ? 'مبلغ جدید را به تومان ارسال کنید. نمونه: 550000'
            : 'تعداد مجاز روزانه را بین ۱ تا ۱۰۰۰ وارد کنید. نمونه: 20';
        $this->render($bot, '<b>'.$title.'</b>'
            ."\n\nمقدار فعلی: <b>{$current}</b>"
            ."\n\n".$hint
            ."\n\n<blockquote>تغییر، درخواست‌های قبلی را حذف یا اصلاح نمی‌کند.</blockquote>",
            $this->back());
    }

    public function prepareTrial(Nutgram $bot, string $value): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! in_array($value, ['on', 'off'], true)) {
            $this->notice($bot, 'مقدار معتبر نیست.');

            return;
        }
        try {
            $settings = $this->loaded();
            $this->clearCompetingDrafts($root, (int) $this->private->id($bot));
            $this->confirm($bot, $root, 'trial', $settings->trial_enabled, $value === 'on');
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'تنظیمات در دسترس نیست.');
        }
    }

    public function text(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            return false;
        }
        $key = $this->key($actor->userId);
        $state = Cache::get($key);
        if (! is_array($state) || ! in_array($state['step'] ?? null, ['input', 'confirm'], true)) {
            return false;
        }
        if ($this->root($bot) === null) {
            Cache::forget($key);

            return true;
        }

        if (str_starts_with(trim($text), '/') || in_array($text, ReplyMenuLabels::all(), true)) {
            Cache::forget($key);

            return false;
        }

        if ($state['step'] === 'confirm') {
            $this->render($bot, 'برای ادامه، تأیید نهایی یا انصراف را انتخاب کنید.', $this->back());

            return true;
        }

        $kind = $state['kind'] ?? null;
        if (! in_array($kind, ['panel', 'limit'], true)) {
            Cache::forget($key);

            return true;
        }
        $normalized = strtr(trim($text), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        if (preg_match('/\A[1-9][0-9]{0,8}\z/D', $normalized) !== 1) {
            $this->render($bot, 'یک عدد صحیح و مثبت بدون جداکننده وارد کنید.', $this->back());

            return true;
        }
        $number = (int) $normalized;
        if (($kind === 'limit' && ($number < 1 || $number > 1000))
            || ($kind === 'panel' && ($number < 1 || $number > 100_000_000))) {
            $this->render($bot, $kind === 'limit'
                ? 'تعداد روزانه باید بین ۱ تا ۱۰۰۰ باشد.'
                : 'حداقل اعتبار باید بین ۱ تا ۱۰۰ میلیون تومان باشد.', $this->back());

            return true;
        }
        try {
            $settings = $this->loaded();
            $old = $kind === 'panel' ? $settings->panel_minimum_wallet_toman : $settings->trial_daily_limit;
            $this->confirm($bot, $actor->userId, $kind, $old, $number);
        } catch (Throwable $exception) {
            report($exception);
            Cache::forget($key);
            $this->notice($bot, 'تنظیمات فعلاً قابل ذخیره نیست.');

            return true;
        }

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->notice($bot, 'تأیید نامعتبر است.');

            return;
        }
        $key = $this->key($root);
        try {
            $draft = Cache::lock($key.':apply', 15)->block(2, static function () use ($key, $token): ?array {
                $state = Cache::get($key);
                if (! is_array($state) || ($state['step'] ?? null) !== 'confirm'
                    || ! is_string($state['token'] ?? null) || ! hash_equals($state['token'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $state;
            });
            if ($draft === null) {
                $this->notice($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.');

                return;
            }

            // Serialize privileged updates from different trusted roots.
            Cache::lock('admin:ops:write', 10)->block(3, function () use ($draft, $root): void {
                $this->access->reviewer($root);
                $settings = $this->loaded();
                $property = match ($draft['kind']) {
                    'panel' => 'panel_minimum_wallet_toman',
                    'trial' => 'trial_enabled',
                    'limit' => 'trial_daily_limit',
                    default => throw new RuntimeException('Unsupported operational setting.'),
                };
                $old = $settings->{$property};
                $new = $draft['new'];
                if ($old !== $draft['old']) {
                    throw ValidationException::withMessages(['settings' => 'Settings changed before confirmation.']);
                }
                if (($property === 'trial_enabled' && ! is_bool($new))
                    || ($property === 'trial_daily_limit' && (! is_int($new) || $new < 1 || $new > 1000))
                    || ($property === 'panel_minimum_wallet_toman'
                        && (! is_int($new) || $new < 1 || $new > 100_000_000))) {
                    throw ValidationException::withMessages(['settings' => 'Invalid confirmed setting.']);
                }
                $settings->{$property} = $new;
                $settings->save();
                Log::notice('Trusted root updated operational setting.', [
                    'actor_id' => $root, 'property' => $property, 'previous' => $old, 'current' => $new,
                ]);
            });

            $this->render($bot, '<b>✅ تنظیم ذخیره شد.</b>'
                ."\n\nتغییر برای درخواست‌ها و بررسی‌های جدید اعمال می‌شود.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('⚙️ تنظیمات عملیاتی', callback_data: 'admin:ops:show')));
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'تغییر انجام نشد. تنظیمات یا دسترسی تغییر کرده است؛ دوباره بررسی کنید.');
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'ذخیره تنظیمات تأیید نشد. قبل از تلاش دوباره، مقدار فعلی را بررسی کنید.');
        }
    }

    private function confirm(Nutgram $bot, int $root, string $kind, int|bool $old, int|bool $new): void
    {
        if ($old === $new) {
            Cache::forget($this->key($root));
            $this->notice($bot, 'این گزینه از قبل همین مقدار را دارد.');

            return;
        }
        $label = match ($kind) {
            'panel' => 'حداقل اعتبار نمایندگی',
            'trial' => 'تست رایگان',
            'limit' => 'سقف روزانه تست',
            default => throw new RuntimeException('Invalid operational setting.'),
        };
        $fmt = static fn (int|bool $value): string => is_bool($value)
            ? ($value ? 'فعال' : 'غیرفعال') : number_format($value).($kind === 'panel' ? ' تومان' : ' تست');
        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($root), [
            'step' => 'confirm', 'kind' => $kind, 'old' => $old, 'new' => $new, 'token' => $token,
        ], now()->addMinutes(3));

        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید و ذخیره',
                callback_data: 'admin:ops:apply:'.$token, style: ButtonStyle::SUCCESS))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ops:show'));

        $text = '<b>تأیید تغییر تنظیمات</b>'
            ."\n\n{$label}"
            ."\nمقدار فعلی: <b>".TelegramUi::escape($fmt($old)).'</b>'
            ."\nمقدار جدید: <b>".TelegramUi::escape($fmt($new)).'</b>'
            ."\n\n<blockquote>این تغییر روی درخواست‌ها و تصمیم‌های جدید اعمال می‌شود؛ عملیات قبلی حذف نمی‌شود.</blockquote>";
        $html = '<h3>تأیید تغییر تنظیمات</h3><table bordered striped compact>'
            .self::cell('تنظیم', $label)
            .self::cell('مقدار فعلی', $fmt($old))
            .self::cell('مقدار جدید', $fmt($new))
            .'</table><footer>💡 تغییر تنها با تأیید نهایی ثبت می‌شود.</footer>';
        $this->render($bot, $text, $buttons, $html);
    }

    private function loaded(): OperationalSettings
    {
        $settings = app(OperationalSettings::class);
        $settings->refresh();
        if ($settings->panel_minimum_wallet_toman === null || $settings->trial_enabled === null
            || $settings->trial_daily_limit === null) {
            throw new RuntimeException('Operational settings migration is pending.');
        }

        return $settings;
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        try {
            if ($actor === null || ! $actor->canManageRoles()) {
                throw new AuthorizationException;
            }
            $this->access->reviewer($actor->userId);

            return $actor->userId;
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return null;
        }
    }

    /**
     * Retire only this root's temporary Admin text forms; never modify
     * persistent settings, customer wallets or financial ledger entries.
     */
    private function clearCompetingDrafts(int $root, int $telegramId): void
    {
        foreach ([
            'nw01:draft:', 'node01:draft:', 'node02:draft:', 'node03:draft:',
            'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:',
            'telegram:finance:wallet:', 'telegram:bankreview:reference:',
            'cf02:form:', 'tg:users:search-prompt:',
        ] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$root));
        }

        Cache::forget('admin:accounting:draft:'.$root);
        Cache::forget('admin:sales-welcome:draft:'.$telegramId);
        Cache::forget('nw03:hostDraft:'.$root);
        Cache::forget('nw03:confirm:'.$root);
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$root);
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات عملیاتی', callback_data: 'admin:ops:show'));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, '<b>⚙️ تنظیمات</b>'."\n\n".$message, $this->back());
    }

    private static function cell(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td>'.TelegramUi::escape($value).'</td></tr>';
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, ?string $richHtml = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($richHtml !== null) {
            try {
                $rich = new InputRichMessage(html: $richHtml, is_rtl: true);
                TelegramUi::editRichText($bot, $rich, $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
