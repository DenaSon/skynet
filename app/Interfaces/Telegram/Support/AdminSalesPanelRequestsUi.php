<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Notifications\SalesPanelNotificationOutbox;
use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ManageRoles;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\ReviewSalesPanelRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** Root-only review queue for opt-in Sales Panel membership. */
final readonly class AdminSalesPanelRequestsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageRoles $roles,
        private ReviewSalesPanelRequest $requests,
        private SalesPanelNotificationOutbox $notifications,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $page = '1'): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        if ($this->number($page, 1000) === null) {
            $this->notice($bot, 'شماره صفحه معتبر نیست.');

            return;
        }
        $number = (int) $page;
        $rows = $this->requests->page($actor->userId, $number);
        $next = count($rows) > 8;
        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['<b>🧩 درخواست‌های پنل فروش</b>', '', '🕒 درخواست‌های منتظر بررسی', ''];
        foreach (array_slice($rows, 0, 8) as $row) {
            $name = TelegramUi::escape(mb_substr($row['first_name'].' '.$row['last_name'], 0, 60));
            $lines[] = '👤 #'.$row['id'].' · '.$name;
            $keyboard->addRow(InlineKeyboardButton::make(
                'بررسی درخواست #'.$row['id'], callback_data: 'sp:detail:'.$row['id'],
            ));
        }
        if ($rows === []) {
            $lines[] = 'درخواستی در انتظار بررسی نیست.';
        }
        $paging = [];
        if ($number > 1) {
            $paging[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'sp:list:'.($number - 1));
        }
        if ($next) {
            $paging[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'sp:list:'.($number + 1));
        }
        if ($paging !== []) {
            $keyboard->addRow(...$paging);
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ محصولات و پلن‌ها', callback_data: 'menu:admin:sales'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $number = $this->number($id, PHP_INT_MAX);
        if ($number === null) {
            $this->notice($bot, 'شناسه درخواست معتبر نیست.');

            return;
        }
        try {
            $row = $this->requests->find($actor->userId, $number);
        } catch (ValidationException) {
            $this->notice($bot, 'درخواست پیدا نشد.');

            return;
        }

        $name = TelegramUi::escape($row['first_name'].' '.$row['last_name']);
        $status = match ($row['status']) {
            'approved' => '✅ تأییدشده',
            'rejected' => '❌ ردشده',
            default => '🕒 در انتظار بررسی',
        };
        $keyboard = InlineKeyboardMarkup::make();
        if ($row['status'] === 'pending') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '✅ تأیید پنل فروش', callback_data: 'sp:prepare:'.$number.':a', style: ButtonStyle::SUCCESS,
            ));
            $keyboard->addRow(InlineKeyboardButton::make(
                '❌ رد درخواست', callback_data: 'sp:prepare:'.$number.':r', style: ButtonStyle::DANGER,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ درخواست‌ها', callback_data: 'sp:list:1'));
        $this->render($bot,
            '<b>🧩 بررسی پنل فروش #'.$row['id'].'</b>'
            ."\n\n👤 نام: <b>{$name}</b>"
            ."\nشناسه کاربر: ".TelegramUi::code((string) $row['user_id'])
            ."\nموبایل: ".TelegramUi::code($row['mobile'])
            ."\nوضعیت: {$status}", $keyboard);
    }

    public function prepare(Nutgram $bot, string $id, string $decision): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $number = $this->number($id, PHP_INT_MAX);
        if ($number === null || ! in_array($decision, ['a', 'r'], true)) {
            $this->notice($bot, 'درخواست معتبر نیست.');

            return;
        }
        try {
            $row = $this->requests->find($actor->userId, $number);
            if ($row['status'] !== 'pending') {
                throw ValidationException::withMessages(['application' => 'Already reviewed.']);
            }
        } catch (ValidationException) {
            $this->notice($bot, 'این درخواست دیگر در انتظار بررسی نیست.');

            return;
        }
        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($actor->userId, $token), [
            'id' => $number, 'approve' => $decision === 'a',
        ], now()->addMinutes(3));
        $title = $decision === 'a' ? '✅ تأیید و فعال‌سازی پنل فروش' : '❌ رد درخواست';
        $description = $decision === 'a'
            ? 'با تأیید، حساب کاربر به پنل فروش ارتقا پیدا می‌کند و قیمت‌های ویژه برای او فعال می‌شوند.'
            : 'با رد درخواست، حساب عادی کاربر و موجودی کیف پول بدون تغییر باقی می‌ماند.';
        $this->render($bot,
            "<b>{$title}</b>"
            ."\n\nدرخواست #{$number}"
            ."\nنام: ".TelegramUi::escape($row['first_name'].' '.$row['last_name'])
            ."\n\n<blockquote>{$description}</blockquote>"
            ."\n\nآیا تصمیم را قطعی می‌کنید؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make(
                    $decision === 'a' ? '✅ تأیید نهایی' : '❌ رد نهایی',
                    callback_data: 'sp:apply:'.$token,
                    style: $decision === 'a' ? ButtonStyle::SUCCESS : ButtonStyle::DANGER,
                ))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'sp:detail:'.$number)));
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->notice($bot, 'این تأیید معتبر نیست.');

            return;
        }
        $draft = Cache::pull($this->key($actor->userId, $token));
        if (! is_array($draft) || ! is_int($draft['id'] ?? null) || ! is_bool($draft['approve'] ?? null)) {
            $this->notice($bot, 'این تأیید منقضی یا قبلاً استفاده شده است.');

            return;
        }
        try {
            DB::transaction(function () use ($actor, $draft): void {
                $row = $this->requests->decide($actor->userId, $draft['id'], $draft['approve']);
                $this->notifications->reviewed($row['id']);
            });
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'درخواست تغییر کرده یا امکان بررسی آن وجود ندارد. فهرست را تازه کنید.');

            return;
        }
        $this->render($bot,
            $draft['approve']
                ? '<b>✅ پنل فروش فعال شد</b>'."\n\nدسترسی کاربر پس از تأیید برقرار شد."
                : '<b>❌ درخواست رد شد</b>'."\n\nحساب کاربر بدون تغییر باقی ماند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🧩 درخواست‌های دیگر', callback_data: 'sp:list:1'))
                ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin')));
    }

    private function root(Nutgram $bot): ?TelegramActor
    {
        if (TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);
        try {
            if ($actor === null || ! $actor->canManageRoles()) {
                throw new AuthorizationException;
            }
            $this->roles->authorize($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function number(string $value, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) !== 1) {
            return null;
        }
        $number = (int) $value;

        return $number > 0 && $number <= $max ? $number : null;
    }

    private function key(int $rootId, string $token): string
    {
        return TelegramBotContext::current()->cacheKey('telegram:panel:review:'.$rootId.':'.$token);
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, '<b>🧩 پنل فروش</b>'."\n\n".$message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ درخواست‌ها', callback_data: 'sp:list:1'))
            ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin')));
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
