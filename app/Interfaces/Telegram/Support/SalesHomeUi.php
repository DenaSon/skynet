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
use App\Modules\Sales\Application\SalesPanelRequests;
use App\Modules\Sales\Application\SummarizeSalesPurchases;
use App\Support\PersianCalendar;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use SergiX44\Nutgram\Telegram\Types\WebApp\WebAppInfo;
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
        private ListQuotedPresets $catalog,
        private CheckPlanSquadReadiness $squads,
        private GetReferralInvite $invites,
        private ReadAccountCreatedAt $memberSince,
        private SummarizeSalesPurchases $purchases,
        private ListMyServices $services,
        private ViewWallet $wallets,
        private SalesPanelRequests $panelRequests,
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

        $this->clearInput($actor->userId, $bot);
        $isReseller = in_array('reseller', $actor->roles, true);

        // Telegram /start deep link from the authenticated reseller Mini App.
        // The link is only a navigation hint, not an authorization credential.
        // Reuse the existing private owner-verified top-up flow; never create
        // a wallet request, payment or receipt from this /start handler.
        if ($payload === 'live_topup' && $isReseller && config('denagate_live.enabled') === true
            && ! $bot->isCallbackQuery()) {
            app(WalletTopupUi::class)->start($bot);

            return;
        }

        $panelRequestLabel = $this->panelRequestLabel($actor);
        if ($payload === 'demo_panel' && $panelRequestLabel !== null && ! $bot->isCallbackQuery()) {
            // Navigation only. The existing Sales Panel form enforces its own
            // wallet eligibility, consent and administrative approval.
            app(SalesPanelRequestUi::class)->start($bot);

            return;
        }
        $customWelcome = app(SalesWelcomeMessage::class)->customizedForActor($actor);

        $hasWallet = $isReseller || in_array('member', $actor->roles, true);
        $walletLine = '';
        if ($hasWallet) {
            try {
                $walletLine = "\n💰 اعتبار من: <b>"
                    .TelegramUi::toman($this->wallets->handle($actor->userId)->balanceRials).' تومان</b>';
            } catch (Throwable) {
                $walletLine = "\n💰 اعتبار من: فعلاً در دسترس نیست";
            }
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🟢 خرید سرویس', callback_data: 'sales:buy', style: ButtonStyle::SUCCESS),
                InlineKeyboardButton::make('📋 سرویس‌های من', callback_data: 'sales:subs:list:0', style: ButtonStyle::PRIMARY),
            );
        if ($hasWallet) {
            $keyboard->addRow(
                InlineKeyboardButton::make(ReplyMenuLabels::SALES_WALLET, callback_data: 'sales:wallet'),
                InlineKeyboardButton::make('👤 حساب کاربری', callback_data: 'sales:account'),
            );
        } else {
            $keyboard->addRow(InlineKeyboardButton::make('👤 حساب کاربری', callback_data: 'sales:account'));
        }
        if ($actor->roles === ['member']) {
            $keyboard->addRow(
                InlineKeyboardButton::make(ReplyMenuLabels::SALES_REWARD, callback_data: 'sales:invite', style: ButtonStyle::PRIMARY),
                InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'),
            );
        }
        if ($isReseller) {
            $keyboard->addRow(InlineKeyboardButton::make('🧩 پلن سفارشی', callback_data: 'menu:builder'));
            $keyboard->addRow(InlineKeyboardButton::make('📊 گزارش‌های من', callback_data: 'sales:report:30'));
        }
        if ($panelRequestLabel !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                $panelRequestLabel, callback_data: 'sales:panel:hub',
            ));
        }
        if ($actor->roles !== ['member']) {
            $keyboard->addRow(InlineKeyboardButton::make('💬 پشتیبانی', callback_data: 'sales:support'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📋 همه پلن‌ها و قیمت‌ها', callback_data: 'menu:plan-catalog'));
        $keyboard->addRow(InlineKeyboardButton::make(ReplyMenuLabels::SALES_ANNOUNCEMENTS, callback_data: 'sales:ann:list'));
        if ($isReseller && config('denagate_live.enabled') === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                ReplyMenuLabels::SALES_APP,
                web_app: WebAppInfo::make((string) config('denagate_live.url')),
                style: ButtonStyle::PRIMARY,
            ));
        }

        $homeText = '<b>🏠 صفحه اصلی DenaGate</b>'
            .($customWelcome === null ? '' : "\n\n".$customWelcome)
            ."\n\nنوع حساب: <b>".$this->salesRole($actor).'</b>'
            .$walletLine;

        // A Rich Message can include its own interactive buttons AND the
        // persistent reply keyboard. Keep the existing two-message native
        // fallback only when Telegram rejects Rich delivery.
        if (! $bot->isCallbackQuery() || $showKeyboard) {
            $welcome = $customWelcome ?? 'خوش آمدید، '.$this->name($actor->name).'.';
            $paragraphs = implode('', array_map(
                static fn (string $line): string => '<p>'.($line === '' ? '&nbsp;' : $line).'</p>',
                explode("\n", str_replace("\r", '', $welcome)),
            ));

            $richHtml = '<h4>🏠 صفحه اصلی DenaGate</h4>'.$paragraphs
                .'<p>نوع حساب: <b>'.$this->salesRole($actor).'</b></p>'
                .($walletLine === '' ? '' : '<p>'.trim($walletLine).'</p>');

            foreach ($keyboard->inline_keyboard as $row) {
                $richHtml .= '<tg-button-row>';
                foreach ($row as $button) {
                    $style = $button->style === ButtonStyle::SUCCESS ? ' style="success"'
                        : ($button->style === ButtonStyle::PRIMARY ? ' style="primary"' : '');
                    if ($button->web_app !== null) {
                        $richHtml .= '<tg-button type="web_app"'.$style
                            .' url="'.TelegramUi::escape($button->web_app->url).'">'
                            .TelegramUi::escape($button->text).'</tg-button>';
                    } else {
                        $richHtml .= '<tg-button type="callback_data"'.$style
                            .' data="'.TelegramUi::escape((string) $button->callback_data).'">'
                            .TelegramUi::escape($button->text).'</tg-button>';
                    }
                }
                $richHtml .= '</tg-button-row>';
            }

            try {
                $bot->sendRichMessage(
                    rich_message: new InputRichMessage(html: $richHtml, is_rtl: true),
                    reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard(
                        $actor,
                        app(SalesTrialUi::class)->visible($actor, (int) $telegramId),
                        $panelRequestLabel === ReplyMenuLabels::SALES_PANEL_STATUS,
                    ),
                );

                return;
            } catch (TelegramException $exception) {
                report($exception);
                $bot->sendMessage(
                    text: $customWelcome === null
                        ? app(SalesWelcomeMessage::class)->forActor($actor)
                        : '⌨️ منوی دناگیت آماده است.',
                    parse_mode: 'HTML',
                    reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard(
                        $actor,
                        app(SalesTrialUi::class)->visible($actor, (int) $telegramId),
                        $panelRequestLabel === ReplyMenuLabels::SALES_PANEL_STATUS,
                    ),
                );
            }
        }

        $this->render($bot, $homeText, $keyboard);
    }

    /**
     * Keep the existing bounded, buyer-quoted ready-plan view behind Buy,
     * including old sales:home:{page} pagination callbacks.
     */
    public function buy(Nutgram $bot, int $page = 1, bool $rich = true): void
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
        $isReseller = in_array('reseller', $actor->roles, true);
        $lines = ['<b>🛒 پلن‌های آماده خرید</b>', '', 'یک پلن را انتخاب کنید تا مشخصات سفارش را تکمیل کنید.', ''];
        $keyboard = InlineKeyboardMarkup::make();
        $richHtml = '<h4>🛒 پلن‌های آماده خرید</h4>'
            .'<p>پلن موردنظر را انتخاب کنید؛ مشخصات و قیمت هر پلن پیش از خرید دوباره بررسی می‌شود.</p>'
            .($pages > 1 ? '<p>صفحه '.$page.' از '.$pages.'</p>' : '');
        foreach (array_slice($entries, ($page - 1) * 5, 5) as $entry) {
            $lines[] = '📦 <b>'.$this->name($entry->plan->name).'</b>';
            $lines[] = ($entry->quote->trafficGb === null ? 'حجم نامحدود' : $entry->quote->trafficGb.' گیگابایت')
                .' · '.$entry->quote->durationDays.' روز';
            $lines[] = ($isReseller ? 'قیمت مخصوص پنل فروش: <b>' : 'مبلغ: <b>')
                .TelegramUi::toman($entry->quote->totalPriceRials).' تومان</b>';
            $lines[] = '';
            $callback = 'catalog:detail:'.$entry->plan->id.':1';
            $richHtml .= '<p>📦 <b>'.$this->name($entry->plan->name).'</b><br>'
                .($entry->quote->trafficGb === null ? 'حجم نامحدود' : $entry->quote->trafficGb.' گیگابایت')
                .' · '.$entry->quote->durationDays.' روز<br>'
                .($isReseller
                    ? '🏷️ قیمت عمومی: '.TelegramUi::toman($entry->quote->publicPriceRials).' تومان<br>'
                        .'🎁 تخفیف نماینده: '.$entry->quote->discountPercent.'٪<br>قیمت شما: '
                    : 'مبلغ نهایی: ')
                .'<b>'.TelegramUi::toman($entry->quote->totalPriceRials).' تومان</b></p>'
                .'<tg-button-row><tg-button type="callback_data" style="success" data="'.$callback.'">🛒 خرید</tg-button></tg-button-row>';
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    TelegramUi::toman($entry->quote->totalPriceRials).' تومان',
                    callback_data: $callback,
                    style: ButtonStyle::PRIMARY,
                ),
                InlineKeyboardButton::make(
                    TelegramUi::planButtonLabel($entry->plan->name, $entry->quote->trafficGb, $entry->quote->durationDays),
                    callback_data: $callback,
                    style: ButtonStyle::PRIMARY,
                ),
            );
        }
        if ($entries === []) {
            $lines[] = 'فعلاً پلن آماده خریدی وجود ندارد. می‌توانید فهرست پلن‌ها را ببینید یا با پشتیبانی گفتگو کنید.';
            $richHtml .= '<p>فعلاً پلن آماده خریدی وجود ندارد. می‌توانید فهرست پلن‌ها را ببینید یا با پشتیبانی گفتگو کنید.</p>';
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
        if (config('sales.manual_bank_review_enabled', false) !== true
            && config('sales.wallet_settlement_enabled', false) !== true) {
            $lines[] = '';
            $lines[] = 'پرداخت فعلاً فعال نیست.';
            $richHtml .= '<p>پرداخت فعلاً فعال نیست.</p>';
        }
        $plainText = trim(implode("\n", $lines));
        if (! $rich || $entries === []) {
            $this->render($bot, $plainText, $keyboard);

            return;
        }

        // The existing native two-column buy buttons remain available during
        // the in-message CTA client pilot. They continue the validated checkout.
        $simpleKeyboard = clone $keyboard;
        $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:buy:simple:'.$page));
        try {
            TelegramUi::answerCallback($bot);
            TelegramUi::editRichText($bot, new InputRichMessage(html: $richHtml, is_rtl: true), $keyboard);
        } catch (TelegramException $exception) {
            report($exception);
            if ($bot->isCallbackQuery()) {
                TelegramUi::editText($bot, $plainText, $simpleKeyboard, 'HTML');
            } else {
                $bot->sendMessage(text: $plainText, reply_markup: $simpleKeyboard, parse_mode: 'HTML');
            }
        }
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
            ReplyMenuLabels::SALES_TUTORIAL => 'tutorial',
            ReplyMenuLabels::SALES_ANNOUNCEMENTS => 'announcements',
            ReplyMenuLabels::SALES_TRIAL => 'trial',
            ReplyMenuLabels::SALES_APP => 'app',
            ReplyMenuLabels::SALES_PANEL_REQUEST, ReplyMenuLabels::SALES_PANEL_STATUS,
            '🧩 درخواست پنل فروش', '📨 وضعیت درخواست پنل فروش' => 'panel',
            ReplyMenuLabels::SALES_REPORTS => 'report',
            ReplyMenuLabels::HOME, '🏠 صفحه اصلی', '❌ انصراف', '/cancel' => 'home',
            ReplyMenuLabels::SALES_REWARD, ReplyMenuLabels::INVITE, '👥 دعوت دوستان' => 'invite',
            ReplyMenuLabels::BUILDER, ReplyMenuLabels::PREVIOUS_BUILDER,
            ReplyMenuLabels::LEGACY_BUILDER,
            ReplyMenuLabels::OLD_BUILDER_PANEL => 'builder',
            ReplyMenuLabels::SALES_WALLET, ReplyMenuLabels::WALLET, ReplyMenuLabels::LEGACY_WALLET,
            ReplyMenuLabels::OLD_WALLET_PANEL, '💰 کیف پول', '💼 کیف پول' => 'wallet',
            default => null,
        };
        if ($action === null) {
            return false;
        }
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return true;
        }
        app(SalesSubscriptionsUi::class)->clearCustomerLabelDraft($actor->userId);
        app(SalesSubscriptionsUi::class)->clearCustomerPhoneDraft($actor->userId);
        app(SalesSubscriptionsUi::class)->clearCustomerSearch($actor->userId);
        app(SalesCheckoutUi::class)->clearCustomerName($actor->userId);
        app(SalesCheckoutUi::class)->clearCustomerPhone($actor->userId);
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
            case 'trial':
                app(SalesTrialUi::class)->show($bot);
                break;
            case 'app':
                app(SalesLiveEntry::class)->show($bot);
                break;
            case 'panel':
                $this->panel($bot);
                break;
            case 'announcements':
                app(SalesAnnouncementsUi::class)->browse($bot);
                break;
            case 'tutorial':
                app(SalesSupportUi::class)->show($bot, 'apps');
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
            case 'wallet':
                $this->wallet($bot);
                break;
            case 'report':
                app(ResellerSalesReportUi::class)->show($bot);
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
        $this->clearInput($actor->userId, $bot);

        return $actor;
    }

    /** Single eligible-member entry to the existing Demo and verified application flows. */
    public function panel(Nutgram $bot): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }
        if ($this->panelRequestLabel($actor) === null) {
            $this->render($bot, 'این بخش برای حساب شما در دسترس نیست.', $this->back());

            return;
        }

        try {
            $request = $this->panelRequests->find($actor->userId);
        } catch (Throwable) {
            $this->render($bot, 'وضعیت درخواست فعلاً در دسترس نیست. لطفاً دوباره تلاش کنید.', $this->back());

            return;
        }

        $status = match ($request['status'] ?? null) {
            'pending' => '🕘 درخواست شما در انتظار بررسی مدیریت است.',
            'rejected' => 'درخواست قبلی تأیید نشده است؛ برای پیگیری وضعیت را ببینید.',
            'approved' => 'درخواست قبلاً تأیید شده است؛ فعال‌بودن پنل را از پشتیبانی پیگیری کنید.',
            default => null,
        };
        $action = $request === null ? '🧩 ارسال درخواست نمایندگی' : '📨 مشاهده وضعیت درخواست';
        $keyboard = InlineKeyboardMarkup::make();
        if (config('denagate_live.enabled') === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🖥️ مشاهده دمو',
                web_app: WebAppInfo::make(rtrim((string) config('denagate_live.url'), '/').'/demo'),
                style: ButtonStyle::PRIMARY,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            $action,
            callback_data: 'sales:panel:request',
            style: $request === null ? ButtonStyle::SUCCESS : ButtonStyle::PRIMARY,
        ));
        $keyboard->addRow(InlineKeyboardButton::make('🏠 بازگشت به خانه', callback_data: 'sales:home'));

        $plain = '<b>🧩 نمایندگی DenaGate</b>'
            ."\n\nبا پنل اختصاصی دناگیت، سرویس‌ها و مشتریان خود را مدیریت کنید."
            ."\n\n• مدیریت مشتریان و سفارش‌ها"
            ."\n• تعرفه‌های مخصوص نمایندگان"
            ."\n• اپلیکیشن اختصاصی نمایندگی"
            .($status === null ? '' : "\n\n".$status)
            .(config('denagate_live.enabled') === true ? '' : "\n\nدمو فعلاً در دسترس نیست.")
            ."\n\n<blockquote>فعال‌سازی نمایندگی پس از بررسی و تأیید مدیریت انجام می‌شود.</blockquote>";
        $html = '<h3>🧩 نمایندگی DenaGate</h3>'
            .'<p>با پنل اختصاصی دناگیت، سرویس‌ها و مشتریان خود را مدیریت کنید.</p>'
            .'<table bordered striped compact>'
            .'<tr><td>👥 مشتریان</td><td>مدیریت سفارش‌ها و سرویس‌ها</td></tr>'
            .'<tr><td>🏷️ تعرفه‌ها</td><td>قیمت مخصوص نمایندگان</td></tr>'
            .'<tr><td>📱 اپلیکیشن</td><td>دسترسی به پنل نمایندگی</td></tr>'
            .'</table>'
            .($status === null ? '' : '<p>'.TelegramUi::escape($status).'</p>')
            .(config('denagate_live.enabled') === true ? '' : '<p>دمو فعلاً در دسترس نیست.</p>')
            .'<footer>فعال‌سازی نمایندگی پس از بررسی و تأیید مدیریت انجام می‌شود.</footer>';

        $this->renderRich($bot, $html, $plain, $keyboard, clone $keyboard);
    }

    public function account(Nutgram $bot, bool $rich = true): void
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
            ->addRow(InlineKeyboardButton::make('🧾 سوابق خرید', callback_data: 'sales:checkout:orders'));

        $balance = '';
        if (in_array('member', $actor->roles, true) || in_array('reseller', $actor->roles, true)) {
            $label = 'اعتبار من';
            try {
                $balance = "\n💼 {$label}: <b>".TelegramUi::toman($this->wallets->handle($actor->userId)->balanceRials).' تومان</b>';
                $keyboard->addRow(InlineKeyboardButton::make(ReplyMenuLabels::SALES_WALLET, callback_data: 'sales:wallet'));
            } catch (Throwable) {
                $balance = "\n💼 {$label}: فعلاً در دسترس نیست";
            }
        }
        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧩 پلن سفارشی', callback_data: 'menu:builder'));
            $keyboard->addRow(InlineKeyboardButton::make('📊 گزارش‌های من', callback_data: 'sales:report:30'));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
        if (in_array('reseller', $actor->roles, true) && config('denagate_live.enabled') === true) {
            $keyboard->addRow(InlineKeyboardButton::make(
                ReplyMenuLabels::SALES_APP,
                web_app: WebAppInfo::make((string) config('denagate_live.url')),
                style: ButtonStyle::PRIMARY,
            ));
        }

        $text = '<b>👤 حساب کاربری</b>'
            ."\n\nنام: ".$this->name($actor->name)
            ."\nشناسه: ".TelegramUi::code((string) $actor->userId)
            ."\nنوع حساب: <b>".$this->salesRole($actor).'</b>'
            ."\nوضعیت: 🟢 فعال"
            ."\nعضویت: ".($joined === null ? 'نامشخص' : PersianCalendar::date($joined))
            ."\n\n<b>📊 خلاصه حساب</b>"
            ."\nسرویس‌های ثبت‌شده: ".$services['total']
            ."\nفعال در سوابق: ".$services['activeRecorded']
            ."\nخریدهای پرداخت‌شده: ".$purchases['count']
            ."\nمجموع پرداخت‌ها: <b>".TelegramUi::toman($purchases['amountRials']).' تومان</b>'
            .$balance
            ."\n\n<blockquote>برای وضعیت لحظه‌ای و انقضا، جزئیات هر سرویس را ببینید.</blockquote>";

        if ($rich) {
            $html = '<h4>👤 حساب کاربری</h4>'
                .'<p>👤 '.$this->name($actor->name)
                .'<br>نوع حساب: <b>'.$this->salesRole($actor).'</b>'
                .'<br>وضعیت حساب: 🟢 فعال</p>'
                .'<p>📋 سرویس‌های ثبت‌شده: <b>'.$services['total'].'</b>'
                .'<br>فعال در سوابق: <b>'.$services['activeRecorded'].'</b>'
                .'<br>🧾 خریدهای پرداخت‌شده: <b>'.$purchases['count'].'</b></p>'
                .($balance === '' ? '' : '<p>'.str_replace("\n", '', $balance).'</p>')
                .'<details><summary>📊 سوابق مالی حساب</summary><p>مجموع پرداخت‌ها: <b>'
                .TelegramUi::toman($purchases['amountRials']).' تومان</b></p></details>'
                .'<details><summary>🪪 اطلاعات عضویت</summary><p>شناسه: '
                .TelegramUi::escape((string) $actor->userId)
                .'<br>تاریخ عضویت: '.($joined === null ? 'نامشخص' : TelegramUi::escape(PersianCalendar::date($joined)))
                .'</p></details>'
                .'<p>برای وضعیت لحظه‌ای و انقضا، جزئیات هر سرویس را بررسی کنید.</p>';
            $plainKeyboard = clone $keyboard;
            $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:account:simple'));
            $this->renderRich($bot, $html, $text, $keyboard, $plainKeyboard);

            return;
        }

        $this->render($bot, $text, $keyboard);
    }

    public function wallet(Nutgram $bot, bool $rich = true): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }
        if (! in_array('member', $actor->roles, true) && ! in_array('reseller', $actor->roles, true)) {
            $this->render($bot, 'اعتبار برای این حساب در دسترس نیست.', $this->back());

            return;
        }

        $pendingTopups = [];
        $richHtml = null;
        $canResume = false;
        try {
            $wallet = $this->wallets->handle($actor->userId);
            $pendingTopups = array_slice(array_values(array_filter(
                $wallet->topups, static fn (array $topup): bool => $topup['status'] === 'pending',
            )), 0, 3);
            $history = array_slice(array_values(array_filter(
                $wallet->topups, static fn (array $topup): bool => $topup['status'] !== 'pending',
            )), 0, 5);
            $canResume = config('billing.topups_enabled', false) === true
                && app(ManualPaymentUi::class)->cardNumber() !== null;

            $balance = TelegramUi::toman($wallet->balanceRials).' تومان';
            $message = '<b>💰 اعتبار من</b>'."\n\nاعتبار قابل استفاده: <b>".$balance.'</b>';
            $richHtml = '<h4>💰 اعتبار من</h4><p>اعتبار قابل استفاده: <b>'.$balance.'</b></p>';

            if ($pendingTopups !== []) {
                $message .= "\n\n<b>🕒 شارژهای نیمه‌تمام</b>";
                $richHtml .= '<h4>🕒 شارژهای نیمه‌تمام</h4>';
                $amounts = array_count_values(array_column($pendingTopups, 'amount_rials'));
                foreach ($pendingTopups as $topup) {
                    $amount = TelegramUi::toman($topup['amount_rials']).' تومان';
                    $duplicate = ($amounts[$topup['amount_rials']] ?? 0) > 1;
                    $suffix = $duplicate ? ' · #'.$topup['id'] : '';
                    $message .= "\n📷 ".$amount.$suffix.' · در انتظار ارسال رسید';
                    $richHtml .= '<p>💳 <b>'.$amount.'</b>'.$suffix.' · در انتظار ارسال رسید</p>';
                    if ($canResume) {
                        $callback = 'sales:wallet:topup:resume:'.$topup['id'];
                        $richHtml .= '<tg-button-row><tg-button type="callback_data" style="success" data="'.$callback.'">'
                            .'📷 تکمیل شارژ · '.$amount.$suffix.'</tg-button></tg-button-row>';
                    }
                }
            }
            if ($history !== []) {
                $message .= "\n\n<b>🧾 سوابق شارژ</b>";
                $richHtml .= '<details><summary>🧾 سوابق شارژ</summary><p>';
                foreach ($history as $topup) {
                    $line = TelegramUi::toman($topup['amount_rials']).' تومان · '
                        .TelegramUi::topupStatus($topup['status']);
                    $message .= "\n▫️ ".$line;
                    $richHtml .= TelegramUi::escape($line).'<br>';
                }
                $richHtml .= '</p></details>';
            }
            $message .= "\n\n<blockquote>اعتبار فقط از تراکنش‌های تأییدشده محاسبه می‌شود.</blockquote>";
            $richHtml .= '<p>اعتبار فقط از تراکنش‌های تأییدشده محاسبه می‌شود.</p>';
        } catch (Throwable) {
            $message = '<b>💰 اعتبار من</b>'."\n\nموجودی فعلاً در دسترس نیست.";
        }

        // Rich content owns its in-message Resume buttons; native fallback
        // preserves the same owned callbacks when Telegram Rich is unavailable.
        $keyboard = InlineKeyboardMarkup::make();
        $plainKeyboard = InlineKeyboardMarkup::make();
        if ($canResume) {
            foreach ($pendingTopups as $topup) {
                $label = '📷 تکمیل شارژ · '.TelegramUi::toman($topup['amount_rials']).' تومان';
                if (count(array_filter($pendingTopups, static fn (array $item): bool => $item['amount_rials'] === $topup['amount_rials'])) > 1) {
                    $label .= ' · #'.$topup['id'];
                }
                $plainKeyboard->addRow(InlineKeyboardButton::make(
                    $label, callback_data: 'sales:wallet:topup:resume:'.$topup['id'],
                    style: ButtonStyle::SUCCESS,
                ));
            }
        }

        if (isset($wallet)) {
            try {
                if ($this->panelRequests->canRequestWithCurrentBalance($actor->userId)) {
                    $action = InlineKeyboardButton::make(
                        '🧩 ثبت درخواست پنل فروش',
                        callback_data: 'sales:panel:request',
                        style: ButtonStyle::SUCCESS,
                    );
                    $keyboard->addRow($action);
                    $plainKeyboard->addRow(clone $action);
                }
            } catch (Throwable) {
                // A failing optional CTA must not hide existing wallet actions.
            }
        }
        if (config('billing.topups_enabled', false) === true) {
            $action = InlineKeyboardButton::make(
                '➕ افزایش اعتبار', callback_data: 'sales:wallet:topup',
                style: ButtonStyle::SUCCESS,
            );
            $keyboard->addRow($action);
            $plainKeyboard->addRow(clone $action);
        }

        if ($rich && $richHtml !== null) {
            $this->renderRich($bot, $richHtml, $message, $keyboard, $plainKeyboard);

            return;
        }

        $this->render($bot, $message, $plainKeyboard);
    }

    public function invite(Nutgram $bot, bool $rich = true): void
    {
        $actor = $this->navigationActor($bot);
        if ($actor === null) {
            return;
        }

        $home = InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home');
        $username = config('telegram.sales.bot_username');
        if (! is_string($username) || preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/D', $username) !== 1) {
            $this->render(
                $bot,
                '<b>🎁 دریافت جایزه</b>'."\n\nلینک دعوت فعلاً در دسترس نیست. کمی بعد دوباره بررسی کنید.",
                InlineKeyboardMarkup::make()->addRow($home),
            );

            return;
        }

        $invite = $this->invites->handle($actor->userId);
        $url = "https://t.me/{$username}?start=ref_{$invite->code}";
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                '📤 اشتراک‌گذاری در تلگرام',
                url: 'https://t.me/share/url?url='.rawurlencode($url).'&text='.rawurlencode('با لینک من به DenaGate بپیوند 👇'),
                style: ButtonStyle::PRIMARY,
            ))
            ->addRow(TelegramUi::copy($url, '📋 کپی لینک دعوت'))
            ->addRow($home);

        $ordinary = $actor->roles === ['member'];
        $rewardActive = $ordinary && $invite->rewardAmountRials !== null
            && $invite->remainingRewards !== null && $invite->rewardLimit !== null;

        $rows = [
            ['دوستان دعوت‌شده', self::inviteNumber($invite->joinedCount).' نفر'],
            ['دعوت‌های پاداش‌دار', self::inviteNumber($invite->rewardedCount).' نفر'],
            ['مجموع پاداش', self::inviteNumber($invite->earnedRials).' ریال'],
        ];

        if ($rewardActive) {
            $rows[] = ['ظرفیت باقی‌مانده', self::inviteNumber($invite->remainingRewards).' نفر'];
            $rows[] = ['هدیه هر دعوت موفق', self::inviteNumber($invite->rewardAmountRials).' ریال'];
            $totalPrize = $invite->rewardAmountRials * $invite->rewardLimit;
            $terms = 'لینک دعوتت رو برای دوستات بفرست! 💚'
                ."\n\n".'وقتی دوست جدیدت با لینک تو ثبت‌نام کنه و اولین خریدش با موفقیت فعال بشه، '
                .self::inviteNumber($invite->rewardAmountRials).' ریال هدیه به اعتبارت اضافه می‌شه.'
                ."\n\n".'🎉 با معرفی موفق '.self::inviteNumber($invite->rewardLimit)
                .' دوست، می‌تونی تا '.self::inviteNumber($totalPrize).' ریال جایزه بگیری!';
        } elseif ($ordinary) {
            $terms = 'لینک دعوتت رو برای دوستات بفرست! 💚'
                ."\n\n".'طرح جایزه فعلاً فعال نیست؛ وقتی فعال بشه، شرایط دریافت هدیه همین‌جا نمایش داده می‌شه.';
        } else {
            $terms = 'لینک دعوتت فعاله و می‌تونی با دوستات به اشتراکش بذاری. 💚'
                ."\n\n".'برای این نوع حساب، در حال حاضر جایزه‌ای در نظر گرفته نشده.';
        }

        $plain = '<b>🎁 دعوت کن، جایزه بگیر!</b>'
            ."\n\n<b>👥 دعوت‌های من</b>";
        $table = '<table bordered striped compact>';
        foreach ($rows as [$label, $value]) {
            $plain .= "\n".TelegramUi::escape($label).': <b>'.TelegramUi::escape($value).'</b>';
            $table .= '<tr><td>'.TelegramUi::escape($label)
                .'</td><td><b>'.TelegramUi::escape($value).'</b></td></tr>';
        }
        $table .= '</table>';

        $plain .= "\n\n🔗 لینک اختصاصی دعوت:\n".TelegramUi::code($url)
            ."\n\n<b>📋 چطور جایزه بگیرم؟</b>\n".TelegramUi::escape($terms);

        if ($rich) {
            $paragraphs = implode('</p><p>', array_map(
                static fn (string $part): string => TelegramUi::escape($part),
                explode("\n\n", $terms),
            ));
            $html = '<h4>🎁 دعوت کن، جایزه بگیر!</h4>'
                .'<h4>👥 دعوت‌های من</h4>'.$table
                .'<p>🔗 لینک اختصاصی دعوت:<br>'.TelegramUi::escape($url).'</p>'
                .'<details><summary>📋 چطور جایزه بگیرم؟</summary>'
                .'<p>'.$paragraphs.'</p></details>';
            $this->renderRich($bot, $html, $plain, $keyboard, clone $keyboard);

            return;
        }

        $this->render($bot, $plain, $keyboard);
    }

    private static function inviteNumber(int $value): string
    {
        return PersianCalendar::digits(str_replace(',', '٬', number_format($value)));
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

    public function clearInput(int $buyerId, ?Nutgram $bot = null): void
    {
        $trialContactPending = app(SalesTrialUi::class)->clear($buyerId);
        $panelContactPending = app(SalesPanelRequestUi::class)->clear($buyerId);
        if (($trialContactPending || $panelContactPending) && $bot !== null) {
            $actor = $this->resolve->handle((int) $this->privateUser->id($bot));
            $bot->sendMessage(text: 'دریافت شماره بسته شد.', reply_markup: app(ReplyMenuRenderer::class)->salesKeyboard(
                $actor,
                $actor !== null && app(SalesTrialUi::class)->visible($actor, (int) $this->privateUser->id($bot)),
                $actor !== null && $this->panelRequestLabel($actor) === ReplyMenuLabels::SALES_PANEL_STATUS,
            ));
        }
        $this->pendingBuilder->forget($buyerId);
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$buyerId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$buyerId));
        Cache::forget(TelegramBotContext::current()->cacheKey('telegram:wallet:topup:'.$buyerId));
        app(SalesCheckoutUi::class)->clearWalletDraft($buyerId);
        app(SalesSubscriptionsUi::class)->clearCustomerLabelDraft($buyerId);
        app(SalesSubscriptionsUi::class)->clearCustomerPhoneDraft($buyerId);
        app(SalesSubscriptionsUi::class)->clearCustomerSearch($buyerId);
        app(SalesCheckoutUi::class)->clearCustomerName($buyerId);
        app(SalesCheckoutUi::class)->clearCustomerPhone($buyerId);
    }

    private function panelRequestLabel(TelegramActor $actor): ?string
    {
        if (! in_array('member', $actor->roles, true)
            || in_array('reseller', $actor->roles, true)
            || in_array('admin', $actor->roles, true)
            || in_array('super-admin', $actor->roles, true)) {
            return null;
        }

        try {
            if ($this->panelRequests->find($actor->userId) !== null) {
                return ReplyMenuLabels::SALES_PANEL_STATUS;
            }
        } catch (Throwable) {
            // Request lookup failures must not hide the member entry point.
        }

        return ReplyMenuLabels::SALES_PANEL_REQUEST;
    }

    private function salesRole(TelegramActor $actor): string
    {
        return in_array('reseller', $actor->roles, true) ? '🧩 پنل فروش' : '👤 کاربر عادی';
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

    /** Rich presentation only; a failed edit keeps the existing native actions. */
    private function renderRich(
        Nutgram $bot,
        string $html,
        string $plain,
        InlineKeyboardMarkup $keyboard,
        InlineKeyboardMarkup $plainKeyboard,
    ): void {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);
        } catch (TelegramException $exception) {
            report($exception);
            if ($bot->isCallbackQuery()) {
                TelegramUi::editText($bot, $plain, $plainKeyboard, 'HTML');
            } else {
                $bot->sendMessage(text: $plain, reply_markup: $plainKeyboard, parse_mode: 'HTML');
            }
        }
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
