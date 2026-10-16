<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Billing\Application\ListTopupReviews;
use App\Modules\Billing\Application\RequestTopup;
use App\Modules\Billing\Application\ReviewTopup;
use App\Modules\Billing\Application\SubmitTopupReference;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class BillingUi
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly ViewWallet $wallets,
        private readonly RequestTopup $topups,
        private readonly SubmitTopupReference $references,
        private readonly ListTopupReviews $reviews,
        private readonly ReviewTopup $reviewTopup,
        private readonly BillingReviewTokens $tokens,
        private readonly MenuRenderer $menus,
    ) {}

    public function wallet(Nutgram $bot): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->menus->denyAccess($bot);

            return;
        }

        $summary = $this->wallets->handle($actor->userId);
        $lines = ['💼 کیف پول نماینده', '', 'موجودی: '.number_format(intdiv($summary->balanceRials, 10)).' تومان', '', 'آخرین درخواست‌ها:'];

        foreach ($summary->topups as $row) {
            $lines[] = '#'.$row['id'].' | '.number_format(intdiv($row['amount_rials'], 10)).' تومان | '.TelegramUi::topupStatus($row['status']);
        }

        if ($summary->topups === []) {
            $lines[] = 'هنوز درخواستی ندارید.';
        }

        $keyboard = InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('➕ افزایش موجودی', callback_data: 'billing:help'))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function help(Nutgram $bot): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->menus->denyAccess($bot);

            return;
        }

        if (! config('billing.topups_enabled', false)) {
            $this->notice($bot, 'درخواست شارژ در حال حاضر غیرفعال است.');

            return;
        }

        $instructions = trim((string) config('billing.payment_instructions', ''));
        $instructions = mb_substr($instructions === '' ? 'برای دریافت مشخصات پرداخت با پشتیبانی هماهنگ کنید.' : $instructions, 0, 450);

        $this->notice($bot, "➕ درخواست افزایش اعتبار\n\nبرای ثبت مبلغ (به تومان):\n/topup 500000\n\n{$instructions}\n\nپس از انتقال وجه و دریافت شناسه معتبر تراکنش، بنویسید:\n/paid <شناسه درخواست> <شناسه تراکنش>\n\nرسید به‌تنهایی تأیید پرداخت نیست؛ مدیر ابتدا گردش واقعی بانک را بررسی می‌کند.");
    }

    public function topup(Nutgram $bot, string $toman): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->menus->denyAccess($bot);

            return;
        }

        if (! config('billing.topups_enabled', false)) {
            $this->notice($bot, 'درخواست شارژ غیرفعال است.');

            return;
        }

        if (preg_match('/^[1-9][0-9]{0,11}$/D', $toman) !== 1) {
            $this->notice($bot, 'مبلغ معتبر نیست. مثال: /topup 500000 (تومان)');

            return;
        }

        $amount = (int) $toman;

        if ($amount < 10000 || $amount > 100000000000) {
            $this->notice($bot, 'مبلغ خارج از محدوده است.');

            return;
        }

        $messageId = $bot->message()?->message_id;
        $key = 'tg:'.$this->privateUser->id($bot).':'.($messageId === null ? bin2hex(random_bytes(12)) : (string) $messageId);

        try {
            $id = $this->topups->handle($actor->userId, $amount * 10, $key);
        } catch (ValidationException) {
            $this->notice($bot, 'درخواست شارژ معتبر نیست.');

            return;
        }

        $this->notice($bot, "✅ درخواست شارژ #{$id} به مبلغ ".number_format($amount)." تومان ثبت شد.\n\nپس از واریز واقعی، شناسه تراکنش را ثبت کنید:\n/paid {$id} BANK123456\n\nشارژ تنها پس از بررسی مستقل مدیر انجام می‌شود.");
    }

    public function paid(Nutgram $bot, string $id, string $reference): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->menus->denyAccess($bot);

            return;
        }

        if (! ctype_digit($id) || (int) $id < 1) {
            $this->notice($bot, 'شناسه درخواست نامعتبر است.');

            return;
        }

        try {
            $this->references->handle($actor->userId, (int) $id, $reference);
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'ثبت شناسه پرداخت امکان‌پذیر نیست. وضعیت درخواست را بررسی کنید.');

            return;
        }

        $this->notice($bot, "🕘 شناسه پرداخت برای درخواست #{$id} ثبت شد.\nاعتبار هنوز اضافه نشده است؛ مدیر باید دریافت واقعی وجه را مستقل از پیام شما تأیید کند.");
    }

    public function reviews(Nutgram $bot): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! $actor->canManageRoles()) {
            $this->menus->denyAccess($bot);

            return;
        }

        $rows = $this->reviews->handle($actor->userId);
        $lines = ['💳 درخواست‌های شارژ در انتظار بررسی', ''];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($rows as $row) {
            $lines[] = '#'.$row['id'].' | کاربر #'.$row['user_id'].' | '.number_format(intdiv($row['amount_rials'], 10)).' تومان';
            $lines[] = 'شناسه اعلامی: '.$row['reference'];
            $keyboard->addRow(
                InlineKeyboardButton::make('✅ بررسی تأیید #'.$row['id'], callback_data: "billing:review:{$row['id']}:a"),
                InlineKeyboardButton::make('❌ بررسی رد #'.$row['id'], callback_data: "billing:review:{$row['id']}:r"),
            );
        }

        if ($rows === []) {
            $lines[] = 'درخواست ثبت‌شده‌ای وجود ندارد.';
        }

        $lines[] = '';
        $lines[] = '⚠️ پیش از تأیید، شماره پیگیری و مبلغ را با تراکنش واقعی بانک تطبیق دهید.';
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'menu:billing-reviews'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function reviewRequest(Nutgram $bot, string $topupId, string $decision): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! $actor->canManageRoles()) {
            $this->menus->denyAccess($bot);

            return;
        }

        $rows = $this->reviews->handle($actor->userId);
        $row = collect($rows)->first(static fn (array $item): bool => $item['id'] === (int) $topupId);

        if ($row === null || ! in_array($decision, ['a', 'r'], true)) {
            $this->notice($bot, 'درخواست قابل بررسی نیست یا دیگر در صف انتظار قرار ندارد.');

            return;
        }

        $approve = $decision === 'a';
        $token = $this->tokens->issue($actor->userId, $row['id'], $approve, $row['reference']);
        $operation = $approve ? 'تأیید و شارژ کیف پول' : 'رد درخواست بدون شارژ';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید تصمیم', callback_data: 'billing:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: 'menu:billing-reviews'));

        $this->render($bot, "⚠️ {$operation}\n\nدرخواست #{$row['id']} | کاربر #{$row['user_id']}\nمبلغ: ".number_format(intdiv($row['amount_rials'], 10))." تومان\nشناسه اعلامی: {$row['reference']}\n\nدر صورت تأیید، شما مسئول تطبیق واقعی تراکنش بانکی هستید. کد تأیید ۳ دقیقه اعتبار دارد.", $keyboard);
    }

    public function applyReview(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        if (! $actor->canManageRoles()) {
            $this->menus->denyAccess($bot);

            return;
        }

        $request = $this->tokens->consume($actor->userId, $token);

        if ($request === null) {
            $this->notice($bot, 'این تأیید نامعتبر یا مصرف‌شده است.');

            return;
        }

        try {
            $this->reviewTopup->handle($actor->userId, $request['topup'], $request['approve'], $request['reference']);
        } catch (ValidationException|AuthorizationException|UniqueConstraintViolationException) {
            $this->notice($bot, 'عملیات رد شد: درخواست تغییر کرده یا این تراکنش قبلاً تأیید شده است.');

            return;
        }

        $this->notice($bot, $request['approve'] ? '✅ شارژ کیف پول پس از تأیید ثبت شد.' : '❌ درخواست بدون افزایش موجودی رد شد.');
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);

        if ($actor === null) {
            $this->menus->denyAccess($bot);
        }

        return $actor;
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home')));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
