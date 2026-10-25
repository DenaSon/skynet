<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\WalletTopupNotificationOutbox;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Root-only finance read/review screens; Billing Actions retain financial authority. */
final readonly class AdminFinanceWalletUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private AssertBillingAccess $access,
        private BrowseCustomerWallets $wallets,
        private ReadAdminWalletSummary $summary,
        private BrowsePhotoTopups $photos,
        private BillingReviewTokens $tokens,
        private ReviewTopup $review,
        private WalletTopupNotificationOutbox $notifications,
        private TelegramNotificationSender $sender,
        private MenuRenderer $menus,
    ) {}

    public function wallets(Nutgram $bot, string $page = '1'): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $this->clear($actor->userId);
        $number = $this->page($page);
        if ($number === null) {
            $this->notice($bot, 'صفحه معتبر نیست.');

            return;
        }
        $rows = $this->wallets->page($actor->userId, $number);
        $summary = $this->summary->handle($actor->userId);
        $next = count($rows) > 8;
        $keyboard = InlineKeyboardMarkup::make();
        $lines = [
            '<b>💼 کیف پول کاربران</b>',
            '',
            'مجموع مانده: <b>'.number_format(intdiv($summary['balanceRials'], 10)).' تومان</b>',
            '👤 کاربران عادی: '.TelegramUi::toman($summary['memberBalanceRials']).' تومان',
            '🧩 پنل‌های فروش: '.TelegramUi::toman($summary['resellerBalanceRials']).' تومان',
            'کل کیف پول‌ها: '.number_format($summary['walletsTotal']),
            'دارای موجودی: '.number_format($summary['walletsPositive']),
            'شارژهای منتظر تأیید: '.number_format($summary['pendingTopups']),
        ];
        if ($summary['otherBalanceRials'] !== 0) {
            $lines[] = '👥 سایر حساب‌ها: '.TelegramUi::toman($summary['otherBalanceRials']).' تومان';
        }
        if ($summary['walletsNegative'] > 0) {
            $lines[] = '⚠️ مانده منفی نیازمند بررسی: '.number_format($summary['walletsNegative']);
        }
        $lines[] = '<blockquote>مانده از گردش مالی تأییدشده محاسبه می‌شود؛ درآمد فروش نیست.</blockquote>';
        $lines[] = '';
        $lines[] = '<b>فهرست کیف پول‌ها</b>';
        foreach (array_slice($rows, 0, 8) as $row) {
            $name = TelegramUi::escape(mb_substr($row['name'], 0, 45));
            $lines[] = self::accountLabel($row['account_type']).' · #'.$row['id'].' · '.$name.' · <b>'.TelegramUi::toman($row['balance_rials']).' تومان</b>';
            $keyboard->addRow(InlineKeyboardButton::make(
                'مشاهده #'.$row['id'], callback_data: 'finance:wallet:'.$row['id'],
            ));
        }
        if ($rows === []) {
            $lines[] = 'کیف پول ثبت‌شده‌ای پیدا نشد.';
        }
        $arrows = [];
        if ($number > 1) {
            $arrows[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'finance:wallets:'.($number - 1));
        }
        if ($next) {
            $arrows[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'finance:wallets:'.($number + 1));
        }
        if ($arrows !== []) {
            $keyboard->addRow(...$arrows);
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔎 جستجو با شناسه کاربر', callback_data: 'finance:find-wallet'))
            ->addRow(InlineKeyboardButton::make('◀️ امور مالی', callback_data: 'menu:admin:finance'));
        $this->render($bot, trim(implode("\n", $lines)), $keyboard);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $this->clear($actor->userId);
        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) !== 1) {
            $this->notice($bot, 'شناسه کاربر معتبر نیست.');

            return;
        }
        try {
            $row = $this->wallets->detail($actor->userId, (int) $id);
        } catch (ValidationException) {
            $this->notice($bot, 'کیف پول این کاربر پیدا نشد.');

            return;
        }

        $lines = [
            '<b>💼 جزئیات کیف پول</b>', '',
            'کاربر: '.TelegramUi::escape(mb_substr($row['name'], 0, 70)),
            'شناسه: '.TelegramUi::code((string) $row['id']),
            'وضعیت حساب: '.($row['active'] ? '🟢 فعال' : '⏸️ غیرفعال'),
            'نوع حساب: '.self::accountLabel($row['account_type']),
            'موجودی: <b>'.TelegramUi::toman($row['balance_rials']).' تومان</b>',
            '', '<b>گردش‌های اخیر</b>',
        ];
        foreach ($row['entries'] as $entry) {
            $kind = $entry['kind'] === 'topup' ? 'شارژ' : 'پرداخت';
            $sign = $entry['side'] === 'credit' ? '➕' : '➖';
            $lines[] = $sign.' '.$kind.' · '.TelegramUi::toman($entry['amount_rials']).' تومان'
                .' · سند #'.$entry['journal'].' · '.TelegramUi::escape(mb_substr($entry['at'], 0, 16));
            if ($entry['source'] !== '') {
                $lines[] = 'مرجع داخلی: '.TelegramUi::code($entry['source']);
            }
        }
        if ($row['entries'] === []) {
            $lines[] = 'هنوز گردش مالی تأییدشده‌ای وجود ندارد.';
        }
        if ($row['topups'] !== []) {
            $lines[] = '';
            $lines[] = '<b>درخواست‌های شارژ</b>';
            foreach ($row['topups'] as $topup) {
                $line = '#'.$topup['id'].' · '.TelegramUi::toman($topup['amount_rials']).' تومان · '
                    .TelegramUi::escape(TelegramUi::topupStatus($topup['status']));
                if ($topup['reference'] !== null) {
                    $line .= ' · مرجع: '.TelegramUi::code($topup['reference']);
                }
                $lines[] = $line;
            }
        }
        $this->render($bot, implode("\n", $lines), InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کیف پول کاربران', callback_data: 'finance:wallets:1'))
            ->addRow(InlineKeyboardButton::make('🧾 رسیدهای شارژ', callback_data: 'finance:topups:1'))
            ->addRow(InlineKeyboardButton::make('🏠 امور مالی', callback_data: 'menu:admin:finance')));
    }

    public function pending(Nutgram $bot, string $page = '1'): void
    {
        $actor = $this->topupViewer($bot);
        if ($actor === null) {
            return;
        }
        $this->clear($actor->userId);
        $number = $this->page($page);
        if ($number === null) {
            $this->notice($bot, 'صفحه معتبر نیست.');

            return;
        }
        $rows = $this->photos->pending($actor->userId, $number);
        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['<b>🧾 رسیدهای شارژ کیف پول</b>', '', 'فقط درخواست‌های دارای تصویر و در انتظار بررسی نمایش داده می‌شوند.', ''];
        foreach (array_slice($rows, 0, 8) as $row) {
            $lines[] = '#'.$row['id'].' · کاربر #'.$row['user_id'].' · <b>'
                .TelegramUi::toman($row['amount_rials']).' تومان</b>';
            $keyboard->addRow(InlineKeyboardButton::make(
                '🖼️ مشاهده رسید #'.$row['id'], callback_data: 'finance:topup:photo:'.$row['id'],
            ));
        }
        if ($rows === []) {
            $lines[] = 'رسید شارژ منتظر بررسی وجود ندارد.';
        }
        $arrows = [];
        if ($number > 1) {
            $arrows[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'finance:topups:'.($number - 1));
        }
        if (count($rows) > 8) {
            $arrows[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'finance:topups:'.($number + 1));
        }
        if ($arrows !== []) {
            $keyboard->addRow(...$arrows);
        }
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'finance:topups:1'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: $actor->canManageRoles()
                ? 'menu:admin:finance' : 'menu:admin:reviews'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function showPhoto(Nutgram $bot, string $id): void
    {
        $actor = $this->topupViewer($bot);
        if ($actor === null) {
            return;
        }
        if (! $this->enabled()) {
            $this->notice($bot, 'بررسی شارژ فعلاً در دسترس نیست.');

            return;
        }
        $row = $this->photo($actor->userId, $id);
        if ($row === null) {
            $this->notice($bot, 'این رسید دیگر در صف بررسی موجود نیست.');

            return;
        }

        $caption = "🧾 رسید شارژ #{$row['id']}\nکاربر #{$row['user_id']}\nمبلغ: "
            .TelegramUi::toman($row['amount_rials'])." تومان\n\n⚠️ قبل از تأیید، واریز واقعی و مبلغ را در بانک بررسی کنید.";
        try {
            $this->sender->showWalletTopupPhotoToRoot(
                $row['id'], $actor->userId, (int) $this->private->id($bot), $caption,
            );
        } catch (Throwable) {
            $this->notice($bot, 'تصویر فعلاً قابل نمایش نیست. بدون مشاهده رسید و تأیید مستقل بانک، شارژ را تأیید نکنید.');

            return;
        }
        TelegramUi::answerCallback($bot);
    }

    public function decision(Nutgram $bot, string $id, string $decision): void
    {
        $actor = $this->topupReviewer($bot);
        if ($actor === null) {
            return;
        }
        if (! $this->enabled() || ! in_array($decision, ['a', 'r'], true)) {
            $this->notice($bot, 'امکان بررسی این درخواست وجود ندارد.');

            return;
        }
        $row = $this->photo($actor->userId, $id);
        if ($row === null) {
            $this->notice($bot, 'درخواست دیگر در انتظار بررسی نیست.');

            return;
        }
        $this->clear($actor->userId);
        if ($decision === 'a') {
            Cache::put($this->key($actor->userId), ['kind' => 'reference', 'topup' => $row['id']], now()->addMinutes(10));
            $this->render($bot,
                '<b>🔎 بررسی شارژ · مرحله ۱ از ۲</b>'."\n\nدرخواست #{$row['id']} · کاربر #{$row['user_id']}"
                ."\nمبلغ: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
                ."\n\nهنوز شارژی ثبت نشده است. پس از تطبیق مبلغ در بانک، شناسه <b>واقعی و یکتای تراکنش</b> را ارسال کنید."
                ."\n<blockquote>تصویر رسید به‌تنهایی دلیل واریز نیست. مبلغ و دریافت وجه باید جداگانه بررسی شوند.</blockquote>",
                $this->backTopups());

            return;
        }

        $token = $this->tokens->issue($actor->userId, $row['id'], false, 'PHOTO-REJECT');
        $this->render($bot,
            '<b>❌ رد رسید شارژ</b>'."\n\nدرخواست #{$row['id']} · کاربر #{$row['user_id']}"
            ."\nمبلغ: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
            ."\n\nاین تصمیم بدون افزایش موجودی ثبت می‌شود. تأیید می‌کنید؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('❌ تأیید رد رسید', callback_data: 'finance:topup:apply:'.$token, style: ButtonStyle::DANGER))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'finance:topups:1')));
    }

    public function find(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $this->clear($actor->userId);
        Cache::put($this->key($actor->userId), ['kind' => 'find'], now()->addMinutes(10));
        $this->render($bot, '<b>🔎 جستجوی کیف پول</b>'."\n\nشناسه عددی کاربر را ارسال کنید.", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کیف پول کاربران', callback_data: 'finance:wallets:1')));
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
        $state = Cache::get($this->key($linked->userId));
        if (! is_array($state) || ! in_array($state['kind'] ?? null, ['find', 'reference'], true)) {
            return false;
        }
        $actor = $state['kind'] === 'find' ? $this->root($bot) : $this->topupReviewer($bot);
        if ($actor === null) {
            return true;
        }

        if ($state['kind'] === 'find') {
            $id = trim($value);
            if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) !== 1) {
                $this->notice($bot, 'شناسه عددی معتبر کاربر را وارد کنید.');

                return true;
            }
            $this->detail($bot, $id);

            return true;
        }

        if (! $this->enabled() || ! is_int($state['topup'] ?? null)) {
            $this->clear($actor->userId);
            $this->notice($bot, 'زمان بررسی تمام شده است؛ از صف شارژ دوباره شروع کنید.');

            return true;
        }
        $reference = strtoupper(trim($value));
        if (preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1) {
            $this->notice($bot, 'شناسه بانکی معتبر (۶ تا ۶۴ نویسه انگلیسی یا عدد) وارد کنید.');

            return true;
        }
        $row = $this->photo($actor->userId, (string) $state['topup']);
        $this->clear($actor->userId);
        if ($row === null) {
            $this->notice($bot, 'این رسید دیگر در صف بررسی نیست.');

            return true;
        }

        $token = $this->tokens->issue($actor->userId, $row['id'], true, $reference);
        $this->render($bot,
            '<b>✅ تأیید نهایی شارژ · مرحله ۲ از ۲</b>'."\n\nکاربر #{$row['user_id']} · درخواست #{$row['id']}"
            ."\nمبلغ: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
            ."\nشناسه بانک: ".TelegramUi::code($reference)
            ."\n\n<b>تا زدن دکمه «تأیید و افزایش موجودی»، شارژ در صف باقی می‌ماند.</b>"
            ."\nبا تأیید نهایی، این مبلغ فقط یک‌بار اضافه می‌شود. آیا دریافت واقعی وجه را بررسی کرده‌اید؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید و افزایش موجودی', callback_data: 'finance:topup:apply:'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'finance:topups:1')));

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->topupReviewer($bot);
        if ($actor === null) {
            return;
        }
        $data = $this->tokens->consume($actor->userId, $token);
        if ($data === null) {
            $this->notice($bot, 'این تأیید منقضی، نامعتبر یا قبلاً استفاده شده است.');

            return;
        }
        if (! $this->enabled() || $this->photo($actor->userId, (string) $data['topup']) === null) {
            $this->notice($bot, 'درخواست دیگر قابل بررسی نیست.');

            return;
        }
        try {
            DB::transaction(function () use ($actor, $data): void {
                $this->review->handle($actor->userId, $data['topup'], $data['approve'], $data['reference']);
                $this->notifications->reviewed($data['topup']);
            });
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'پرداخت ثبت نشد؛ درخواست تغییر کرده یا شناسه بانک قبلاً استفاده شده است.');

            return;
        }
        $this->render($bot,
            $data['approve']
                ? '✅ شارژ تأیید شد و دقیقاً یک‌بار در گردش کیف پول ثبت شد.'
                : '❌ رسید رد شد؛ موجودی افزایش نیافت.',
            $this->backTopups());
    }

    private static function accountLabel(string $accountType): string
    {
        return match ($accountType) {
            'reseller' => '🧩 پنل فروش',
            'member' => '👤 عادی',
            default => '👥 سایر',
        };
    }

    private function enabled(): bool
    {
        return config('billing.topups_enabled', false) === true;
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

    private function topupViewer(Nutgram $bot): ?TelegramActor
    {
        $chat = $this->private->id($bot);
        if ($chat === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $actor = $this->actors->handle($chat);
        try {
            if ($actor === null) {
                throw new AuthorizationException;
            }
            $this->access->topupViewer($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function topupReviewer(Nutgram $bot): ?TelegramActor
    {
        $actor = $this->topupViewer($bot);
        if ($actor === null) {
            return null;
        }
        try {
            $this->access->topupReviewer($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    /** @return array{id:int,user_id:int,name:string,amount_rials:int,file_id:string,source_bot:string}|null */
    private function photo(int $rootId, string $id): ?array
    {
        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) !== 1) {
            return null;
        }
        try {
            return $this->photos->photo($rootId, (int) $id);
        } catch (Throwable) {
            return null;
        }
    }

    private function page(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,3}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function key(int $operatorId): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:finance:wallet:'.$operatorId);
    }

    private function clear(int $operatorId): void
    {
        Cache::forget($this->key($operatorId));
    }

    private function backTopups(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ صف رسیدهای شارژ', callback_data: 'finance:topups:1'))
            ->addRow(InlineKeyboardButton::make('🏠 امور مالی', callback_data: 'menu:admin:finance'));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, '<b>💳 امور مالی</b>'."\n\n".$message, $this->backTopups());
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            $message = $bot->message();
            if (($message->photo ?? []) !== [] || $message?->document !== null
                || $message?->video !== null || $message?->animation !== null
                || $message?->audio !== null || $message?->voice !== null || $message?->sticker !== null) {
                $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');

                return;
            }
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
