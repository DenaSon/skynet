<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Billing\Application\ViewWallet;
use App\Modules\Identity\Application\LinkTelegramAccount;
use App\Modules\Identity\Application\ReadAccountCreatedAt;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Identity\Infrastructure\Models\TelegramAccount;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Provisioning\Application\ListMyServices;
use App\Modules\Referral\Application\GetReferralInvite;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\QuotedPreset;
use App\Modules\Sales\Application\SummarizeSalesPurchases;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** Sales-only navigation; shared Application Actions retain all business authority. */
final readonly class SalesHomeUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private PendingReferral $referrals,
        private JoinFromTelegram $onboard,
        private LinkTelegramAccount $linkAccount,
        private PendingBuilderSelection $pendingBuilder,
        private ReplyMenuRenderer $replyMenus,
        private ListQuotedPresets $catalog,
        private CheckPlanSquadReadiness $squads,
        private GetReferralInvite $invites,
        private ReadAccountCreatedAt $memberSince,
        private SummarizeSalesPurchases $purchases,
        private ListMyServices $services,
        private ViewWallet $wallets,
    ) {}

    public function start(Nutgram $bot, ?string $payload = null, bool $showKeyboard = false): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }
        $actor = $this->resolve->handle($telegramId);
        if ($actor === null && ! $bot->isCallbackQuery()) {
            // Sales-only enrollment: /start (or /menu) is the user's registration action.
            // The Admin Bot's public-signup gate stays closed and is never consulted here.
            $this->referrals->remember($telegramId, $payload ?? '');
            $this->onboard->handle(
                $telegramId,
                (string) ($bot->user()->first_name ?? 'کاربر'),
                $this->referrals->take($telegramId),
            );
            $actor = $this->resolve->handle($telegramId);
        }

        if ($actor === null) {
            $this->guest($bot);

            return;
        }

        $this->clearInput($actor->userId);

        // Persistent Reply and Inline keyboards are separate messages.
        // A Home callback updates its page, never sends a second greeting.
        if (! $bot->isCallbackQuery() || $showKeyboard) {
            $bot->sendMessage(
                text: '<b>🏠 DenaGate</b>'."\n\nخوش آمدید، ".$this->name($actor->name)
                    .".\nنوع حساب: ".$this->salesRole($actor),
                parse_mode: 'HTML',
                reply_markup: $this->replyMenus->salesKeyboard(),
            );
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🟢 خرید سرویس', callback_data: 'sales:buy', style: ButtonStyle::SUCCESS),
                InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0', style: ButtonStyle::PRIMARY),
            )
            ->addRow(
                InlineKeyboardButton::make('👤 حساب کاربری', callback_data: 'sales:account'),
                InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'),
            )
            ->addRow(InlineKeyboardButton::make('📋 همه پلن‌ها و قیمت‌ها', callback_data: 'menu:plan-catalog'));

        $this->render($bot, '<b>🏠 صفحه اصلی DenaGate</b>'."\n\nاز منوی زیر، بخش موردنظر را انتخاب کنید.", $keyboard);
    }

    /**
     * Keep the existing bounded, buyer-quoted ready-plan view behind Buy,
     * including old sales:home:{page} pagination callbacks.
     */
    public function buy(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }

        $entries = array_values(array_filter(
            $this->catalog->handle($actor->userId),
            fn (QuotedPreset $entry): bool => $this->squads->verifiedSquadForCheckout($entry->plan->id) !== null,
        ));
        $pages = max(1, (int) ceil(count($entries) / 5));
        $page = min(max(1, $page), $pages);
        $lines = ['<b>🛒 پلن‌های آماده خرید</b>', '', 'یک پلن را انتخاب کنید تا مستقیماً وارد پرداخت شوید.', ''];
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_slice($entries, ($page - 1) * 5, 5) as $entry) {
            $lines[] = '📦 <b>'.$this->name($entry->plan->name).'</b>';
            $lines[] = ($entry->quote->trafficGb === null ? 'حجم نامحدود' : $entry->quote->trafficGb.' گیگابایت')
                .' · '.$entry->quote->durationDays.' روز';
            $lines[] = 'مبلغ: <b>'.TelegramUi::toman($entry->quote->totalPriceRials).' تومان</b>';
            $lines[] = '';
            $keyboard->addRow(InlineKeyboardButton::make(
                mb_substr($this->plain($entry->plan->name), 0, 35),
                callback_data: 'catalog:detail:'.$entry->plan->id.':1',
                style: ButtonStyle::PRIMARY,
            ));
        }
        if ($entries === []) {
            $lines[] = 'فعلاً پلن آماده خریدی وجود ندارد. می‌توانید فهرست پلن‌ها را ببینید یا با پشتیبانی گفتگو کنید.';
        } elseif ($pages > 1) {
            $lines[] = "صفحه {$page} از {$pages}";
        }
        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'sales:home:'.($page - 1)));
        }
        if ($page < $pages) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'sales:home:'.($page + 1)));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📋 همه پلن‌ها و قیمت‌ها', callback_data: 'menu:plan-catalog'));
        if (config('sales.manual_bank_review_enabled', false) !== true) {
            $lines[] = '';
            $lines[] = 'پرداخت فعلاً فعال نیست.';
        }
        $this->render($bot, trim(implode("\n", $lines)), $keyboard);
    }

    /** A menu action must take precedence over a pending free-text form. */
    public function navigate(Nutgram $bot, string $text): bool
    {
        $action = match (trim($text)) {
            ReplyMenuLabels::SALES_BUY, ReplyMenuLabels::BUY, '🛒 خرید سرویس', '🛒 انتخاب پلن' => 'buy',
            ReplyMenuLabels::CATALOG => 'catalog',
            ReplyMenuLabels::SALES_SUBSCRIPTIONS, ReplyMenuLabels::SUBSCRIPTIONS, '📦 اشتراک‌های من' => 'subscriptions',
            ReplyMenuLabels::SALES_ACCOUNT, ReplyMenuLabels::ACCOUNT => 'account',
            ReplyMenuLabels::SALES_SUPPORT => 'support',
            ReplyMenuLabels::HOME, '🏠 صفحه اصلی', '❌ انصراف', '/cancel' => 'home',
            ReplyMenuLabels::INVITE => 'invite',
            ReplyMenuLabels::BUILDER => 'builder',
            default => null,
        };
        if ($action === null) {
            return false;
        }
        if ($this->navigationActor($bot) === null) {
            return true;
        }
        switch ($action) {
            case 'buy':
                $this->buy($bot);
                break;
            case 'catalog':
                app(PlanCatalogUi::class)->browse($bot);
                break;
            case 'subscriptions':
                app(SalesSubscriptionsUi::class)->browse($bot);
                break;
            case 'account':
                $this->account($bot);
                break;
            case 'support':
                app(SalesSupportUi::class)->show($bot);
                break;
            case 'invite':
                $this->invite($bot);
                break;
            case 'builder':
                app(ResellerBuilderUi::class)->browse($bot);
                break;
            default:
                $this->start($bot);
        }

        return true;
    }

    /** Fresh private identity plus retirement of only this bot's input drafts. */
    public function navigationActor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->resolve->handle($id);
        if ($actor === null) {
            $this->guest($bot);

            return null;
        }
        $this->clearInput($actor->userId);

        return $actor;
    }

    public function account(Nutgram $bot): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }

        try {
            $joined = $this->memberSince->handle($actor->userId);
            $purchases = $this->purchases->handle($actor->userId);
            $viewer = SubscriptionViewer::telegram((int) $this->privateUser->id($bot));
            $services = $this->services->counts($viewer);
        } catch (Throwable) {
            $this->render($bot, '<b>👤 حساب کاربری</b>'."\n\nاطلاعات حساب فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.",
                $this->back());

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'))
            ->addRow(InlineKeyboardButton::make('🧾 سوابق خرید', callback_data: 'sales:checkout:orders'))
            ->addRow(InlineKeyboardButton::make('👥 دعوت دوستان', callback_data: 'sales:invite'));

        $balance = '';
        if (in_array('member', $actor->roles, true) || in_array('reseller', $actor->roles, true)) {
            $reseller = in_array('reseller', $actor->roles, true);
            $label = $reseller ? 'موجودی نمایندگی' : 'موجودی کیف پول';
            try {
                $balance = "\n💼 {$label}: <b>".TelegramUi::toman($this->wallets->handle($actor->userId)->balanceRials).' تومان</b>';
                $keyboard->addRow(InlineKeyboardButton::make('💼 کیف پول', callback_data: 'sales:wallet'));
            } catch (Throwable) {
                $balance = "\n💼 {$label}: فعلاً در دسترس نیست";
            }
        }
        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧩 پلن‌ساز نماینده', callback_data: 'menu:builder'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));

        $text = '<b>👤 حساب کاربری</b>'
            ."\n\nنام: ".$this->name($actor->name)
            ."\nشناسه: ".TelegramUi::code((string) $actor->userId)
            ."\nنوع حساب: ".$this->salesRole($actor)
            ."\nوضعیت: 🟢 فعال"
            ."\nعضویت: ".($joined === null ? 'نامشخص' : TelegramUi::escape($joined))
            ."\n\n<b>📊 خلاصه حساب</b>"
            ."\nسرویس‌های ثبت‌شده: ".$services['total']
            ."\nفعال در سوابق: ".$services['activeRecorded']
            ."\nخریدهای پرداخت‌شده: ".$purchases['count']
            ."\nمجموع پرداخت‌ها: <b>".TelegramUi::toman($purchases['amountRials']).' تومان</b>'
            .$balance
            ."\n\n<blockquote>برای وضعیت لحظه‌ای و انقضا، جزئیات هر سرویس را ببینید.</blockquote>";

        $this->render($bot, $text, $keyboard);
    }

    public function wallet(Nutgram $bot): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }
        if (! in_array('member', $actor->roles, true) && ! in_array('reseller', $actor->roles, true)) {
            $this->render($bot, 'کیف پول برای این حساب در دسترس نیست.', $this->back());

            return;
        }
        try {
            $wallet = $this->wallets->handle($actor->userId);
            $message = '<b>💼 کیف پول من</b>'
                ."\n\nموجودی: <b>".TelegramUi::toman($wallet->balanceRials).' تومان</b>';
            if ($wallet->topups !== []) {
                $message .= "\n\n<b>آخرین درخواست‌های شارژ</b>";
                foreach (array_slice($wallet->topups, 0, 5) as $topup) {
                    $message .= "\n#".$topup['id'].' · '.TelegramUi::toman($topup['amount_rials']).' تومان · '.TelegramUi::topupStatus($topup['status']);
                }
            }
            $message .= "\n\n<blockquote>موجودی براساس تراکنش‌های تأییدشده محاسبه می‌شود.</blockquote>";
        } catch (Throwable) {
            $message = '<b>💼 کیف پول من</b>'."\n\nموجودی فعلاً در دسترس نیست.";
        }

        $this->render($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ حساب کاربری', callback_data: 'sales:account'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home')));
    }

    public function invite(Nutgram $bot): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ حساب کاربری', callback_data: 'sales:account'));
        $username = config('telegram.sales.bot_username');
        if (! is_string($username) || preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $username) !== 1) {
            $this->render($bot, '<b>👥 دعوت دوستان</b>'."\n\nلینک دعوت فعلاً در دسترس نیست. کمی بعد دوباره بررسی کنید.", $keyboard);

            return;
        }
        $invite = $this->invites->handle($actor->userId);
        $url = "https://t.me/{$username}?start=ref_{$invite->code}";
        $keyboard->addRow(TelegramUi::copy($url, '📋 کپی لینک دعوت'))
            ->addRow(InlineKeyboardButton::make(
                '📤 اشتراک‌گذاری در تلگرام',
                url: 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode('با لینک من به DenaGate بپیوند 👇'),
            ));
        $this->render($bot,
            '<b>👥 دعوت دوستان</b>'."\n\nلینک اختصاصی شما:\n".TelegramUi::code($url)
                ."\n\nدعوت‌های موفق: <b>{$invite->joinedCount}</b>"
                ."\n\n<blockquote>با این لینک دوستانت می‌توانند مستقیماً وارد بات فروش شوند. فعلاً پاداش مالی برای دعوت وجود ندارد.</blockquote>",
            $keyboard);
    }

    public function fallback(Nutgram $bot): void
    {
        $id = $this->privateUser->id($bot);
        if ($id === null || ($bot->message() === null && ! $bot->isCallbackQuery())) {
            return;
        }
        if ($this->resolve->handle($id) === null) {
            $this->guest($bot);

            return;
        }
        $this->render($bot,
            $bot->isCallbackQuery()
                ? 'این دکمه دیگر قابل استفاده نیست. از خانه ادامه دهید.'
                : 'برای ادامه، یکی از دکمه‌های منو را انتخاب کنید.',
            $this->back());
    }

    /** Old Sales signup buttons remain harmless: new members enroll on /start. */
    public function join(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            $this->start($bot);
        }
    }

    public function link(Nutgram $bot, string $code): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }
        if (! $this->linkAccount->handle($code, $telegramId)) {
            $this->render($bot, 'کد اتصال نامعتبر، منقضی یا قبلاً مصرف شده است.', $this->back());

            return;
        }
        $this->start($bot);
    }

    private function guest(Nutgram $bot): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId !== null && TelegramAccount::query()->where('telegram_user_id', $telegramId)->exists()) {
            $this->render($bot,
                '<b>⛔ حساب غیرفعال</b>'."\n\nحساب شما غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید.",
                $this->back());

            return;
        }

        // Old callbacks and unexpected messages must not create users implicitly.
        $this->render($bot,
            '<b>👋 به DenaGate خوش آمدید</b>'."\n\nبرای شروع خرید، دستور /start را ارسال کنید.",
            $this->back());
    }

    private function clearInput(int $buyerId): void
    {
        $this->pendingBuilder->forget($buyerId);
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyerId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$buyerId));
    }

    private function salesRole(TelegramActor $actor): string
    {
        return in_array('reseller', $actor->roles, true) ? 'نماینده فروش' : 'کاربر';
    }

    private function plain(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F\x{202A}-\x{202E}]+/u', ' ', $value));
    }

    private function name(string $value): string
    {
        return TelegramUi::escape(mb_substr($this->plain($value), 0, 100));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
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
