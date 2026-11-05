<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Trial\Application\ReadFreeTrial;
use App\Modules\Trial\Application\ReserveFreeTrial;
use App\Modules\Trial\Application\TrialAvailability;
use App\Modules\Trial\Jobs\ProvisionFreeTrialJob;
use App\Settings\OperationalSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

final readonly class SalesTrialUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $actors,
        private ReserveFreeTrial $admission,
        private ReadFreeTrial $trials,
        private SubscriptionQrPng $qr,
    ) {}

    /** Navigation must not leave a native-contact draft intercepting another form. */
    public function retireOnNavigation(Nutgram $bot): void
    {
        $text = $bot->message()->text ?? '';
        $callback = $bot->callbackQuery()->data ?? '';
        if ((! $bot->isCallbackQuery() && (! str_starts_with($text, '/') || $text === '/trial'))
            || ($bot->isCallbackQuery() && str_starts_with($callback, 'sales:trial'))) {
            return;
        }
        $actor = $this->actor($bot);
        if ($actor !== null && $this->clear($actor->userId)) {
            $bot->sendMessage(text: 'دریافت شماره بسته شد.', reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor, $this->visible($actor, (int) $this->privateUser->id($bot))));
        }
    }

    public function visible(TelegramActor $actor, int $telegramId): bool
    {
        try {
            $trial = $this->trials->find($actor->userId, $telegramId);
            if ($trial === null) {
                // Disabling new trials must not hide an already active trial.
                return OperationalSettings::trialIsEnabled() && $this->eligible($actor->userId, $telegramId);
            }
            if ($trial['state'] === 'active') {
                return ! $this->trials->status($actor->userId, $telegramId)->ended();
            }

            return in_array($trial['state'], ['reserved', 'provisioning', 'needs_review'], true);
        } catch (Throwable) {
            return false;
        }
    }

    private function eligible(int $userId, int $telegramId): bool
    {
        TrialAvailability::assertReady();
        $this->admission->assertEligible($userId, $telegramId);

        return true;
    }

    public function show(Nutgram $bot, bool $rich = true): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        app(SalesHomeUi::class)->clearInput($actor->userId, $bot);
        try {
            $trial = $this->trials->find($actor->userId, (int) $this->privateUser->id($bot));
            if ($trial === null) {
                $this->eligible($actor->userId, (int) $this->privateUser->id($bot));
                $text = '<b>🧪 تست رایگان DenaGate</b>'
                    ."\n\nحجم: <b>۲۵۰ مگابایت</b> · اعتبار: <b>۲ ساعت</b>"
                    ."\nبا پایان زمان یا حجم، تست تمام می‌شود و قابل تمدید نیست."
                    ."\n\nهر حساب و شماره فقط یک بار، ویژه کاربران بدون خرید قبلی."
                    ."\nبرای دریافت، شماره همین حساب تلگرام را با دکمه ارسال و سپس تأیید کنید.";
                $buttons = InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('📱 ارسال شماره من',
                        callback_data: 'sales:trial:contact', style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
                $html = '<h4>🧪 تست رایگان دناگیت</h4>'
                    .'<p>🎁 حجم: <b>۲۵۰ مگابایت</b><br>⏱ اعتبار: <b>۲ ساعت</b></p>'
                    .'<p>برای دریافت، فقط شماره همین حساب تلگرام را با دکمه پایین ارسال و سپس تأیید کنید.</p>'
                    .'<details><summary>📋 شرایط استفاده</summary><p>'
                    .'این تست فقط یک‌بار برای حساب و شماره واجد شرایط، بدون خرید قبلی ارائه می‌شود. '
                    .'با پایان زمان یا حجم، تست تمام می‌شود و قابل تمدید نیست.'
                    .'</p></details>';
                $this->renderOverview($bot, $text, $html, $buttons, $rich);

                return;
            }
            if (! in_array($trial['state'], ['active', 'ended'], true)) {
                $state = match ($trial['state']) {
                    'reserved', 'provisioning' => '⏳ درخواست ثبت شده و تست در حال آماده‌سازی است.',
                    'failed' => 'فعال‌سازی انجام نشد. برای راهنمایی با پشتیبانی گفتگو کنید.',
                    default => 'نتیجه فعال‌سازی در حال بررسی است. لطفاً منتظر بمانید یا با پشتیبانی گفتگو کنید.',
                };
                $text = '<b>🧪 تست رایگان</b>'."\n\n".$state;
                $html = '<h4>🧪 وضعیت تست رایگان</h4><p>'.TelegramUi::escape($state).'</p>'
                    .'<details><summary>💡 راهنمای پیگیری</summary><p>'
                    .'برای بررسی دوباره، دکمه «وضعیت تست» را بزنید. در صورت ادامه مشکل با پشتیبانی گفتگو کنید.'
                    .'</p></details>';
                $this->renderOverview($bot, $text, $html, $this->buttons(), $rich);

                return;
            }
            TelegramUi::answerCallback($bot);
            $live = $this->trials->status($actor->userId, (int) $this->privateUser->id($bot));
            $minutes = (int) ceil($live->remainingSeconds / 60);
            $label = $live->ended() ? 'تست به پایان رسیده است' : match ($live->providerStatus) {
                'ACTIVE' => '🟢 فعال', 'DISABLED' => '⏸️ غیرفعال', default => 'فعلاً قابل استفاده نیست',
            };
            $readyToConnect = ! $live->ended() && $live->providerStatus === 'ACTIVE';
            $keyboard = $this->buttons(connection: $readyToConnect, guide: $readyToConnect);
            $text = '<b>🧪 تست رایگان</b>'."\n\nوضعیت: {$label}"
                ."\nحجم باقی‌مانده: <b>".number_format($live->remainingBytes / 1048576, 1).' مگابایت</b>'
                ."\nزمان باقی‌مانده: <b>".intdiv($minutes, 60).' ساعت و '.($minutes % 60).' دقیقه</b>';
            if ($readyToConnect) {
                $text .= "\n\nبرای نصب برنامه، «📚 راهنمای اتصال» را انتخاب کنید.";
            }
            if ($live->ended()) {
                $bot->sendMessage(text: $text, parse_mode: 'HTML',
                    reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor));
            } else {
                $html = '<h4>🧪 وضعیت تست رایگان</h4>'
                    .'<p>وضعیت: <b>'.TelegramUi::escape($label).'</b>'
                    .'<br>📊 حجم باقی‌مانده: <b>'.number_format($live->remainingBytes / 1048576, 1).' مگابایت</b>'
                    .'<br>⏱ زمان باقی‌مانده: <b>'.intdiv($minutes, 60).' ساعت و '.($minutes % 60).' دقیقه</b></p>'
                    .'<details><summary>📋 راهنمای استفاده</summary><p>'
                    .'لینک و کد اتصال را از دکمه همین صفحه دریافت کنید؛ '
                    .'برای نصب برنامه، «📚 راهنمای اتصال» را بزنید. '
                    .'با تمام‌شدن زمان یا حجم، تست پایان می‌یابد.'
                    .'</p></details>';
                $this->renderOverview($bot, $text, $html, $keyboard, $rich, false);
            }
        } catch (ValidationException $error) {
            $this->render($bot, implode("\n", $error->validator->errors()->all()), $this->buttons());
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات تست فعلاً در دسترس نیست. لطفاً دوباره بررسی کنید.', $this->buttons());
        }
    }

    public function requestContact(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        try {
            $this->eligible($actor->userId, (int) $this->privateUser->id($bot));
            if ($this->trials->find($actor->userId, (int) $this->privateUser->id($bot)) !== null) {
                $this->show($bot);

                return;
            }
            app(SalesHomeUi::class)->clearInput($actor->userId, $bot);
            Cache::put(self::key($actor->userId), ['stage' => 'contact', 'telegram_id' => $this->privateUser->id($bot)], now()->addMinutes(5));
            TelegramUi::answerCallback($bot);
            $this->sendContactPrompt($bot,
                '📱 شماره متصل به همین حساب تلگرام را فقط با دکمه زیر ارسال کنید.', true);
        } catch (Throwable) {
            $this->render($bot, 'دریافت تست فعلاً ممکن نیست. شرایط حساب یا وضعیت تست را بررسی کنید.', $this->buttons());
        }
    }

    /** Re-show the native keyboard only within the original private intake. */
    public function showContactKeyboard(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        $telegramId = (int) $this->privateUser->id($bot);
        $draft = Cache::get(self::key($actor->userId));
        $valid = is_array($draft) && ($draft['stage'] ?? null) === 'contact'
            && ($draft['telegram_id'] ?? null) === $telegramId;
        try {
            if ($valid) {
                $this->eligible($actor->userId, $telegramId);
            }
        } catch (Throwable) {
            $valid = false;
        }
        TelegramUi::answerCallback($bot);

        // Old recovery buttons can remain visible after Contact succeeds.
        // Keep the owner-bound confirmation token and its original expiry.
        if (is_array($draft) && ($draft['stage'] ?? null) === 'confirm'
            && ($draft['telegram_id'] ?? null) === $telegramId) {
            $this->render($bot, 'شماره قبلاً دریافت شده است. برای ادامه، از دکمه تأیید در پیام قبلی استفاده کنید.',
                $this->buttons(), false);

            return;
        }

        if (! $valid) {
            // Do not pull a confirmation belonging to another linked Telegram account.
            if (is_array($draft) && ($draft['stage'] ?? null) === 'contact'
                && ($draft['telegram_id'] ?? null) === $telegramId) {
                $this->clear($actor->userId);
            }
            $bot->sendMessage(text: 'مرحله ارسال شماره منقضی شده یا دیگر در دسترس نیست.',
                reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor, $this->visible($actor, $telegramId)));
            $this->render($bot, 'برای دریافت تست، شرایط را دوباره بررسی کنید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🧪 بررسی تست رایگان',
                        callback_data: 'sales:trial')), false);

            return;
        }

        $this->sendContactPrompt($bot, '📱 برای ارسال شماره، دکمه «اشتراک‌گذاری شماره من» را انتخاب کنید.');
    }

    private function sendContactPrompt(Nutgram $bot, string $message, bool $withRecovery = false): void
    {
        if ($withRecovery) {
            $bot->sendMessage(text: 'دکمه اشتراک‌گذاری نمایش داده نمی‌شود؟',
                reply_markup: InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 نمایش دوباره دکمه',
                        callback_data: 'sales:trial:phone:show'))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف',
                        callback_data: 'sales:trial:cancel')));
        }

        $bot->sendMessage(
            text: $message."\n\nاگر دکمه را نمی‌بینید، آیکون کیبورد کنار کادر پیام را بزنید. برای انصراف /cancel را بفرستید.",
            reply_markup: app(ReplyMenuRenderer::class)->salesSelfContactKeyboard(),
        );
    }

    public function contact(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        $draft = Cache::get(self::key($actor->userId));
        $telegramId = (int) $this->privateUser->id($bot);
        if (! is_array($draft) || ($draft['stage'] ?? null) !== 'contact'
            || ($draft['telegram_id'] ?? null) !== $telegramId) {
            if (is_array($draft) && ($draft['stage'] ?? null) === 'confirm') {
                $this->render($bot, 'شماره قبلاً دریافت شده است. برای ادامه از دکمه تأیید استفاده کنید.',
                    $this->buttons());

                return;
            }

            // An expired Panel intake also reaches this shared Contact router.
            // Never accept its phone as a Trial; restore the normal keyboard.
            $bot->sendMessage(text: '⏳ مرحله ارسال شماره منقضی شده یا فعال نیست.',
                reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor, $this->visible($actor, $telegramId)));
            $this->render($bot, 'برای ادامه، فرم موردنظر را دوباره شروع کنید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🧩 درخواست نمایندگی',
                        callback_data: 'sales:panel:request'))
                    ->addRow(InlineKeyboardButton::make('🧪 تست رایگان',
                        callback_data: 'sales:trial')));

            return;
        }

        $message = $bot->message();
        $contact = $message?->contact;
        try {
            TrialAvailability::assertReady();
            if ($contact === null || $message->forward_origin !== null || $message->via_bot !== null) {
                throw new \RuntimeException('No native contact request.');
            }
            $verified = $this->admission->contact($actor->userId, $telegramId,
                $contact->user_id, $contact->phone_number);
            $token = bin2hex(random_bytes(16));
            Cache::put(self::key($actor->userId), ['stage' => 'confirm', 'telegram_id' => $this->privateUser->id($bot), 'token' => $token, ...$verified], now()->addMinutes(5));
            $bot->sendMessage(text: 'شماره دریافت شد.', reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor, $this->visible($actor, (int) $this->privateUser->id($bot))));
            $bot->sendMessage(text: '<b>✅ تأیید تست رایگان</b>'."\n\nشماره: ".TelegramUi::code($verified['masked'])
                ."\nحجم: <b>۲۵۰ مگابایت</b> · اعتبار: <b>۲ ساعت</b>"
                ."\n\nبا تأیید، تنها فرصت تست این حساب و شماره ثبت می‌شود.", parse_mode: 'HTML',
                reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('✅ دریافت تست رایگان',
                    callback_data: 'sales:trial:confirm:'.$token, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:trial:cancel')));
        } catch (Throwable) {
            $this->sendContactPrompt($bot,
                'شماره تأیید نشد. فقط شماره همین حساب تلگرام را با دکمه «اشتراک‌گذاری شماره من» ارسال کنید.');
        }
    }

    public function confirm(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        TelegramUi::answerCallback($bot);
        try {
            TrialAvailability::assertReady();
            $id = Cache::lock(self::key($actor->userId).':lock', 10)->block(2, function () use ($actor, $bot, $token): int {
                $draft = Cache::get(self::key($actor->userId));
                if (! is_array($draft) || ($draft['stage'] ?? null) !== 'confirm'
                    || ($draft['telegram_id'] ?? null) !== $this->privateUser->id($bot)
                    || ! is_string($draft['token'] ?? null) || ! hash_equals($draft['token'], $token)
                    || ! is_string($draft['fingerprint'] ?? null)) {
                    throw new \RuntimeException('Expired confirmation.');
                }
                $id = $this->admission->reserve($actor->userId, (int) $this->privateUser->id($bot), $draft['fingerprint']);
                $this->clear($actor->userId);

                return $id;
            });
            try {
                ProvisionFreeTrialJob::dispatch($id);
            } catch (Throwable) {
                // The durable reservation is recovered by the existing Scheduler path.
            }
            $this->render($bot, '⏳ درخواست تست ثبت شد. پس از فعال‌سازی، لینک اتصال در همین بخش نمایش داده می‌شود.', $this->buttons(), false);
        } catch (ValidationException $error) {
            $this->render($bot, implode("\n", $error->validator->errors()->all()), $this->buttons(), false);
        } catch (Throwable) {
            $this->render($bot, 'تأیید معتبر نیست یا قبلاً استفاده شده است. وضعیت تست را بررسی کنید.', $this->buttons(), false);
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        $this->clear($actor->userId);
        TelegramUi::answerCallback($bot);
        $bot->sendMessage(text: 'دریافت تست لغو شد.', reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard($actor, $this->visible($actor, (int) $this->privateUser->id($bot))));
        $bot->sendMessage(text: 'برای ادامه، از منو یا دکمه خانه استفاده کنید.', reply_markup: $this->buttons());
    }

    public function invalidInput(Nutgram $bot): bool
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return false;
        }
        $draft = Cache::get(self::key($actor->userId));
        if (! is_array($draft)) {
            return false;
        }
        if (($draft['stage'] ?? null) === 'contact') {
            $this->sendContactPrompt($bot, 'شماره متنی پذیرفته نمی‌شود؛ از دکمه اشتراک‌گذاری استفاده کنید.');
        } else {
            $bot->sendMessage(text: 'برای ادامه، تأیید تست را بزنید یا /cancel را ارسال کنید.');
        }

        return true;
    }

    public function clear(int $userId): bool
    {
        $draft = Cache::pull(self::key($userId));

        return is_array($draft) && ($draft['stage'] ?? null) === 'contact';
    }

    public function link(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        TelegramUi::answerCallback($bot);
        try {
            $url = $this->trials->connection($actor->userId, (int) $this->privateUser->id($bot));
            $keyboard = $this->buttons(guide: true, copyLink: $url);
            $text = '<b>🔐 لینک اتصال تست رایگان</b>'."\n\n".TelegramUi::code($url);
            $caption = $text."\n\n📷 کد بالا را اسکن کنید یا لینک را کپی کنید.";
            // Keep one connection card. Very long links use text only, preserving
            // the full credential beyond Telegram's media-caption limit.
            if (mb_strlen($caption) <= 900) {
                $stream = null;
                try {
                    $png = $this->qr->render($url);
                    $stream = fopen('php://memory', 'w+b');
                    if ($stream === false || fwrite($stream, $png) !== strlen($png)) {
                        throw new \RuntimeException('QR stream unavailable.');
                    }
                    rewind($stream);
                    $bot->sendPhoto(photo: InputFile::make($stream, 'denagate-trial-qr.png'),
                        caption: $caption, parse_mode: 'HTML', reply_markup: $keyboard);

                    return;
                } catch (Throwable) {
                    // Preserve private link access when QR rendering/upload is unavailable.
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            }
            $bot->sendMessage(text: $text,
                parse_mode: 'HTML', reply_markup: $keyboard,
                link_preview_options: LinkPreviewOptions::make(is_disabled: true));
        } catch (Throwable) {
            $bot->sendMessage(text: 'لینک تست فعلاً قابل دریافت نیست. وضعیت یا زمان و حجم باقی‌مانده را بررسی کنید.', reply_markup: $this->buttons());
        }
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::SALES) {
            return null;
        }
        $id = $this->privateUser->id($bot);

        return $id === null ? null : $this->actors->handle($id);
    }

    private static function key(int $userId): string
    {
        return 'telegram:sales:trial:'.$userId;
    }

    private function buttons(bool $connection = false, bool $guide = false, ?string $copyLink = null): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        if ($connection) {
            $keyboard->addRow(InlineKeyboardButton::make('🔐 لینک و کد اتصال',
                callback_data: 'sales:trial:link', style: ButtonStyle::SUCCESS));
        }
        if ($copyLink !== null && mb_strlen($copyLink) <= 256) {
            $keyboard->addRow(InlineKeyboardButton::make('📋 کپی لینک',
                copy_text: CopyTextButton::make($copyLink)));
        }
        if ($guide) {
            $keyboard->addRow(InlineKeyboardButton::make('📚 راهنمای اتصال', callback_data: 'sales:help:apps'));
        }

        return $keyboard
            ->addRow(InlineKeyboardButton::make('🔄 وضعیت تست', callback_data: 'sales:trial'))
            ->addRow(InlineKeyboardButton::make('🛒 خرید اشتراک', callback_data: 'sales:buy'))
            ->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'),
                InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    /**
     * Presentation only. The native Contact/Confirm flow and ended-trial
     * reply keyboard are deliberately excluded from Rich transport.
     */
    private function renderOverview(
        Nutgram $bot,
        string $text,
        string $html,
        InlineKeyboardMarkup $keyboard,
        bool $rich,
        bool $acknowledge = true,
    ): void {
        if (! $rich) {
            $this->render($bot, $text, $keyboard, $acknowledge);

            return;
        }
        $plainKeyboard = clone $keyboard;
        $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:trial:simple'));
        if ($acknowledge) {
            TelegramUi::answerCallback($bot);
        }
        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);
        } catch (TelegramException $exception) {
            report($exception);
            $this->render($bot, $text, $plainKeyboard, false);
        }
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, bool $acknowledge = true): void
    {
        if ($bot->isCallbackQuery()) {
            if ($acknowledge) {
                TelegramUi::answerCallback($bot);
            }
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');
        } else {
            $bot->sendMessage(text: $text, parse_mode: 'HTML', reply_markup: $keyboard);
        }
    }
}
