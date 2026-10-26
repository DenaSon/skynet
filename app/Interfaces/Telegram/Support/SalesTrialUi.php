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
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\KeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\Keyboard\ReplyKeyboardRemove;
use SergiX44\Nutgram\Telegram\Types\Message\LinkPreviewOptions;
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
            $bot->sendMessage(text: 'دریافت شماره بسته شد.', reply_markup: ReplyKeyboardRemove::make(remove_keyboard: true));
        }
    }

    public function visible(TelegramActor $actor, int $telegramId): bool
    {
        if (config('trial.enabled') !== true) {
            return false;
        }
        try {
            return $this->trials->find($actor->userId, $telegramId) !== null
                || $this->eligible($actor->userId, $telegramId);
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

    public function show(Nutgram $bot): void
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
                $this->render($bot, '<b>🧪 تست رایگان DenaGate</b>'
                    ."\n\nحجم: <b>۲۵۰ مگابایت</b> · اعتبار: <b>۲ ساعت</b>"
                    ."\nبا پایان زمان یا حجم، تست تمام می‌شود و قابل تمدید نیست."
                    ."\n\nهر حساب و شماره فقط یک بار، ویژه کاربران بدون خرید قبلی."
                    ."\nبرای دریافت، شماره همین حساب تلگرام را با دکمه ارسال و سپس تأیید کنید.",
                    InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('📱 ارسال شماره من',
                        callback_data: 'sales:trial:contact', style: ButtonStyle::SUCCESS))
                        ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home')));

                return;
            }
            if (! in_array($trial['state'], ['active', 'ended'], true)) {
                $state = match ($trial['state']) {
                    'reserved', 'provisioning' => '⏳ درخواست ثبت شده و تست در حال آماده‌سازی است.',
                    'failed' => 'فعال‌سازی انجام نشد. برای راهنمایی با پشتیبانی گفتگو کنید.',
                    default => 'نتیجه فعال‌سازی در حال بررسی است. لطفاً منتظر بمانید یا با پشتیبانی گفتگو کنید.',
                };
                $this->render($bot, '<b>🧪 تست رایگان</b>'."\n\n".$state, $this->buttons());

                return;
            }
            TelegramUi::answerCallback($bot);
            $live = $this->trials->status($actor->userId, (int) $this->privateUser->id($bot));
            $minutes = (int) ceil($live->remainingSeconds / 60);
            $label = $live->ended() ? 'تست به پایان رسیده است' : match ($live->providerStatus) {
                'ACTIVE' => '🟢 فعال', 'DISABLED' => '⏸️ غیرفعال', default => 'فعلاً قابل استفاده نیست',
            };
            $keyboard = $this->buttons();
            if (! $live->ended() && $live->providerStatus === 'ACTIVE') {
                $keyboard->addRow(InlineKeyboardButton::make('🔐 لینک و کد اتصال', callback_data: 'sales:trial:link', style: ButtonStyle::SUCCESS));
            }
            $this->render($bot, '<b>🧪 تست رایگان</b>'."\n\nوضعیت: {$label}"
                ."\nحجم باقی‌مانده: <b>".number_format($live->remainingBytes / 1048576, 1).' مگابایت</b>'
                ."\nزمان باقی‌مانده: <b>".intdiv($minutes, 60).' ساعت و '.($minutes % 60).' دقیقه</b>', $keyboard, false);
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
            $bot->sendMessage(text: '📱 شماره متصل به همین حساب تلگرام را فقط با دکمه زیر ارسال کنید. برای انصراف /cancel را بفرستید.',
                reply_markup: ReplyKeyboardMarkup::make(resize_keyboard: true, one_time_keyboard: true)
                    ->addRow(KeyboardButton::make('📱 اشتراک‌گذاری شماره من', request_contact: true))
                    ->addRow(KeyboardButton::make('❌ انصراف')));
        } catch (Throwable) {
            $this->render($bot, 'دریافت تست فعلاً ممکن نیست. شرایط حساب یا وضعیت تست را بررسی کنید.', $this->buttons());
        }
    }

    public function contact(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }
        $draft = Cache::get(self::key($actor->userId));
        $message = $bot->message();
        $contact = $message?->contact;
        try {
            TrialAvailability::assertReady();
            if (! is_array($draft) || ($draft['stage'] ?? null) !== 'contact'
                || ($draft['telegram_id'] ?? null) !== $this->privateUser->id($bot) || $contact === null
                || $message->forward_origin !== null || $message->via_bot !== null) {
                throw new \RuntimeException('No native contact request.');
            }
            $verified = $this->admission->contact($actor->userId, (int) $this->privateUser->id($bot),
                $contact->user_id, $contact->phone_number);
            $token = bin2hex(random_bytes(16));
            Cache::put(self::key($actor->userId), ['stage' => 'confirm', 'telegram_id' => $this->privateUser->id($bot), 'token' => $token, ...$verified], now()->addMinutes(5));
            $bot->sendMessage(text: 'شماره دریافت شد.', reply_markup: ReplyKeyboardRemove::make(remove_keyboard: true));
            $bot->sendMessage(text: '<b>✅ تأیید تست رایگان</b>'."\n\nشماره: ".TelegramUi::code($verified['masked'])
                ."\nحجم: <b>۲۵۰ مگابایت</b> · اعتبار: <b>۲ ساعت</b>"
                ."\n\nبا تأیید، تنها فرصت تست این حساب و شماره ثبت می‌شود.", parse_mode: 'HTML',
                reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('✅ دریافت تست رایگان',
                    callback_data: 'sales:trial:confirm:'.$token, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'sales:trial:cancel')));
        } catch (Throwable) {
            $bot->sendMessage(text: 'شماره تأیید نشد. فقط شماره خودتان را با دکمه «اشتراک‌گذاری شماره من» ارسال کنید یا /cancel را بزنید.');
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
        $bot->sendMessage(text: 'دریافت تست لغو شد.', reply_markup: ReplyKeyboardRemove::make(remove_keyboard: true));
        $bot->sendMessage(text: 'برای ادامه، از منو یا دکمه خانه استفاده کنید.', reply_markup: $this->buttons());
    }

    public function invalidInput(Nutgram $bot): bool
    {
        $actor = $this->actor($bot);
        if ($actor === null || ! Cache::has(self::key($actor->userId))) {
            return false;
        }
        $bot->sendMessage(text: 'شماره متنی پذیرفته نمی‌شود. از دکمه ارسال شماره یا تأیید تست استفاده کنید. برای انصراف /cancel را بزنید.');

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
            $keyboard = $this->buttons();
            if (mb_strlen($url) <= 256) {
                $keyboard->addRow(InlineKeyboardButton::make('📋 کپی لینک', copy_text: CopyTextButton::make($url)));
            }
            $stream = fopen('php://memory', 'w+b');
            try {
                if ($stream === false) {
                    throw new \RuntimeException('QR stream unavailable.');
                }
                fwrite($stream, $this->qr->render($url));
                rewind($stream);
                $bot->sendPhoto(photo: InputFile::make($stream, 'denagate-trial-qr.png'),
                    caption: '🧪 کد اتصال تست رایگان', reply_markup: $keyboard, protect_content: true);
            } catch (Throwable) {
                // Preserve private link access when QR rendering/upload is unavailable.
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $bot->sendMessage(text: '<b>🔐 لینک اتصال تست رایگان</b>'."\n\n".TelegramUi::code($url),
                parse_mode: 'HTML', reply_markup: $keyboard, protect_content: true,
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

    private function buttons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 وضعیت تست', callback_data: 'sales:trial'))
            ->addRow(InlineKeyboardButton::make('🛒 خرید اشتراک', callback_data: 'sales:buy'))
            ->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'),
                InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
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
