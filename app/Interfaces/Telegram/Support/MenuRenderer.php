<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\PermissionEntry;
use App\Modules\Identity\Application\RoleDirectoryPage;
use App\Modules\Identity\Application\RoleEntry;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Identity\Application\UserDirectoryEntry;
use App\Modules\Identity\Application\UserDirectoryPage;
use App\Modules\Referral\Application\ReferralInvite;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\CopyTextButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

final class MenuRenderer
{
    public function showHome(Nutgram $bot, ?TelegramActor $actor): void
    {
        if ($actor === null) {
            if (config('telegram.public_signup_enabled', false)) {
                $keyboard = InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ ایجاد حساب رایگان', callback_data: 'onboarding:join'));

                $this->render($bot, "👋 خوش آمدید به DenaGate\n\nبرای ایجاد حساب عادی، دکمه زیر را انتخاب کنید.\nاگر قبلاً توسط مدیر حسابی ساخته شده است، به‌جای ثبت‌نام با دستور /link کد اتصال آن را وارد کنید.", $keyboard);

                return;
            }

            $this->render($bot, '🔒 حساب شما به DenaGate متصل نیست. برای اتصال، کد یک‌بارمصرف صادرشده توسط مدیر را در گفت‌وگوی خصوصی با دستور /link وارد کنید.');

            return;
        }

        // The existing BotFather chat remains backwards-compatible, but active
        // operators now land on the dedicated Admin home, never a buyer menu.
        if ($actor->canEnterAdmin()) {
            $this->showAdmin($bot, $actor);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🛒 خرید اشتراک', callback_data: 'menu:shop'))
            ->addRow(InlineKeyboardButton::make('📋 پلن‌ها و قیمت‌ها', callback_data: 'menu:plan-catalog'))
            ->addRow(InlineKeyboardButton::make('📦 سرویس‌های من', callback_data: 'menu:subscriptions'))
            ->addRow(InlineKeyboardButton::make('👤 حساب من', callback_data: 'menu:account'))
            ->addRow(InlineKeyboardButton::make('⚙️ تنظیمات', callback_data: 'menu:settings'))
            ->addRow(InlineKeyboardButton::make('🎁 دعوت دوستان', callback_data: 'menu:invite'));

        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('💼 پلن‌ساز پنل فروش', callback_data: 'menu:builder'));
            $keyboard->addRow(InlineKeyboardButton::make('💰 اعتبار من', callback_data: 'menu:wallet'));
        }

        $this->render($bot, "🏠 DenaGate\n\nخوش آمدید، {$this->displayName($actor->name)}.", $keyboard);
    }

    public function showInvite(
        Nutgram $bot, string $url, int $invitedCount,
        ?ReferralInvite $stats = null,
        bool $ordinary = true,
    ): void {
        $lines = [
            '🎁 دعوت دوستان', '', 'لینک اختصاصی شما:', $url, '',
            '👥 دوستان ثبت‌نام‌شده: '.$invitedCount,
        ];
        if ($stats !== null) {
            $lines[] = '🎁 دوستان دارای پاداش: '.$stats->rewardedCount;
            $lines[] = '💰 مجموع پاداش‌ها: '.TelegramUi::toman($stats->earnedRials).' تومان';
            if ($ordinary && $stats->remainingRewards !== null && $stats->rewardAmountRials !== null) {
                $lines[] = '🎯 ظرفیت باقی‌مانده: '.$stats->remainingRewards;
                $lines[] = '💵 پاداش هر نفر: '.TelegramUi::toman($stats->rewardAmountRials).' تومان';
                $lines[] = '';
                $lines[] = 'پاداش فقط برای کاربر جدید، پس از اولین خرید پولی و فعال‌سازی موفق است؛ تست رایگان و خرید تکراری مشمول نیستند.';
            } else {
                $lines[] = '';
                $lines[] = $ordinary ? 'طرح پاداش فعلاً فعال نیست.' : 'پاداش معرفی مخصوص کاربران عادی است؛ پنل فروش مشمول نیست.';
            }
        }

        $this->render($bot, implode("\n", $lines), $this->homeKeyboard());
    }

    public function showInviteUnavailable(Nutgram $bot): void
    {
        $this->render($bot, 'لینک دعوت هنوز آماده نیست. لطفاً به مدیر اطلاع دهید.', $this->homeKeyboard());
    }

    public function showSignupNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "👤 ثبت‌نام\n\n{$message}", $this->homeKeyboard());
    }

    public function showAccount(Nutgram $bot, TelegramActor $actor): void
    {
        $name = $this->displayName($actor->name);
        $roles = implode('، ', array_map(TelegramUi::role(...), $actor->roles));

        $this->render(
            $bot,
            "👤 حساب من\n\nنام: {$name}\nشناسه DenaGate: {$actor->userId}\nنقش‌ها: {$roles}\nوضعیت: 🟢 فعال",
            $this->homeKeyboard(),
        );
    }

    public function showSettings(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "⚙️ تنظیمات\n\nدر حال حاضر گزینه‌ای برای تغییر تنظیمات وجود ندارد.",
            $this->homeKeyboard(),
        );
    }

    public function showAdmin(Nutgram $bot, TelegramActor $actor): void
    {
        $keyboard = InlineKeyboardMarkup::make();

        if ($actor->canManageRoles()) {
            $keyboard->addRow(
                InlineKeyboardButton::make('🧭 داشبورد', callback_data: 'kpi:period:day'),
                InlineKeyboardButton::make('📈 گزارش‌ها', callback_data: 'stats:overview'),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('👥 کاربران', callback_data: 'menu:admin:users'),
                InlineKeyboardButton::make('💳 امور مالی', callback_data: 'menu:admin:finance'),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('📡 دنابان', callback_data: 'denaban:show'),
                InlineKeyboardButton::make('⚙️ کنترل پنل', callback_data: 'menu:admin:control'),
            );
        } else {
            if ($actor->allows('users.view') || $actor->allows('support.view')) {
                $keyboard->addRow(InlineKeyboardButton::make('👥 کاربران', callback_data: 'menu:admin:users'));
            }
            if ($actor->allows('plans.manage') || $actor->allows('resellers.review')) {
                $keyboard->addRow(InlineKeyboardButton::make('📦 محصولات و پلن‌ها', callback_data: 'menu:admin:sales'));
            }
            if ($actor->allows('reports.view')) {
                $keyboard->addRow(InlineKeyboardButton::make('📊 گزارش‌های عملیاتی', callback_data: 'rbac2:reports'));
            }
            if ($actor->allows('finance.view')) {
                $keyboard->addRow(InlineKeyboardButton::make('💳 گزارش مالی', callback_data: 'rbac2:finance:1'));
            }
            if ($actor->allows('servers.view')) {
                $keyboard->addRow(InlineKeyboardButton::make('🖥️ وضعیت سرورها', callback_data: 'rbac2:servers'));
            }
            if ($actor->allows('purchases.view') || $actor->allows('topups.view')) {
                $keyboard->addRow(InlineKeyboardButton::make('🧾 بررسی رسیدها', callback_data: 'menu:admin:reviews'));
            }
        }

        if (! $actor->canManageRoles()
            && config('telegram_notifications.admin_topic.enabled') === true
            && AdminNotificationTopicUi::eligible($actor)) {
            $keyboard->addRow(InlineKeyboardButton::make('🔔 اعلان‌های من', callback_data: 'admin:notice-topic:show'));
        }

        $this->render($bot, "🛡️ مدیریت DenaGate\n\nبخش موردنظر را انتخاب کنید.", $keyboard);
    }

    public function showAdminControlPanel(Nutgram $bot, TelegramActor $actor): void
    {
        if (! $actor->canManageRoles()) {
            $this->denyAccess($bot);

            return;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('📦 کاتالوگ', callback_data: 'menu:admin:sales'),
                InlineKeyboardButton::make('🖥️ سرورها', callback_data: 'menu:admin:servers'),
            )
            ->addRow(
                InlineKeyboardButton::make('💾 پشتیبان‌گیری', callback_data: 'admin:rwbackup:list:1'),
                InlineKeyboardButton::make('🔐 نقش‌ها و دسترسی‌ها', callback_data: 'menu:admin:roles'),
            )
            ->addRow(InlineKeyboardButton::make('📢 مدیریت اعلانات', callback_data: 'admin:ann:list:1'))
            ->addRow(InlineKeyboardButton::make('⚙️ تنظیمات سیستم', callback_data: 'admin:welcome:show'))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, "⚙️ کنترل پنل\n\nبخش موردنظر را انتخاب کنید.", $keyboard);
    }

    public function showAdminReceiptQueues(Nutgram $bot, TelegramActor $actor): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        if ($actor->allows('purchases.view')) {
            $keyboard->addRow(InlineKeyboardButton::make('🧾 رسیدهای خرید', callback_data: 'bank:list'));
        }
        if ($actor->allows('topups.view')) {
            $keyboard->addRow(InlineKeyboardButton::make('💰 رسیدهای شارژ', callback_data: 'finance:topups:1'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $this->render($bot, "🧾 بررسی رسیدها\n\nبخش موردنظر را انتخاب کنید.", $keyboard);
    }

    public function showAdminSales(Nutgram $bot, ?TelegramActor $actor = null): void
    {
        $root = $actor === null || $actor->canManageRoles();
        $plans = $root || $actor->allows('plans.manage');
        $reviews = $root || $actor->allows('resellers.review');
        $keyboard = InlineKeyboardMarkup::make();
        if ($plans) {
            $keyboard->addRow(
                InlineKeyboardButton::make('📚 محصولات پایه', callback_data: 'menu:admin:products'),
                InlineKeyboardButton::make('📦 مدیریت پلن‌ها', callback_data: 'menu:admin:plans'),
            );
        }
        if ($root) {
            $keyboard->addRow(InlineKeyboardButton::make('🔌 فعال‌سازی اشتراک‌ها', callback_data: 'menu:admin:fulfillment'));
        }
        if ($reviews) {
            $keyboard->addRow(InlineKeyboardButton::make('🧩 درخواست‌های پنل فروش', callback_data: 'sp:list:1'));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            $root ? '◀️ کنترل پنل' : '◀️ مدیریت',
            callback_data: $root ? 'menu:admin:control' : 'menu:admin',
        ));

        $this->render($bot, $root
            ? "📦 کاتالوگ\n\nمدیریت محصولات، پلن‌ها و قیمت‌ها."
            : "📦 محصولات و پلن‌ها\n\nبخش موردنظر را انتخاب کنید.", $keyboard);
    }

    /** @param array{balanceRials:int,walletsTotal:int,walletsPositive:int,walletsNegative:int,pendingTopups:int}|null $walletSummary */
    public function showAdminFinance(Nutgram $bot, ?array $walletSummary = null): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('💼 کیف پول کاربران', callback_data: 'finance:wallets:1'))
            ->addRow(InlineKeyboardButton::make('💰 گزارش دریافتی‌ها', callback_data: 'finance:cash:30'));
        if (config('billing.topups_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('🖼️ رسیدهای شارژ کیف پول', callback_data: 'finance:topups:1'))
                ->addRow(InlineKeyboardButton::make('💳 بررسی شارژهای قبلی', callback_data: 'menu:billing-reviews'));
        }
        if (config('sales.manual_bank_review_enabled', false)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧾 بررسی رسیدهای خرید', callback_data: 'bank:list'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('📒 دفتر مالی', callback_data: 'acc:home'));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $lines = ['💳 امور مالی', ''];
        if ($walletSummary === null) {
            $lines[] = 'آمار کیف پول فعلاً در دسترس نیست.';
        } else {
            $lines[] = '💼 مجموع مانده کیف پول‌ها: '
                .number_format(intdiv($walletSummary['balanceRials'], 10)).' تومان';
            $lines[] = 'کیف پول‌ها: '.number_format($walletSummary['walletsTotal'])
                .' | دارای موجودی: '.number_format($walletSummary['walletsPositive']);
            $lines[] = 'شارژهای در انتظار تأیید: '.number_format($walletSummary['pendingTopups']);
            if ($walletSummary['walletsNegative'] > 0) {
                $lines[] = '⚠️ کیف پول با مانده منفی: '.number_format($walletSummary['walletsNegative']);
            }
            $lines[] = 'مانده کیف پول درآمد فروش نیست.';
        }
        $lines[] = '';
        $lines[] = 'بخش موردنظر را انتخاب کنید.';

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function showUserList(Nutgram $bot, UserDirectoryPage $results, string $mode = 'a', bool $trustedRoot = false): void
    {
        $type = UserDirectoryMode::type($mode);
        $sort = UserDirectoryMode::sort($mode);
        $direction = UserDirectoryMode::direction($mode);
        $filter = UserDirectoryMode::filter($mode);
        $filterLabel = UserDirectoryMode::FILTER_LABELS[$filter];
        $header = match (true) {
            $mode === 's' => '🔎 نتایج جست‌وجوی کاربران',
            $type === 'r' => '🧩 نمایندگان',
            $type === 'm' => '👤 کاربران عادی',
            default => '👥 مدیریت کاربران',
        };
        $lines = [$header, '', "تعداد: {$results->total} | صفحه {$results->page} از {$results->lastPage}"];
        $sortLabel = match ($sort) {
            'wallet' => $direction === 'asc' ? '💰 کمترین موجودی' : '💰 بیشترین موجودی',
            'purchase' => $direction === 'asc' ? '🛒 کمترین خرید' : '🛒 بیشترین خرید',
            default => $direction === 'asc' ? '🕒 قدیمی‌ترین' : '🕒 جدیدترین',
        };
        if ($trustedRoot && $mode !== 's') {
            $lines[] = 'مرتب‌سازی: '.$sortLabel.' · '.($direction === 'asc' ? 'صعودی' : 'نزولی');
            if ($filter !== '') {
                $lines[] = 'فیلتر: '.$filterLabel;
            }
        }

        $keyboard = InlineKeyboardMarkup::make();
        if ($trustedRoot) {
            $tab = static fn (string $name, string $target) => InlineKeyboardButton::make(
                ($type === $target && $mode !== 's' ? '✅ ' : '').$name,
                callback_data: UserDirectoryMode::pageCallback(
                    UserDirectoryMode::for($target, $sort, $direction, $filter) ?? $target, 1,
                ),
            );
            $keyboard->addRow(
                $tab('همه', 'a'),
                $tab('عادی', 'm'),
                $tab('نمایندگان', 'r'),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('🔃 مرتب‌سازی', callback_data: 'users:sort:'.($mode === 's' ? 'a' : $mode)),
                InlineKeyboardButton::make('🧰 فیلترها', callback_data: 'users:filters:'.($mode === 's' ? 'a' : $mode)),
            );
        } else {
            // Existing delegated-admin directory stays unchanged and has no financial controls.
            $keyboard->addRow(
                InlineKeyboardButton::make($mode === 'a' ? '✅ همه کاربران' : '👥 همه کاربران', callback_data: 'users:page:1'),
                InlineKeyboardButton::make($mode === 'r' ? '✅ نمایندگان' : '🧩 نمایندگان', callback_data: 'users:resellers:1'),
            );
        }

        $buttons = [];
        foreach ($results->users as $user) {
            $status = $user->active ? '🟢' : '🔴';
            $name = mb_substr($this->displayName($user->name), 0, $sort === 'recent' ? 16 : 8);
            $metric = $sort === 'recent' ? '' : ' · '.TelegramUi::toman($user->directoryMetricRials ?? 0).'ت';
            $buttons[] = InlineKeyboardButton::make(
                "{$status} #{$user->id} {$name}{$metric}",
                callback_data: "users:view:{$user->id}:{$results->page}:{$mode}",
            );
        }
        foreach (array_chunk($buttons, 2) as $row) {
            $keyboard->addRow(...$row);
        }

        if ($results->users === []) {
            $lines[] = '';
            $lines[] = 'کاربری پیدا نشد.';
        }

        $previous = $results->page > 1
            ? InlineKeyboardButton::make('◀️ قبلی', callback_data: UserDirectoryMode::pageCallback($mode, $results->page - 1))
            : null;
        $next = $results->page < $results->lastPage
            ? InlineKeyboardButton::make('بعدی ▶️', callback_data: UserDirectoryMode::pageCallback($mode, $results->page + 1))
            : null;

        if ($previous !== null && $next !== null) {
            $keyboard->addRow($previous, $next);
        } elseif ($previous !== null) {
            $keyboard->addRow($previous);
        } elseif ($next !== null) {
            $keyboard->addRow($next);
        }

        $keyboard->addRow(InlineKeyboardButton::make('🔎 جست‌وجو', callback_data: 'users:search'));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        if ($sort !== 'recent') {
            $html = '<h3>'.TelegramUi::escape($header).'</h3>'
                .'<table bordered striped compact>'
                .'<tr><td>تعداد</td><td>'.number_format($results->total).'</td></tr>'
                .'<tr><td>مرتب‌سازی</td><td>'.TelegramUi::escape($sortLabel).'</td></tr>'
                .'<tr><td>جهت</td><td>'.($direction === 'asc' ? 'صعودی' : 'نزولی').'</td></tr>'
                .'<tr><td>فیلتر</td><td>'.TelegramUi::escape($filterLabel).'</td></tr>'
                .'</table><footer>مبالغ به تومان هستند؛ روی نام کاربر بزنید. '
                .'صفحه '.number_format($results->page).' از '.number_format($results->lastPage).'</footer>';
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);
                if ($bot->isCallbackQuery()) {
                    TelegramUi::answerCallback($bot);
                }

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Preserve the same sorted list, exact figures and callbacks.
            }
        }

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function showUserSort(Nutgram $bot, string $mode): void
    {
        $type = UserDirectoryMode::type($mode);
        $current = UserDirectoryMode::sort($mode);
        $direction = UserDirectoryMode::direction($mode);
        $filter = UserDirectoryMode::filter($mode);
        $keyboard = InlineKeyboardMarkup::make();
        foreach ([
            'recent' => '🕒 زمان ثبت‌نام',
            'wallet' => '💰 موجودی کیف پول',
            'purchase' => '🛒 مجموع خرید',
        ] as $sort => $label) {
            $target = UserDirectoryMode::for($type, $sort, $direction, $filter);
            if ($target !== null) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    ($current === $sort ? '✅ ' : '').$label,
                    callback_data: UserDirectoryMode::pageCallback($target, 1),
                ));
            }
        }
        $keyboard->addRow(
            InlineKeyboardButton::make(
                ($direction === 'desc' ? '✅ ' : '').'⬇️ نزولی',
                callback_data: UserDirectoryMode::pageCallback(
                    UserDirectoryMode::for($type, $current, 'desc', $filter) ?? 'a', 1,
                ),
            ),
            InlineKeyboardButton::make(
                ($direction === 'asc' ? '✅ ' : '').'⬆️ صعودی',
                callback_data: UserDirectoryMode::pageCallback(
                    UserDirectoryMode::for($type, $current, 'asc', $filter) ?? 'a', 1,
                ),
            ),
        );
        $keyboard->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت به کاربران', callback_data: UserDirectoryMode::pageCallback($mode, 1),
        ));

        $this->render($bot, "🔃 مرتب‌سازی کاربران\n\nمعیار و جهت مرتب‌سازی را انتخاب کنید.", $keyboard);
    }

    public function showUserFilters(Nutgram $bot, string $mode): void
    {
        $type = UserDirectoryMode::type($mode);
        $sort = UserDirectoryMode::sort($mode);
        $direction = UserDirectoryMode::direction($mode);
        $current = UserDirectoryMode::filter($mode);
        $keyboard = InlineKeyboardMarkup::make();
        $buttons = [];
        foreach (UserDirectoryMode::FILTER_LABELS as $filter => $label) {
            $target = UserDirectoryMode::for($type, $sort, $direction, $filter);
            if ($target !== null) {
                $buttons[] = InlineKeyboardButton::make(
                    ($current === $filter ? '✅ ' : '').$label,
                    callback_data: UserDirectoryMode::pageCallback($target, 1),
                );
            }
        }
        foreach (array_chunk($buttons, 2) as $row) {
            $keyboard->addRow(...$row);
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت به کاربران', callback_data: UserDirectoryMode::pageCallback($mode, 1),
        ));

        $this->render(
            $bot,
            "🧰 فیلتر کاربران\n\nیک فیلتر انتخاب کنید. نتایج براساس داده‌های ثبت‌شده دناگیت هستند، نه وضعیت لحظه‌ای سرویس در پنل.",
            $keyboard,
        );
    }

    public function showUserDetails(Nutgram $bot, UserDirectoryEntry $user, int $page = 1, bool $search = false, bool $manageRoles = false, bool $profileAllowed = true, bool $resellersOnly = false, ?string $contactUrl = null, ?string $directoryMode = null): void
    {
        $name = $this->displayName($user->name);
        // Telegram enrollment stores a non-routable random placeholder, not contact email.
        $email = preg_match('/\Atelegram-[0-9a-f]{32}@accounts\.skynet\.invalid\z/iD', $user->email) === 1
            ? 'ایمیل ثبت نشده'
            : $this->displayName($user->email);
        $roles = implode('، ', $user->roles);
        $status = $user->active ? '🟢 فعال' : '🔴 غیرفعال';
        $linked = $user->telegramLinked ? 'متصل' : 'متصل نیست';
        $telegramId = $user->telegramUserId === null ? 'ثبت نشده' : (string) $user->telegramUserId;
        $mode = $directoryMode ?? ($search ? 's' : ($resellersOnly ? 'r' : 'a'));

        $keyboard = InlineKeyboardMarkup::make();
        if ($user->telegramUserId !== null) {
            $keyboard->addRow(InlineKeyboardButton::make(
                '📋 کپی شناسه تلگرام', copy_text: CopyTextButton::make((string) $user->telegramUserId),
            ));
        }
        if ($profileAllowed) {
            $keyboard->addRow(InlineKeyboardButton::make('📋 پرونده کاربر', callback_data: "users:profile:{$user->id}:{$page}:{$mode}"));
        }

        if ($contactUrl !== null) {
            $keyboard->addRow(InlineKeyboardButton::make('💬 ارتباط با کاربر', url: $contactUrl));
        }

        if ($user->canChangeStatus) {
            $label = $user->active ? '⛔ غیرفعال‌کردن' : '✅ فعال‌کردن';
            $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: "users:confirm:{$user->id}:{$page}:{$mode}"));
        }

        if ($manageRoles && ! in_array('super-admin', $user->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('🔐 مدیریت نقش‌های کاربر', callback_data: "rbac:user:{$user->id}:1"));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: UserDirectoryMode::pageCallback($mode, $page)));
        $keyboard->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));

        $footer = '💡 وضعیت حساب و اتصال تلگرام از اطلاعات ثبت‌شده خوانده می‌شود.';
        $text = "👤 جزئیات کاربر\n\nشناسه: {$user->id}\nنام: {$name}\nایمیل: {$email}\nنقش‌ها: {$roles}\nوضعیت: {$status}\nتلگرام: {$linked}\nشناسه تلگرام: {$telegramId}\n\n{$footer}";
        $rows = [
            ['شناسه', (string) $user->id],
            ['نام', $name],
            ['ایمیل', $email],
            ['نقش‌ها', $roles],
            ['وضعیت', $status],
            ['تلگرام', $linked],
            ['شناسه تلگرام', $telegramId],
        ];
        $table = '';
        foreach ($rows as [$label, $value]) {
            $table .= '<tr><td>'.TelegramUi::escape($label).'</td><td>'
                .TelegramUi::escape($value).'</td></tr>';
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        try {
            TelegramUi::editRichText($bot, new InputRichMessage(
                html: '<h2>👤 جزئیات کاربر</h2><table bordered striped compact>'
                    .$table.'</table><footer>'.$footer.'</footer>',
                is_rtl: true,
            ), $keyboard);

            return;
        } catch (TelegramException $exception) {
            report($exception);
            // The same authorized identity snapshot and buttons remain available.
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }

    public function showUserStatusConfirmation(Nutgram $bot, UserDirectoryEntry $user, string $token, int $page, bool $search = false, bool $resellersOnly = false, ?string $directoryMode = null): void
    {
        $action = $user->active ? 'غیرفعال' : 'فعال';
        $mode = $directoryMode ?? ($search ? 's' : ($resellersOnly ? 'r' : 'a'));
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید عملیات', callback_data: 'users:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: "users:view:{$user->id}:{$page}:{$mode}"));

        $this->render(
            $bot,
            "⚠️ تأیید تغییر وضعیت\n\nآیا حساب #{$user->id} ({$this->displayName($user->name)}) {$action} شود؟\n\nاین تأیید فقط ۳ دقیقه اعتبار دارد.",
            $keyboard,
        );
    }

    public function showUserSearchHelp(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "🔎 جست‌وجوی کاربران\n\nنام، ایمیل، شناسه داخلی، شناسه تلگرام یا شناسه سرویس (sk_...) را ارسال کنید.\n\nحداکثر ۶۴ نویسه. برای لغو، دکمه زیر را بزنید.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'users:cancelsearch'))
                ->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'menu:admin:users')),
        );
    }

    public function showUserNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "👥 مدیریت کاربران\n\n{$message}", $this->userBackKeyboard());
    }

    public function showRolesList(Nutgram $bot, RoleDirectoryPage $page): void
    {
        $text = "🔐 نقش‌ها و دسترسی‌ها\n\nتعداد: {$page->total} | صفحه {$page->page} از {$page->lastPage}\n";
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($page->roles as $role) {
            $mark = $role->protected ? '🔒' : '🧩';
            $text .= "\n{$mark} {$role->name} — {$role->userCount} کاربر";
            $keyboard->addRow(InlineKeyboardButton::make(
                "{$mark} {$role->name}",
                callback_data: "rbac:role:{$role->id}:{$page->page}",
            ));
        }

        if ($page->roles === []) {
            $text .= "\nنقشی پیدا نشد.";
        }

        $keyboard->addRow(InlineKeyboardButton::make('➕ نقش جدید', callback_data: 'rbac:new'));

        if ($page->page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'rbac:page:'.($page->page - 1)));
        }

        if ($page->page < $page->lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'rbac:page:'.($page->page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ کنترل پنل', callback_data: 'menu:admin:control'));
        $this->render($bot, $text, $keyboard);
    }

    /**
     * @param  list<PermissionEntry>  $available
     */
    public function showRoleDetails(Nutgram $bot, RoleEntry $role, array $available, int $page, int $permissionPage = 1): void
    {
        $assigned = $role->permissions === [] ? 'هیچ مجوزی' : implode('، ', $role->permissions);
        $text = "🔐 نقش: {$role->name}\n\nکاربران: {$role->userCount}\nمجوزها: {$assigned}";
        $keyboard = InlineKeyboardMarkup::make();

        if ($role->protected) {
            $text .= "\n\n🔒 نقش سیستمی محافظت‌شده است و از ربات قابل تغییر نیست.";
        } else {
            $permissionPages = max(1, (int) ceil(count($available) / 8));
            $permissionPage = max(1, min($permissionPage, $permissionPages));
            $text .= "\nصفحه مجوزها: {$permissionPage} از {$permissionPages}";

            foreach (array_slice($available, ($permissionPage - 1) * 8, 8) as $permission) {
                $on = in_array($permission->name, $role->permissions, true);
                $keyboard->addRow(InlineKeyboardButton::make(
                    ($on ? '✅ ' : '⬜ ').$permission->name,
                    callback_data: "rbac:perm:{$role->id}:{$permission->id}",
                ));
            }

            if ($permissionPage > 1) {
                $keyboard->addRow(InlineKeyboardButton::make('◀️ مجوزهای قبلی', callback_data: "rbac:p:{$role->id}:{$page}:".($permissionPage - 1)));
            }

            if ($permissionPage < $permissionPages) {
                $keyboard->addRow(InlineKeyboardButton::make('مجوزهای بعدی ▶️', callback_data: "rbac:p:{$role->id}:{$page}:".($permissionPage + 1)));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ همه نقش‌ها', callback_data: "rbac:page:{$page}"));
        $this->render($bot, $text, $keyboard);
    }

    public function showUserRoles(Nutgram $bot, UserDirectoryEntry $user, RoleDirectoryPage $page): void
    {
        $text = "🔐 نقش‌های کاربر #{$user->id}\n\n{$this->displayName($user->name)}\nنقش‌های فعلی: ".implode('، ', $user->roles);
        $keyboard = InlineKeyboardMarkup::make();
        $protected = in_array('super-admin', $user->roles, true);

        foreach ($page->roles as $role) {
            if ($protected || $role->name === 'super-admin') {
                continue;
            }

            $on = in_array($role->name, $user->roles, true);
            $keyboard->addRow(InlineKeyboardButton::make(
                ($on ? '✅ ' : '⬜ ').$role->name,
                callback_data: "rbac:member:{$user->id}:{$role->id}:{$page->page}",
            ));
        }

        if ($protected) {
            $text .= "\n\n🔒 حساب Super Admin محافظت شده است.";
        }

        if ($page->page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: "rbac:user:{$user->id}:".($page->page - 1)));
        }

        if ($page->page < $page->lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: "rbac:user:{$user->id}:".($page->page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ صفحه کاربر', callback_data: "users:view:{$user->id}:1:a"));
        $this->render($bot, $text, $keyboard);
    }

    public function showRoleCreateHelp(Nutgram $bot): void
    {
        $this->render(
            $bot,
            "➕ ایجاد نقش سفارشی\n\nدر گفت‌وگوی خصوصی ارسال کنید:\n/newrole reseller\n\nنام نقش فقط از حروف انگلیسی کوچک، اعداد و خط تیره (۳ تا ۵۰ نویسه) تشکیل می‌شود.\n\nپس از تأیید، نقش بدون مجوز ایجاد خواهد شد؛ مجوزها را از صفحه نقش تخصیص دهید.",
            $this->rbacBackKeyboard(),
        );
    }

    public function showRbacConfirmation(Nutgram $bot, string $operation, string $token): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید عملیات', callback_data: 'rbac:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('انصراف', callback_data: 'menu:admin:roles'));

        $this->render($bot, "⚠️ تأیید عملیات نقش و دسترسی\n\n{$operation}\n\nاین تأیید یک‌بارمصرف و به‌مدت ۳ دقیقه معتبر است.", $keyboard);
    }

    public function showRoleNotice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "🔐 مدیریت نقش‌ها\n\n{$message}", $this->rbacBackKeyboard());
    }

    private function rbacBackKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ نقش‌ها', callback_data: 'menu:admin:roles'))
            ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));
    }

    public function denyAccess(Nutgram $bot): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot, 'دسترسی مجاز نیست.');

            return;
        }

        $bot->sendMessage('دسترسی مجاز نیست.');
    }

    private function userBackKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کاربران', callback_data: 'menu:admin:users'))
            ->addRow(InlineKeyboardButton::make('🏠 مدیریت', callback_data: 'menu:admin'));
    }

    private function homeKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ صفحه اصلی', callback_data: 'menu:home'));
    }

    private function displayName(string $name): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
    }

    private function render(Nutgram $bot, string $text, ?InlineKeyboardMarkup $keyboard = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);

            $message = $bot->message();
            // Telegram cannot edit a photo/document caption into a text message.
            // Keep the original receipt/media intact and open a fresh menu.
            if (($message->photo ?? []) !== []
                || $message?->document !== null
                || $message?->video !== null
                || $message?->animation !== null
                || $message?->audio !== null
                || $message?->voice !== null
                || $message?->sticker !== null) {
                $bot->sendMessage(text: $text, reply_markup: $keyboard);

                return;
            }

            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}
