<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\ReverseUnusedWalletTopup;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** Private Admin transport only. Billing remains authoritative for every write. */
final readonly class AdminWalletReversalUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private AssertBillingAccess $access,
        private BrowseCustomerWallets $wallets,
        private ReverseUnusedWalletTopup $reversals,
        private MenuRenderer $menus,
    ) {}

    public function history(Nutgram $bot, string $userId, string $page): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root->userId));
        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $userId) !== 1
            || preg_match('/\A[1-9][0-9]{0,3}\z/D', $page) !== 1) {
            $this->notice($bot, 'شناسه یا صفحه معتبر نیست.');

            return;
        }

        $user = (int) $userId;
        $number = (int) $page;
        try {
            $wallet = $this->wallets->detail($root->userId, $user);
            $rows = $this->wallets->correctionCandidates($root->userId, $user, $number);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'سوابق این کیف پول در دسترس نیست.');

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['<b>↩️ اصلاح تراکنش کیف پول</b>', '',
            'کاربر: '.TelegramUi::escape($wallet['name']).' (#'.$user.')',
            'فقط شارژ تأییدشده و مصرف‌نشده قابل اصلاح است.', ''];
        $table = '';
        foreach (array_slice($rows, 0, 8) as $row) {
            $label = $row['status'] === 'voided' ? '↩️ اصلاح‌شده' : '✅ تأییدشده';
            $lines[] = '#'.$row['id'].' · '.TelegramUi::toman($row['amount_rials']).' تومان · '.$label;
            $table .= self::cell('#'.$row['id'].' · '.$label, TelegramUi::toman($row['amount_rials']).' تومان');
            if ($row['status'] === 'approved') {
                $keyboard->addRow(InlineKeyboardButton::make('بررسی شارژ #'.$row['id'],
                    callback_data: 'finance:reverse:prepare:'.$user.':'.$row['id']));
            }
        }
        if ($rows === []) {
            $lines[] = 'شارژ تأییدشده یا اصلاح‌شده‌ای پیدا نشد.';
            $table = self::cell('سوابق', 'موردی پیدا نشد');
        }

        $pages = [];
        if ($number > 1) {
            $pages[] = InlineKeyboardButton::make('◀️ قبلی',
                callback_data: 'finance:reverse:list:'.$user.':'.($number - 1));
        }
        if (count($rows) > 8) {
            $pages[] = InlineKeyboardButton::make('بعدی ▶️',
                callback_data: 'finance:reverse:list:'.$user.':'.($number + 1));
        }
        if ($pages !== []) {
            $keyboard->addRow(...$pages);
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ جزئیات کیف پول',
            callback_data: 'finance:wallet:'.$user));

        $html = '<h2>↩️ اصلاح تراکنش کیف پول</h2><table bordered striped compact>'
            .self::cell('کاربر', $wallet['name'].' (#'.$user.')')
            .self::cell('موجودی', TelegramUi::toman($wallet['balance_rials']).' تومان')
            .'</table><h3>شارژها</h3><table bordered striped compact>'.$table
            .'</table><footer>💡 اعتبار مصرف‌شده خودکار اصلاح نمی‌شود. صفحه '.number_format($number).'</footer>';
        $this->render($bot, implode("\n", $lines), $keyboard, $html);
    }

    public function prepare(Nutgram $bot, string $userId, string $topupId): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->key($root->userId));
        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $userId) !== 1
            || preg_match('/\A[1-9][0-9]{0,14}\z/D', $topupId) !== 1) {
            $this->notice($bot, 'تراکنش معتبر نیست.');

            return;
        }
        $user = (int) $userId;
        $id = (int) $topupId;
        try {
            $state = $this->reversals->preview($root->userId, $user, $id);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'اصلاح این تراکنش مجاز نیست.');

            return;
        }

        if (! $state['eligible']) {
            $reason = $state['block_reason'] ?? 'این تراکنش قابل اصلاح نیست.';
            $this->render($bot,
                '<b>⚠️ نیازمند بررسی</b>'."\n\nشارژ #{$id} · "
                    .TelegramUi::toman($state['amount_rials']).' تومان'
                    ."\n\n".TelegramUi::escape($reason)
                    ."\n\nهیچ تغییری در حساب کاربر انجام نشد.",
                $this->back($user),
                '<h3>⚠️ نیازمند بررسی</h3><table bordered striped compact>'
                    .self::cell('شارژ', '#'.$id)
                    .self::cell('مبلغ', TelegramUi::toman($state['amount_rials']).' تومان')
                    .self::cell('نتیجه', $reason)
                    .'</table><footer>هیچ تغییری در حساب انجام نشد؛ بررسی مدیریتی لازم است.</footer>');

            return;
        }

        Cache::put($this->key($root->userId), [
            'kind' => 'reverse_reason', 'user_id' => $user, 'topup_id' => $id,
            'amount_rials' => $state['amount_rials'], 'balance_rials' => $state['balance_rials'],
        ], now()->addMinutes(10));

        $this->render($bot, '<b>↩️ اصلاح تراکنش</b>'."\n\nشارژ #{$id}"
            ."\nمبلغ: <b>".TelegramUi::toman($state['amount_rials']).' تومان</b>'
            ."\nمانده فعلی: ".TelegramUi::toman($state['balance_rials']).' تومان'
            ."\n\nدلیل مستند اصلاح را ارسال کنید (حداکثر ۲۰۰ نویسه)."
            ."\n\n<blockquote>این مسیر فقط برای اصلاح واریز اشتباه یا فاقد دریافت واقعی وجه است؛ بازپرداخت بانکی انجام نمی‌دهد.</blockquote>",
            $this->back($user));
    }

    public function text(Nutgram $bot, string $value): bool
    {
        $chat = $this->private->id($bot);
        if ($chat === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return false;
        }
        $linked = $this->actors->handle($chat);
        if ($linked === null) {
            return false;
        }
        $key = $this->key($linked->userId);
        $state = Cache::get($key);
        if (! is_array($state) || ! in_array($state['kind'] ?? null, ['reverse_reason', 'reverse_confirm'], true)) {
            return false;
        }
        $root = $this->root($bot);
        if ($root === null) {
            return true;
        }

        $user = (int) $state['user_id'];
        $topup = (int) $state['topup_id'];
        $back = $this->back($user);

        if (str_starts_with(trim($value), '/') || in_array($value, ReplyMenuLabels::all(), true)) {
            Cache::forget($key);

            return false;
        }
        if ($state['kind'] === 'reverse_confirm') {
            $this->render($bot, 'برای ادامه «تأیید نهایی» را بزنید یا انصراف دهید.', $back);

            return true;
        }

        $reason = trim($value);
        if ($reason === '' || mb_strlen($reason) > 200) {
            $this->render($bot, 'دلیل را در یک جمله و حداکثر ۲۰۰ نویسه وارد کنید.', $back);

            return true;
        }
        try {
            $fresh = $this->reversals->preview($root->userId, $user, $topup);
        } catch (AuthorizationException|ValidationException) {
            Cache::forget($key);
            $this->notice($bot, 'تراکنش دیگر قابل بررسی نیست.');

            return true;
        }
        if (! $fresh['eligible'] || $fresh['balance_rials'] !== (int) $state['balance_rials']
            || $fresh['amount_rials'] !== (int) $state['amount_rials']) {
            Cache::forget($key);
            $this->render($bot, '⚠️ وضعیت کیف پول تغییر کرده است. اصلاحی انجام نشد.', $back);

            return true;
        }

        $state['reason'] = $reason;
        $state['kind'] = 'reverse_confirm';
        $state['token'] = bin2hex(random_bytes(12));
        Cache::put($key, $state, now()->addMinutes(3));

        $balanceAfter = $fresh['balance_rials'] - $fresh['amount_rials'];
        $html = '<h2>⚠️ تأیید نهایی اصلاح کیف پول</h2><table bordered striped compact>'
            .self::cell('کاربر', '#'.$user)
            .self::cell('شارژ', '#'.$topup)
            .self::cell('مبلغ اصلاح', TelegramUi::toman($fresh['amount_rials']).' تومان')
            .self::cell('مانده فعلی', TelegramUi::toman($fresh['balance_rials']).' تومان')
            .self::cell('مانده پس از اصلاح', TelegramUi::toman($balanceAfter).' تومان')
            .self::cell('شناسه بانکی', $fresh['reference'] ?? 'ندارد')
            .self::cell('دلیل', $reason)
            .'</table><footer>فقط واریز نامعتبر را اصلاح کنید؛ سند اصلی حذف نمی‌شود. '
            .'خرید، سرویس و بازپرداخت بانکی تغییر نمی‌کنند.</footer>';
        $plain = '<b>⚠️ تأیید نهایی اصلاح</b>'
            ."\n\nکاربر: #{$user} · شارژ #{$topup}"
            ."\nمبلغ: ".TelegramUi::toman($fresh['amount_rials']).' تومان'
            ."\nمانده فعلی: ".TelegramUi::toman($fresh['balance_rials']).' تومان'
            ."\nمانده پس از اصلاح: <b>".TelegramUi::toman($balanceAfter).' تومان</b>'
            ."\nشناسه بانک: ".TelegramUi::code($fresh['reference'] ?? '-')
            ."\nدلیل: ".TelegramUi::escape($reason)
            ."\n\n<blockquote>فقط پس از بررسی قطعی نامعتبر بودن واریز تأیید کنید. سند اصلی حذف نمی‌شود.</blockquote>";
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('↩️ تأیید و ثبت سند معکوس',
                callback_data: 'finance:reverse:apply:'.$state['token'], style: ButtonStyle::DANGER))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'finance:wallet:'.$user));
        $this->render($bot, $plain, $keyboard, $html);

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            $this->notice($bot, 'تأیید اصلاح نامعتبر است.');

            return;
        }
        $key = $this->key($root->userId);
        try {
            $draft = Cache::lock($key.':reverse:apply', 15)->block(2, static function () use ($key, $token): ?array {
                $state = Cache::get($key);
                if (! is_array($state) || ($state['kind'] ?? null) !== 'reverse_confirm'
                    || ! hash_equals((string) ($state['token'] ?? ''), $token)) {
                    return null;
                }
                Cache::forget($key);

                return $state;
            });

            if ($draft === null) {
                $this->notice($bot, 'تأیید منقضی شده یا قبلاً استفاده شده است.');

                return;
            }

            $user = (int) $draft['user_id'];
            $topup = (int) $draft['topup_id'];
            $journal = $this->reversals->handle(
                $root->userId, $user, $topup,
                (int) $draft['balance_rials'], (string) $draft['reason'],
            );

            $this->render($bot, '<b>✅ اصلاح تراکنش ثبت شد.</b>'
                ."\n\nشارژ #{$topup} · ".TelegramUi::toman((int) $draft['amount_rials']).' تومان'
                ."\nسند معکوس: <b>#{$journal}</b>"
                ."\n\nسوابق اصلی محفوظ است؛ هیچ سفارش یا سرویسی تغییر نکرد.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('💼 جزئیات کیف پول',
                        callback_data: 'finance:wallet:'.$user)));
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'اصلاح انجام نشد؛ مانده، تراکنش یا دسترسی تغییر کرده است. سوابق را دوباره بررسی کنید.');
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'ثبت اصلاح تأیید نشد؛ پیش از تلاش مجدد گردش کیف پول را بررسی کنید.');
        }
    }

    private function root(Nutgram $bot): ?TelegramActor
    {
        $chat = $this->private->id($bot);
        if ($chat === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $actor = $this->actors->handle($chat);
        try {
            if ($actor === null || ! $actor->canManageRoles()) {
                throw new AuthorizationException;
            }
            $this->access->reviewer($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function key(int $operatorId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:finance:wallet:'.$operatorId);
    }

    private function back(int $userId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ انصراف / سوابق',
                callback_data: 'finance:reverse:list:'.$userId.':1'));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, '<b>💳 امور مالی</b>'."\n\n".$message,
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ کیف پول کاربران', callback_data: 'finance:wallets:1')));
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
        $message = $bot->isCallbackQuery() ? $bot->message() : null;
        $media = ($message->photo ?? []) !== [] || $message?->document !== null
            || $message?->video !== null || $message?->animation !== null
            || $message?->audio !== null || $message?->voice !== null || $message?->sticker !== null;

        if ($richHtml !== null) {
            try {
                $rich = new InputRichMessage(html: $richHtml, is_rtl: true);
                if ($media) {
                    $bot->sendRichMessage(rich_message: $rich, reply_markup: $keyboard);
                } else {
                    TelegramUi::editRichText($bot, $rich, $keyboard);
                }

                return;
            } catch (TelegramException $exception) {
                report($exception);
            }
        }

        if ($bot->isCallbackQuery() && ! $media) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
