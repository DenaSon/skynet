<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\LinkTelegramAccount;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Onboarding\Application\JoinFromTelegram;
use App\Modules\Provisioning\Application\CheckPlanSquadReadiness;
use App\Modules\Referral\Application\GetReferralInvite;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\QuotedPreset;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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
    ) {}

    public function start(Nutgram $bot, ?string $payload = null, int $page = 1, bool $showKeyboard = false): void
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }
        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            if (! $bot->isCallbackQuery()) {
                $this->referrals->remember($telegramId, $payload ?? '');
            }
            $this->guest($bot);

            return;
        }
        $this->clearInput($actor->userId);

        // Reply and inline keyboards require separate messages. Install the
        // persistent menu on entry; callback navigation edits only its own page.
        if (! $bot->isCallbackQuery() || $showKeyboard) {
            $bot->sendMessage(
                text: '<b>🏠 DenaGate</b>'."\n\nخوش آمدید، ".$this->name($actor->name)
                    .".\nنوع حساب: ".$this->salesRole($actor),
                parse_mode: 'HTML',
                reply_markup: $this->replyMenus->salesKeyboard(),
            );
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
            ReplyMenuLabels::SALES_BUY, ReplyMenuLabels::BUY, ReplyMenuLabels::CATALOG, '🛒 انتخاب پلن' => 'catalog',
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
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0'))
            ->addRow(InlineKeyboardButton::make('🧾 سوابق خرید', callback_data: 'sales:checkout:orders'))
            ->addRow(InlineKeyboardButton::make('👥 دعوت دوستان', callback_data: 'sales:invite'));
        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('💼 پلن‌ساز نماینده', callback_data: 'menu:builder'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
        $this->render($bot,
            '<b>👤 حساب کاربری</b>'."\n\nنام: ".$this->name($actor->name)
                ."\nشناسه DenaGate: {$actor->userId}\nنوع حساب: ".$this->salesRole($actor)
                ."\nوضعیت: 🟢 فعال",
            $keyboard);
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
        $keyboard->addRow(TelegramUi::copy($url, '📋 کپی لینک دعوت'));
        $this->render($bot,
            '<b>👥 دعوت دوستان</b>'."\n\nلینک اختصاصی شما:\n".TelegramUi::code($url)
                ."\n\nدعوت‌های ثبت‌شده: {$invite->joinedCount}\n\nفعلاً برای دعوت دوستان اعتباری اضافه نمی‌شود.",
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

    public function join(Nutgram $bot): void
    {
        if (! $bot->isCallbackQuery()) {
            return;
        }
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return;
        }
        if (config('telegram.public_signup_enabled', false) !== true) {
            $this->render($bot, '🔒 ثبت‌نام در حال حاضر غیرفعال است.', $this->back());

            return;
        }
        $actor = $this->resolve->handle($telegramId);
        if ($actor !== null) {
            $this->start($bot);

            return;
        }
        $this->onboard->handle($telegramId, (string) ($bot->user()->first_name ?? 'کاربر'), $this->referrals->take($telegramId));
        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            $this->render($bot, 'حساب شما غیرفعال است. برای پیگیری با پشتیبانی تماس بگیرید.', $this->back());

            return;
        }
        $this->start($bot, showKeyboard: true);
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
        if (config('telegram.public_signup_enabled', false) === true) {
            $this->render($bot,
                "👋 به DenaGate خوش آمدید.\n\nبرای ساخت حساب کاربری، دکمه زیر را انتخاب کنید.\nاگر از قبل حساب دارید، کد اتصال دریافتی را با دستور /link وارد کنید.",
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('✅ ایجاد حساب', callback_data: 'sales:join')));

            return;
        }
        $this->render($bot,
            "🔒 برای استفاده از DenaGate ابتدا حساب خود را متصل کنید.\n\nکد یک‌بارمصرف دریافتی از مدیر را با دستور /link وارد کنید.",
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
