<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\TelegramNotificationSender;
use App\Interfaces\Telegram\Notifications\WalletTopupNotificationOutbox;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\BrowseCustomerWallets;
use App\Modules\Billing\Application\BrowsePhotoTopups;
use App\Modules\Billing\Application\CreditVerifiedAdminBankTopup;
use App\Modules\Billing\Application\ReadAdminWalletSummary;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
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
        private CreditVerifiedAdminBankTopup $adminCredits,
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
        $t = static fn (string $key, string $value): string => '<tr><td>'.TelegramUi::escape($key)
            .'</td><td>'.TelegramUi::escape($value).'</td></tr>';
        $summaryTable = $t('مجموع مانده', TelegramUi::toman($summary['balanceRials']).' تومان')
            .$t('کاربران عادی', TelegramUi::toman($summary['memberBalanceRials']).' تومان')
            .$t('پنل‌های فروش', TelegramUi::toman($summary['resellerBalanceRials']).' تومان')
            .$t('کل کیف پول‌ها', number_format($summary['walletsTotal']))
            .$t('دارای موجودی', number_format($summary['walletsPositive']))
            .$t('شارژهای منتظر تأیید', number_format($summary['pendingTopups']));
        if ($summary['otherBalanceRials'] !== 0) {
            $summaryTable .= $t('سایر حساب‌ها', TelegramUi::toman($summary['otherBalanceRials']).' تومان');
        }
        if ($summary['walletsNegative'] > 0) {
            $summaryTable .= $t('⚠️ مانده منفی نیازمند بررسی', number_format($summary['walletsNegative']));
        }
        $walletTable = '';
        foreach (array_slice($rows, 0, 8) as $row) {
            $walletTable .= $t('#'.$row['id'].' · '.mb_substr($row['name'], 0, 45)
                .' · '.self::accountLabel($row['account_type']), TelegramUi::toman($row['balance_rials']).' تومان');
        }
        if ($rows === []) {
            $walletTable = $t('فهرست', 'کیف پول ثبت‌شده‌ای پیدا نشد.');
        }
        $html = '<h2>💼 کیف پول کاربران</h2><table bordered striped compact>'.$summaryTable
            .'</table><h3>فهرست کیف پول‌ها</h3><table bordered striped compact>'.$walletTable
            .'</table><footer>💡 مانده از گردش تأییدشده محاسبه می‌شود؛ درآمد فروش نیست.'
            .' | صفحه '.number_format($number).' | '.PersianCalendar::localDateTime(now()->toDateTimeString()).' (تهران)</footer>';
        $this->render($bot, trim(implode("\n", $lines)), $keyboard, $html);
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
            $kind = match ($entry['kind']) {
                'topup' => 'شارژ',
                'admin_verified_topup' => 'افزایش اعتبار توسط مدیر',
                'topup_reversal' => 'اصلاح شارژ',
                'topup_amount_correction' => 'اصلاح مبلغ شارژ',
                'purchase_reversal' => 'اصلاح خرید',
                default => 'پرداخت',
            };
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
                $manual = $topup['origin'] === 'admin_verified_bank';
                $line = ($manual ? 'افزایش اعتبار مدیر · ' : 'شارژ · ').'#'.$topup['id'].' · '
                    .TelegramUi::toman($topup['amount_rials']).' تومان · '
                    .TelegramUi::escape(TelegramUi::topupStatus($topup['status']));
                if ($topup['reference'] !== null) {
                    $line .= ' · مرجع: '.TelegramUi::code($topup['reference']);
                }
                $lines[] = $line;
                if ($manual) {
                    $lines[] = 'دلیل: '.TelegramUi::escape($topup['reason'] ?? 'ثبت نشده')
                        .' · ثبت‌کننده: '.TelegramUi::escape($topup['reviewer'] ?? 'نامشخص');
                }
                if ($topup['reversal_journal_id'] !== null) {
                    $lines[] = '↩️ اصلاح سند #'.$topup['reversal_journal_id'].' · '
                        .TelegramUi::escape($topup['reversal_reason'] ?? 'بدون توضیح')
                        .' · '.TelegramUi::escape($topup['reverser'] ?? 'مدیر');
                }
            }
        }
        $keyboard = InlineKeyboardMarkup::make();
        if ($row['active'] && in_array($row['account_type'], ['member', 'reseller'], true)
            && $this->enabled()) {
            $keyboard->addRow(InlineKeyboardButton::make('افزایش اعتبار',
                callback_data: 'finance:credit:begin:'.$row['id'], style: ButtonStyle::DANGER));
        }
        if ($row['active'] && in_array($row['account_type'], ['member', 'reseller'], true)
            && $row['topups'] !== []) {
            $keyboard->addRow(InlineKeyboardButton::make('↩️ اصلاح تراکنش',
                callback_data: 'finance:reverse:list:'.$row['id'].':1'));
        }
        $keyboard
            ->addRow(InlineKeyboardButton::make('◀️ کیف پول کاربران', callback_data: 'finance:wallets:1'))
            ->addRow(InlineKeyboardButton::make('🧾 رسیدهای شارژ', callback_data: 'finance:topups:1'))
            ->addRow(InlineKeyboardButton::make('🏠 امور مالی', callback_data: 'menu:admin:finance'));
        $t = static fn (string $key, string $value): string => '<tr><td>'.TelegramUi::escape($key)
            .'</td><td>'.TelegramUi::escape($value).'</td></tr>';
        $balance = TelegramUi::toman($row['balance_rials']).' تومان';
        $identity = $t('کاربر', mb_substr($row['name'], 0, 70))
            .$t('شناسه', (string) $row['id'])
            .$t('وضعیت حساب', $row['active'] ? '🟢 فعال' : '⏸️ غیرفعال')
            .$t('نوع حساب', self::accountLabel($row['account_type']))
            .$t('موجودی', $balance);
        $ledger = '';
        foreach ($row['entries'] as $entry) {
            $kind = match ($entry['kind']) {
                'topup' => 'شارژ',
                'admin_verified_topup' => 'افزایش اعتبار توسط مدیر',
                'topup_reversal' => 'اصلاح شارژ',
                'topup_amount_correction' => 'اصلاح مبلغ شارژ',
                'purchase_reversal' => 'اصلاح خرید',
                default => 'پرداخت',
            };
            $source = $entry['source'] !== '' ? ' · مرجع داخلی: '.$entry['source'] : '';
            $ledger .= $t(($entry['side'] === 'credit' ? '➕ ' : '➖ ').$kind.' · سند #'.$entry['journal'],
                TelegramUi::toman($entry['amount_rials']).' تومان · '.mb_substr($entry['at'], 0, 16).$source);
        }
        if ($row['entries'] === []) {
            $ledger = $t('گردش‌ها', 'هنوز گردش مالی تأییدشده‌ای وجود ندارد.');
        }
        $html = '<h2>💼 جزئیات کیف پول</h2><table bordered striped compact>'.$identity.'</table>';
        if ($row['balance_rials'] < 0) {
            $html .= '<blockquote>⚠️ مانده منفی این حساب نیازمند بررسی است.</blockquote>';
        }
        $html .= '<h3>گردش‌های اخیر</h3><table bordered striped compact>'.$ledger.'</table>';
        if ($row['topups'] !== []) {
            $topups = '';
            foreach ($row['topups'] as $topup) {
                $manual = $topup['origin'] === 'admin_verified_bank';
                $reference = $topup['reference'] !== null ? ' · مرجع: '.$topup['reference'] : '';
                $audit = $manual ? ' · دلیل: '.($topup['reason'] ?? 'ثبت نشده')
                    .' · ثبت‌کننده: '.($topup['reviewer'] ?? 'نامشخص') : '';
                $label = $manual ? 'افزایش اعتبار مدیر' : 'شارژ';
                $reversal = $topup['reversal_journal_id'] === null ? ''
                    : ' · اصلاح: سند #'.$topup['reversal_journal_id']
                        .' · دلیل: '.($topup['reversal_reason'] ?? 'ثبت نشده')
                        .' · مدیر: '.($topup['reverser'] ?? 'نامشخص');
                $topups .= $t($label.' #'.$topup['id'].' · '.TelegramUi::topupStatus($topup['status']),
                    TelegramUi::toman($topup['amount_rials']).' تومان'.$reference.$audit.$reversal);
            }
            $html .= '<h3>درخواست‌های شارژ</h3><table bordered striped compact>'.$topups.'</table>';
        }
        $html .= '<footer>💡 مبنای موجودی، گردش‌های تأییدشده است. '
            .PersianCalendar::localDateTime(now()->toDateTimeString()).' (تهران)</footer>';
        $this->render($bot, implode("\n", $lines), $keyboard, $html);
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
        $t = static fn (string $key, string $value): string => '<tr><td>'.TelegramUi::escape($key)
            .'</td><td>'.TelegramUi::escape($value).'</td></tr>';
        $items = '';
        foreach (array_slice($rows, 0, 8) as $row) {
            $items .= $t('#'.$row['id'].' · کاربر #'.$row['user_id'],
                TelegramUi::toman($row['amount_rials']).' تومان · در انتظار بررسی');
        }
        if ($rows === []) {
            $items = $t('صف', 'رسید شارژ منتظر بررسی وجود ندارد.');
        }
        $html = '<h2>🧾 رسیدهای شارژ کیف پول</h2><table bordered striped compact>'.$items
            .'</table><footer>💡 فقط درخواست‌های دارای تصویر و در انتظار بررسی.'
            .' | صفحه '.number_format($number).' | '.PersianCalendar::localDateTime(now()->toDateTimeString()).' (تهران)</footer>';
        $this->render($bot, implode("\n", $lines), $keyboard, $html);
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
        Cache::forget(TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$actor->userId));
        $this->clear($actor->userId);
        if ($decision === 'a') {
            Cache::put($this->key($actor->userId), ['kind' => 'bank_amount', 'topup' => $row['id']], now()->addMinutes(10));
            $this->render($bot,
                '<b>🔎 بررسی شارژ · مرحله ۱ از ۳</b>'."\n\nدرخواست #{$row['id']} · کاربر #{$row['user_id']}"
                ."\nمبلغ: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
                 ."\n\nمبلغ <b>واقعی مشاهده‌شده در گردش بانک</b> را به تومان وارد کنید.\nمثال: <code>900000</code>\nهنوز شارژی ثبت نشده است."
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
        Cache::forget(TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$actor->userId));
        $this->clear($actor->userId);
        Cache::put($this->key($actor->userId), ['kind' => 'find'], now()->addMinutes(10));
        $this->render($bot, '<b>🔎 جستجوی کیف پول</b>'."\n\nشناسه عددی کاربر را ارسال کنید.", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کیف پول کاربران', callback_data: 'finance:wallets:1')));
    }

    /** A private Super Admin starts a bank-verified credit for one customer. */
    public function beginManual(Nutgram $bot, string $userId): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }

        if (! $this->enabled() || preg_match('/\A[1-9][0-9]{0,14}\z/D', $userId) !== 1) {
            $this->notice($bot, 'ثبت افزایش اعتبار در دسترس نیست.');

            return;
        }
        try {
            $wallet = $this->wallets->detail($actor->userId, (int) $userId);
        } catch (ValidationException) {
            $this->notice($bot, 'کاربر موردنظر پیدا نشد.');

            return;
        }

        if (! $wallet['active'] || ! in_array($wallet['account_type'], ['member', 'reseller'], true)) {
            $this->notice($bot, 'فقط کیف پول کاربران فعال عادی یا نماینده قابل شارژ است.');

            return;
        }

        Cache::forget(TelegramBotContext::current()->cacheKey('admin:ops:draft:'.$actor->userId));
        $this->clear($actor->userId);
        Cache::put($this->key($actor->userId), [
            'kind' => 'manual_amount', 'user_id' => (int) $userId,
        ], now()->addMinutes(10));

        $this->render($bot,
            '<b>افزایش اعتبار · مرحله ۱ از ۳</b>'."\n\nکاربر: "
                .TelegramUi::escape($wallet['name']).' (#'.$userId.')'
                ."\nمبلغ واریز تأییدشده را به <b>تومان</b> وارد کنید."
                ."\nنمونه: ".TelegramUi::code('27500')
                ."\n\n<blockquote>فقط وجهی را ثبت کنید که دریافت واقعی آن در بانک بررسی شده است. حداکثر هر ثبت ۱۰ میلیون تومان.</blockquote>",
            $this->manualBack((int) $userId));
    }

    /** Final one-use confirmation; financial mutation is owned by Billing. */
    public function applyManual(Nutgram $bot, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            $this->notice($bot, 'تأیید نامعتبر یا منقضی شده است.');

            return;
        }

        $key = $this->key($actor->userId);
        try {
            $draft = Cache::lock($key.':apply', 15)->block(2, static function () use ($key, $token): ?array {
                $state = Cache::get($key);
                if (! is_array($state) || ($state['kind'] ?? null) !== 'manual_confirm'
                    || ! hash_equals((string) ($state['token'] ?? ''), $token)) {
                    return null;
                }

                Cache::forget($key);

                return $state;
            });

            if ($draft === null) {
                $this->notice($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.');

                return;
            }

            $topupId = $this->adminCredits->handle(
                $actor->userId,
                (int) $draft['user_id'],
                (int) $draft['amount_rials'],
                (string) $draft['reference'],
                (string) $draft['reason'],
            );
            $this->render($bot, '<b>✅ افزایش اعتبار ثبت شد.</b>'
                ."\n\nمبلغ: <b>".TelegramUi::toman((int) $draft['amount_rials']).' تومان</b>'
                ."\nکاربر: #".$draft['user_id']
                ."\nسند شارژ: #{$topupId}"
                ."\n\nاین وجه فقط یک‌بار در کیف پول ثبت شده و هیچ سفارشی خودکار فعال نشده است.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('💼 جزئیات کیف پول',
                        callback_data: 'finance:wallet:'.$draft['user_id']))
                    ->addRow(InlineKeyboardButton::make('📊 گزارش دریافتی‌ها',
                        callback_data: 'finance:cash:30')));
        } catch (AuthorizationException|ValidationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'افزایش اعتبار انجام نشد. وضعیت کاربر، مبلغ و یکتایی شناسه بانک را بررسی کنید.');
        } catch (Throwable $exception) {
            report($exception);
            $this->notice($bot, 'ثبت اعتبار تأیید نشد. پیش از اقدام دوباره، گردش کیف پول را بررسی کنید.');
        }
    }

    /** @param array<string,mixed> $state */
    private function manualText(Nutgram $bot, int $operatorId, array $state, string $value): bool
    {
        $id = (int) ($state['user_id'] ?? 0);
        if ($id < 1 || str_starts_with(trim($value), '/')
            || in_array($value, ReplyMenuLabels::all(), true)) {
            $this->clear($operatorId);

            return false;
        }

        $key = $this->key($operatorId);
        $back = $this->manualBack($id);

        if ($state['kind'] === 'manual_amount') {
            $raw = strtr(trim($value), [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            if (preg_match('/\A(?:[1-9][0-9]*|[1-9][0-9]{0,2}(?:[,٬][0-9]{3})+)\z/u', $raw) !== 1) {
                $this->render($bot, 'مبلغ معتبر به تومان وارد کنید؛ مانند '.TelegramUi::code('27500'), $back);

                return true;
            }
            $toman = (int) str_replace([',', '٬'], '', $raw);
            if ($toman < 1 || $toman > 10_000_000) {
                $this->render($bot, 'مبلغ باید بین ۱ تا ۱۰ میلیون تومان باشد.', $back);

                return true;
            }
            $state['amount_rials'] = $toman * 10;
            $state['kind'] = 'manual_reference';
            Cache::put($key, $state, now()->addMinutes(10));
            $this->render($bot,
                '<b>افزایش اعتبار · مرحله ۲ از ۳</b>'."\n\nمبلغ: <b>"
                    .TelegramUi::toman($state['amount_rials']).' تومان</b>'
                    ."\n\nشناسه <b>واقعی و یکتای تراکنش بانکی</b> را پس از بررسی بانک وارد کنید."
                    ."\n۶ تا ۶۴ نویسه انگلیسی، عدد، خط تیره یا زیرخط.",
                $back);

            return true;
        }

        if ($state['kind'] === 'manual_reference') {
            $reference = strtoupper(trim($value));
            if (preg_match('/\A[A-Z0-9_-]{6,64}\z/D', $reference) !== 1
                || str_starts_with($reference, 'MANUAL-CLAIM-')) {
                $this->render($bot, 'شناسه بانکی واقعی و معتبر وارد کنید (۶ تا ۶۴ نویسه).', $back);

                return true;
            }
            $state['reference'] = $reference;
            $state['kind'] = 'manual_reason';
            Cache::put($key, $state, now()->addMinutes(10));
            $this->render($bot, '<b>افزایش اعتبار · مرحله ۳ از ۳</b>'
                ."\n\nدلیل افزایش اعتبار را کوتاه وارد کنید."
                ."\nمثلاً: واریز اضافه برای سفارش قبلی"
                ."\n\nحداکثر ۲۰۰ نویسه؛ این توضیح در سوابق مدیریتی ثبت می‌شود.", $back);

            return true;
        }

        if ($state['kind'] === 'manual_reason') {
            $reason = trim($value);
            if ($reason === '' || mb_strlen($reason) > 200) {
                $this->render($bot, 'دلیل را در یک جمله و حداکثر ۲۰۰ نویسه وارد کنید.', $back);

                return true;
            }
            try {
                $wallet = $this->wallets->detail($operatorId, $id);
            } catch (ValidationException) {
                $this->clear($operatorId);
                $this->notice($bot, 'کاربر در دسترس نیست.');

                return true;
            }
            if (! $wallet['active'] || ! in_array($wallet['account_type'], ['member', 'reseller'], true)) {
                $this->clear($operatorId);
                $this->notice($bot, 'کیف پول این کاربر دیگر قابل شارژ نیست.');

                return true;
            }

            $state['reason'] = $reason;
            $state['kind'] = 'manual_confirm';
            $state['token'] = bin2hex(random_bytes(12));
            Cache::put($key, $state, now()->addMinutes(3));

            $newBalance = $wallet['balance_rials'] + (int) $state['amount_rials'];
            $lines = '<b>🔎 تأیید نهایی افزایش اعتبار</b>'
                ."\n\nکاربر: ".TelegramUi::escape($wallet['name']).' (#'.$id.')'
                ."\nمبلغ: <b>".TelegramUi::toman($state['amount_rials']).' تومان</b>'
                ."\nموجودی فعلی: ".TelegramUi::toman($wallet['balance_rials']).' تومان'
                ."\nموجودی پیش‌بینی‌شده: ".TelegramUi::toman($newBalance).' تومان'
                ."\nشناسه بانک: ".TelegramUi::code($state['reference'])
                ."\nدلیل: ".TelegramUi::escape($reason)
                ."\n\n<blockquote>با تأیید، دریافت وجه واقعی را گواهی می‌کنید. عملیات غیرقابل تکرار است و سفارش یا سرویس را فعال نمی‌کند.</blockquote>";
            $t = static fn (string $name, string $value): string => '<tr><td>'.TelegramUi::escape($name)
                .'</td><td>'.TelegramUi::escape($value).'</td></tr>';
            $html = '<h3>تأیید نهایی افزایش اعتبار</h3><table bordered striped compact>'
                .$t('کاربر', $wallet['name'].' (#'.$id.')')
                .$t('مبلغ', TelegramUi::toman($state['amount_rials']).' تومان')
                .$t('مانده فعلی', TelegramUi::toman($wallet['balance_rials']).' تومان')
                .$t('مانده پیش‌بینی‌شده', TelegramUi::toman($newBalance).' تومان')
                .$t('شناسه بانک', $state['reference'])
                .$t('دلیل', $reason)
                .'</table><footer>فقط پس از بررسی واقعی بانک تأیید کنید. این عملیات سفارش را فعال نمی‌کند.</footer>';
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('تأیید و افزایش اعتبار',
                    callback_data: 'finance:credit:apply:'.$state['token'], style: ButtonStyle::DANGER))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'finance:wallet:'.$id));

            $this->render($bot, $lines, $keyboard, $html);

            return true;
        }

        $this->render($bot, 'برای انجام عملیات، دکمه تأیید را بزنید یا انصراف دهید.', $back);

        return true;
    }

    private function manualBack(int $userId): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ انصراف',
                callback_data: 'finance:wallet:'.$userId));
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
        if (! is_array($state) || ! in_array($state['kind'] ?? null, [
            'find', 'bank_amount', 'reference', 'manual_amount', 'manual_reference', 'manual_reason', 'manual_confirm',
        ], true)) {
            return false;
        }
        $manual = str_starts_with((string) $state['kind'], 'manual_');
        $actor = $manual || $state['kind'] === 'find' ? $this->root($bot) : $this->topupReviewer($bot);
        if ($actor === null) {
            return true;
        }

        if ($manual) {
            return $this->manualText($bot, $actor->userId, $state, $value);
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
        $row = $this->photo($actor->userId, (string) $state['topup']);
        if ($row === null) {
            $this->clear($actor->userId);
            $this->notice($bot, 'این رسید دیگر در صف بررسی نیست.');

            return true;
        }

        if ($state['kind'] === 'bank_amount') {
            $digits = strtr(trim($value), [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            $digits = str_replace([',', '٬', ' '], '', $digits);
            if (preg_match('/\\A[1-9][0-9]{0,7}\\z/D', $digits) !== 1
                || (int) $digits > 10_000_000) {
                $this->notice($bot, 'مبلغ واقعی بانک را به تومان وارد کنید؛ مانند 900000.');

                return true;
            }
            $bankAmountRials = (int) $digits * 10;
            if ($bankAmountRials !== $row['amount_rials']) {
                $this->render($bot,
                    '<b>⛔ مغایرت مبلغ واریزی</b>'
                    ."\\n\\nدرخواست #{$row['id']}"
                    ."\\nمبلغ درخواست: ".TelegramUi::toman($row['amount_rials']).' تومان'
                    ."\\nمبلغ بانک: ".TelegramUi::toman($bankAmountRials).' تومان'
                    ."\\n\\nتأیید مسدود است. مبلغ را دوباره بررسی کنید یا رسید را رد کنید.",
                    InlineKeyboardMarkup::make()
                        ->addRow(InlineKeyboardButton::make('❌ رد رسید',
                            callback_data: 'finance:topup:decision:'.$row['id'].':r', style: ButtonStyle::DANGER))
                        ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'finance:topups:1')));

                return true;
            }

            Cache::put($this->key($actor->userId), [
                'kind' => 'reference',
                'topup' => $row['id'],
                'verified_amount_rials' => $bankAmountRials,
            ], now()->addMinutes(10));
            $this->render($bot,
                '<b>🔎 بررسی شارژ · مرحله ۲ از ۳</b>'
                ."\\n\\nدرخواست #{$row['id']} · مبلغ بانک: <b>"
                .TelegramUi::toman($bankAmountRials).' تومان</b>'
                ."\\n\\nمبلغ مطابقت دارد. حالا شناسه <b>واقعی و یکتای تراکنش بانکی</b> را وارد کنید.",
                $this->backTopups());

            return true;
        }

        $bankAmountRials = $state['verified_amount_rials'] ?? null;
        if ($state['kind'] !== 'reference' || ! is_int($bankAmountRials)
            || $bankAmountRials !== $row['amount_rials']) {
            $this->clear($actor->userId);
            $this->notice($bot, 'مبلغ بررسی‌شده معتبر نیست. رسید را دوباره از صف باز کنید.');

            return true;
        }
        $reference = strtoupper(trim($value));
        if (preg_match('/\\A[A-Z0-9_-]{6,64}\\z/D', $reference) !== 1) {
            $this->notice($bot, 'شناسه بانکی معتبر (۶ تا ۶۴ نویسه انگلیسی یا عدد) وارد کنید.');

            return true;
        }
        $this->clear($actor->userId);

        $token = $this->tokens->issue($actor->userId, $row['id'], true, $reference, $bankAmountRials);
        $this->render($bot,
            '<b>✅ تأیید نهایی شارژ · مرحله ۳ از ۳</b>'."\\n\\nکاربر #{$row['user_id']} · درخواست #{$row['id']}"
            ."\\nمبلغ درخواست: <b>".TelegramUi::toman($row['amount_rials']).' تومان</b>'
            ."\\nمبلغ تأییدشده بانک: <b>".TelegramUi::toman($bankAmountRials).' تومان</b>'
            ."\\nشناسه بانک: ".TelegramUi::code($reference)
            ."\\n\\n<b>تا زدن دکمه «تأیید و افزایش موجودی»، شارژ در صف باقی می‌ماند.</b>"
            ."\\nآیا دریافت واقعی وجه را در گردش بانک بررسی کرده‌اید؟",
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
                $this->review->handle($actor->userId, $data['topup'], $data['approve'], $data['reference'], $data['verified_amount_rials'] ?? null);
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
                // Use the identical authorized data and keyboard in native HTML.
            }
        }

        if ($bot->isCallbackQuery() && ! $media) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
