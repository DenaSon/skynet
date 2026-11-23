<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminArianetCloudLinks;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use App\Modules\Provisioning\Application\ReadAdminArianetCloud;
use App\Support\PersianCalendar;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/**
 * ARI-02A: read-only Arianet Cloud in the private trusted-root Admin Bot.
 * This UI is never connected to Cloud writes or Remnawave/Cloudflare mutations.
 */
final readonly class AdminArianetCloudUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminArianetCloud $cloud,
        private ManageAdminArianetCloudLinks $links,
        private ManageAdminNodes $nodes,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $requested = '1'): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $page = self::number($requested, 100);
        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تلاش مجدد', callback_data: 'ari2:list:1'))
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));
        if ($page === null) {
            $this->render($bot, 'صفحه معتبر نیست.', $back);

            return;
        }

        try {
            $result = $this->cloud->browse($root, $page);
        } catch (Throwable) {
            $this->render($bot, '☁️ سرورهای ابری'."\n\n".'دریافت فهرست ممکن نیست؛ اتصال API را بررسی و دوباره تلاش کنید.', $back);

            return;
        }

        $buttons = InlineKeyboardMarkup::make();
        foreach ($result['items'] as $server) {
            $buttons->addRow(InlineKeyboardButton::make(
                self::statusIcon($server['status']).' '.self::short($server['name'], 32),
                callback_data: 'ari2:view:'.$server['id'].':'.$page,
            ));
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'ari2:list:'.($page - 1));
        }
        if ($page < $result['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'ari2:list:'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('📦 پلن‌های ابری', callback_data: 'ari2:dc'));
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:list:'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ وضعیت سرورها', callback_data: 'menu:admin:servers'));

        $count = count($result['items']);
        $running = count(array_filter($result['items'], static fn (array $server): bool => $server['status'] === 'running'));
        $label = $result['total'] === null ? 'تعداد این صفحه' : 'کل سرورها';
        $total = $result['total'] ?? $count;
        $rows = self::row($label, self::digits($total))
            .self::row('روشن در این صفحه', self::digits($running).' از '.self::digits($count))
            .self::row('صفحه', self::digits($page).' از '.self::digits($result['pages']));
        if ($result['monthlyRials'] !== null) {
            $rows .= self::row('برآورد ماهانه حساب تک‌سروری', self::toman($result['monthlyRials']));
        }

        $list = '';
        $simpleList = '';
        foreach ($result['items'] as $server) {
            $name = self::short($server['name'], 48);
            $description = ($server['datacenter'] === 'نامشخص' ? '—' : $server['datacenter'])
                .' · '.($server['ipv4'] ?? 'IPv4 نامشخص');
            $list .= '<tr><td>'.TelegramUi::escape(self::statusIcon($server['status']).' '.$name)
                .'</td><td>'.TelegramUi::escape($description).'</td></tr>';
            $simpleList .= "\n".self::statusIcon($server['status']).' '.TelegramUi::escape($name)
                .' · '.TelegramUi::escape($description);
        }
        $rich = '<h2>☁️ سرورهای ابری</h2><p>آریاسرویس · وضعیت زمان درخواست</p>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .($list === '' ? '<p>هنوز سرور ابری ثبت نشده است.</p>'
                : '<table bordered striped compact>'.$list.'</table>')
            .'<footer>💡 هزینه مجموع فقط در حساب تک‌سروری با دریافت هزینه واقعی نمایش داده می‌شود. این بخش صرفاً خواندنی است.</footer>';
        $simple = '<b>☁️ سرورهای ابری</b>'."\n\n"
            .TelegramUi::escape($label).': '.self::digits($total)
            ."\nروشن در این صفحه: ".self::digits($running).' از '.self::digits($count)
            ."\nصفحه: ".self::digits($page).' از '.self::digits($result['pages'])
            .($result['monthlyRials'] === null ? '' : "\nبرآورد ماهانه: ".self::toman($result['monthlyRials']))
            ."\n".($simpleList === '' ? "\nهنوز سرور ابری ثبت نشده است." : $simpleList)
            ."\n\nبرای مشاهده جزئیات، یک سرور را انتخاب کنید.";
        $this->render($bot, $simple, $buttons, $rich);
    }

    public function detail(Nutgram $bot, string $requestedId, string $requestedPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::number($requestedId, PHP_INT_MAX);
        $page = self::number($requestedPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'شناسه یا صفحه معتبر نیست.', self::listBack(1));

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:view:'.$id.':'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ فهرست سرورها', callback_data: 'ari2:list:'.$page));

        try {
            $server = $this->cloud->detail($root, $id);
        } catch (Throwable) {
            $this->render($bot, 'جزئیات سرور در دسترس نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }

        $cost = null;
        try {
            $cost = $this->cloud->costs($root, $id);
        } catch (Throwable) {
            // Cost failure must not hide the already-verified server identity.
        }
        $link = null;
        $linkUnavailable = false;
        $nodeName = null;
        try {
            $link = $this->links->linked($root, $id);
            if ($link !== null) {
                try {
                    $node = $this->nodes->cloudLinkNode($root, $link['nodeUuid']);
                    $nodeName = $node['name'];
                } catch (Throwable) {
                    // The saved identity remains visible even during RW downtime.
                }
            }
        } catch (Throwable) {
            $linkUnavailable = true;
        }

        $buttons = InlineKeyboardMarkup::make();
        if ($server['ipv4'] !== null) {
            $buttons->addRow(TelegramUi::copy($server['ipv4'], '📋 کپی IPv4'));
        }
        $buttons->addRow(
            InlineKeyboardButton::make('💰 هزینه‌ها', callback_data: 'ari2:cost:'.$id.':'.$page),
            InlineKeyboardButton::make('🕒 سوابق عملیات', callback_data: 'ari2:history:'.$id.':'.$page.':1'),
        );
        $buttons->addRow(InlineKeyboardButton::make('🔎 پیش‌نمایش تعویض IPv4',
            callback_data: 'ari4:preview:'.$id.':'.$page));
        if (! $linkUnavailable) {
            if ($link === null) {
                $buttons->addRow(InlineKeyboardButton::make('🔗 اتصال به نود',
                    callback_data: 'ari3:nodes:'.$id.':'.$page.':1'));
            } else {
                $buttons->addRow(InlineKeyboardButton::make('🖥️ مشاهده نود مرتبط',
                    callback_data: 'node1:view:'.$link['nodeUuid']));
                $buttons->addRow(
                    InlineKeyboardButton::make('🔁 تغییر نود',
                        callback_data: 'ari3:nodes:'.$id.':'.$page.':1'),
                    InlineKeyboardButton::make('⛓️ قطع ارتباط',
                        callback_data: 'ari3:unlink:'.$id.':'.$page),
                );
            }
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:view:'.$id.':'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ فهرست سرورها', callback_data: 'ari2:list:'.$page));

        $fee = self::changeFee($server);
        $fields = [
            ['وضعیت', self::statusLabel($server['status'], false)],
            ['شناسه', (string) $id],
            ['دیتاسنتر', $server['datacenter']],
            ['پلن', $server['planId'] === null ? 'نامشخص' : '#'.$server['planId']],
            ['دیسک', $server['diskGb'] === null ? 'نامشخص' : self::digits($server['diskGb']).' گیگابایت'],
            ['IPv4', $server['ipv4'] ?? 'نامشخص'],
            ['IPv6', $server['ipv6'] ?? 'نامشخص'],
            ['تعویض رایگان باقی‌مانده', $server['freeLeft'] === null ? 'نامشخص' : self::digits($server['freeLeft'])],
            ['هزینه تعویض بعدی', $fee],
            ['امکان تعویض IP', self::yesNo($server['changeAllowed'])],
            ['عملیات IP معلق', self::yesNo($server['pending'])],
            ['برآورد ماهانه', $cost === null || $cost['monthlyRials'] === null
                ? 'نامشخص' : self::toman($cost['monthlyRials'])],
            ['نود مرتبط', $linkUnavailable ? 'در دسترس نیست'
                : ($link === null ? 'ثبت نشده' : ($nodeName ?? 'ثبت شده؛ اطلاعات نود در دسترس نیست'))],
        ];

        $richRows = '';
        $simple = '<b>☁️ '.TelegramUi::escape($server['name']).'</b>'."\n";
        foreach ($fields as [$label, $value]) {
            $richRows .= self::row($label, $value);
            $simple .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $rich = '<h2>☁️ '.TelegramUi::escape($server['name']).'</h2>'
            .'<table bordered striped compact>'.$richRows.'</table>'
            .'<footer>🔒 فقط ارتباط داخلی با نود قابل ثبت یا قطع است؛ IP، DNS و تنظیمات سرور تغییر نمی‌کنند.</footer>';
        $this->render($bot, $simple, $buttons, $rich);
    }

    public function costs(Nutgram $bot, string $requestedId, string $requestedPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::number($requestedId, PHP_INT_MAX);
        $page = self::number($requestedPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'شناسه معتبر نیست.', self::listBack(1));

            return;
        }
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:cost:'.$id.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور', callback_data: 'ari2:view:'.$id.':'.$page));
        try {
            $cost = $this->cloud->costs($root, $id);
        } catch (Throwable) {
            $this->render($bot, 'دریافت هزینه‌ها ممکن نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }

        $values = [
            ['نرخ ساعتی', self::money($cost['hourlyRials'])],
            ['برآورد ماهانه', self::money($cost['monthlyRials'])],
            ['مبنای محاسبه ماهانه', $cost['hoursPerMonth'] === null ? 'نامشخص'
                : self::digits($cost['hoursPerMonth']).' ساعت'],
            ['اسنپ‌شات‌ها', self::amount($cost['snapshots'])],
            ['پشتیبان‌های نگه‌داری‌شده', self::amount($cost['backups'])],
        ];
        $rows = '';
        $simple = '<b>💰 هزینه‌های سرور</b>';
        foreach ($values as [$name, $value]) {
            $rows .= self::row($name, $value);
            $simple .= "\n".TelegramUi::escape($name).': '.TelegramUi::escape($value);
        }
        $detail = '';
        foreach ($cost['lines'] as $line) {
            $label = $line['name'].' · '.$line['type'];
            $amount = self::money($line['monthlyRials']);
            $detail .= self::row($label, $amount);
            $simple .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($amount);
        }
        if ($cost['additionalLines'] > 0) {
            $simple .= "\n".'ردیف‌های بیشتر در پنل ارائه‌دهنده: '.self::digits($cost['additionalLines']);
        }

        $rich = '<h2>💰 هزینه‌های سرور</h2><table bordered striped compact>'.$rows.'</table>'
            .($detail === '' ? '' : '<h3>📋 ردیف‌های هزینه</h3><table bordered striped compact>'.$detail.'</table>')
            .'<footer>این مبالغ نرخ‌های فعلی آریاسرویس هستند، نه صورتحساب قطعی مصرف گذشته.</footer>';
        $this->render($bot, $simple, $buttons, $rich);
    }

    public function history(Nutgram $bot, string $requestedId, string $requestedListPage, string $requestedPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::number($requestedId, PHP_INT_MAX);
        $listPage = self::number($requestedListPage, 100);
        $page = self::number($requestedPage, 100);
        if ($id === null || $listPage === null || $page === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::listBack(1));

            return;
        }
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی',
                callback_data: 'ari2:history:'.$id.':'.$listPage.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور', callback_data: 'ari2:view:'.$id.':'.$listPage));
        try {
            $history = $this->cloud->history($root, $id, $page);
        } catch (Throwable) {
            $this->render($bot, 'دریافت سوابق عملیات ممکن نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی',
                callback_data: 'ari2:history:'.$id.':'.$listPage.':'.($page - 1));
        }
        if ($page < $history['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️',
                callback_data: 'ari2:history:'.$id.':'.$listPage.':'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی',
            callback_data: 'ari2:history:'.$id.':'.$listPage.':'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور',
            callback_data: 'ari2:view:'.$id.':'.$listPage));

        $rows = '';
        $simple = '<b>🕒 سوابق عملیات سرور</b>'."\nصفحه ".self::digits($page).' از '.self::digits($history['pages']);
        foreach ($history['items'] as $action) {
            $label = self::operation($action['command']).' #'.$action['id'];
            $value = self::statusLabel($action['status'], true).' · '.self::date($action['startedAt']);
            $rows .= self::row($label, $value);
            $simple .= "\n".TelegramUi::escape($label).' — '.TelegramUi::escape($value);
        }
        $rich = '<h2>🕒 سوابق عملیات سرور</h2><p>صفحه '.self::digits($page)
            .' از '.self::digits($history['pages']).'</p>'
            .($rows === '' ? '<p>عملیاتی ثبت نشده است.</p>'
                : '<table bordered striped compact>'.$rows.'</table>')
            .'<footer>وضعیت عملیات از API آریاسرویس دریافت شده است؛ عملیات جدیدی اجرا نمی‌شود.</footer>';
        $this->render($bot, $simple.($rows === '' ? "\nعملیاتی ثبت نشده است." : ''), $buttons, $rich);
    }

    public function datacenters(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تلاش مجدد', callback_data: 'ari2:dc'))
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
        try {
            $datacenters = $this->cloud->datacenters($root);
        } catch (Throwable) {
            $this->render($bot, 'دریافت دیتاسنترها ممکن نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        foreach ($datacenters as $dc) {
            $buttons->addRow(InlineKeyboardButton::make('🌍 '.self::short($dc['name'], 35),
                callback_data: 'ari2:plans:'.$dc['code'].':1'));
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:dc'));
        $buttons->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
        $rich = '<h2>📦 پلن‌های سرور ابری</h2><p>دیتاسنتر را انتخاب کنید.</p>'
            .'<footer>پلن‌ها فقط برای مشاهده هستند؛ خریدی انجام نمی‌شود.</footer>';
        $this->render($bot, "📦 پلن‌های سرور ابری\n\n"
            .($datacenters === [] ? 'فعلاً دیتاسنتری قابل انتخاب نیست.' : 'یک دیتاسنتر انتخاب کنید.'), $buttons, $rich);
    }

    public function plans(Nutgram $bot, string $datacenter, string $requestedPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $page = self::number($requestedPage, 100);
        if ($page === null || preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $datacenter) !== 1) {
            $this->render($bot, 'انتخاب معتبر نیست.', InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ دیتاسنترها', callback_data: 'ari2:dc')));

            return;
        }
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تلاش مجدد', callback_data: 'ari2:plans:'.$datacenter.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ دیتاسنترها', callback_data: 'ari2:dc'));
        try {
            $plans = $this->cloud->plans($root, $datacenter, $page);
        } catch (Throwable) {
            $this->render($bot, 'دریافت پلن‌ها ممکن نیست؛ دوباره تلاش کنید.', $buttons);

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'ari2:plans:'.$datacenter.':'.($page - 1));
        }
        if ($page < $plans['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'ari2:plans:'.$datacenter.':'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari2:plans:'.$datacenter.':'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ دیتاسنترها', callback_data: 'ari2:dc'));

        $rows = '';
        $simple = '<b>📦 پلن‌های '.TelegramUi::escape($datacenter).'</b>'
            ."\nصفحه ".self::digits($page).' از '.self::digits($plans['pages']);
        foreach ($plans['items'] as $plan) {
            $name = $plan['name'].' #'.$plan['id'];
            $specs = self::amount($plan['cores']).' CPU · '
                .($plan['memoryMb'] === null ? 'RAM نامشخص' : self::digits($plan['memoryMb']).' MB RAM')
                .' · '.($plan['diskGb'] === null ? 'دیسک نامشخص' : self::digits($plan['diskGb']).' GB');
            $price = 'ساعتی: '.self::money($plan['hourlyRials'])
                .' | ماهانه: '.self::money($plan['monthlyRials']);
            $rows .= self::row($name, $specs.' · '.$price);
            $simple .= "\n".TelegramUi::escape($name).' · '.TelegramUi::escape($specs)
                ."\n".TelegramUi::escape($price);
        }
        $rich = '<h2>📦 پلن‌های '.TelegramUi::escape($datacenter).'</h2><p>صفحه '
            .self::digits($page).' از '.self::digits($plans['pages']).'</p>'
            .($rows === '' ? '<p>پلنی برای نمایش وجود ندارد.</p>'
                : '<table bordered striped compact>'.$rows.'</table>')
            .'<footer>قیمت‌ها تنها در صورت تأیید واحد IRR نمایش داده می‌شوند؛ امکان خرید در این مرحله وجود ندارد.</footer>';
        $this->render($bot, $simple.($rows === '' ? "\nپلنی یافت نشد." : ''), $buttons, $rich);
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function render(
        Nutgram $bot,
        string $fallback,
        InlineKeyboardMarkup $buttons,
        ?string $rich = null,
    ): void {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $buttons);

                return;
            } catch (TelegramException) {
                // Render the same trusted projection with native HTML on unsupported clients.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $fallback, reply_markup: $buttons, parse_mode: 'HTML');
    }

    private static function listBack(int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست سرورها', callback_data: 'ari2:list:'.$page));
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
            .TelegramUi::escape($value).'</b></td></tr>';
    }

    private static function number(string $value, int $max): ?int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || strlen($value) > strlen((string) $max)) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($number) && $number <= $max ? $number : null;
    }

    private static function short(string $value, int $max): string
    {
        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $value));

        return mb_substr($clean, 0, $max);
    }

    private static function digits(int $value): string
    {
        return PersianCalendar::number(number_format($value));
    }

    private static function amount(?int $value): string
    {
        return $value === null ? 'نامشخص' : self::digits($value);
    }

    private static function money(?int $rials): string
    {
        return $rials === null ? 'نامشخص' : self::toman($rials);
    }

    private static function toman(int $rials): string
    {
        return TelegramUi::tomanPersian($rials).' تومان';
    }

    private static function statusIcon(string $status): string
    {
        return match ($status) {
            'running' => '🟢',
            'stopped', 'powered_off', 'poweredoff' => '⏸️',
            'building', 'starting', 'pending' => '🟡',
            'error', 'failed' => '🔴',
            default => '⚪',
        };
    }

    private static function statusLabel(string $status, bool $action): string
    {
        if ($status === 'running') {
            return $action ? '🟡 در حال انجام' : '🟢 روشن';
        }

        return match ($status) {
            'success', 'completed' => '🟢 موفق',
            'stopped', 'powered_off', 'poweredoff' => '⏸️ خاموش',
            'building', 'starting', 'pending', 'running_action', 'in_progress' => '🟡 در حال انجام',
            'error', 'failed' => '🔴 ناموفق',
            default => $status === 'نامشخص' ? 'نامشخص' : '⚪ '.self::short($status, 24),
        };
    }

    private static function yesNo(?bool $value): string
    {
        return $value === null ? 'نامشخص' : ($value ? 'بله' : 'خیر');
    }

    /** @param array<string,mixed> $server */
    private static function changeFee(array $server): string
    {
        if ($server['freeLeft'] !== null && $server['freeLeft'] > 0) {
            return 'رایگان ('.self::digits($server['freeLeft']).' مرتبه باقی‌مانده)';
        }
        if ($server['changeFeeRials'] !== null) {
            return self::toman($server['changeFeeRials']);
        }

        return 'نامشخص';
    }

    private static function date(string $utc): string
    {
        if ($utc === 'نامشخص') {
            return $utc;
        }

        return PersianCalendar::utcDateTime($utc);
    }

    private static function operation(string $command): string
    {
        return match ($command) {
            'create' => 'ایجاد سرور',
            'change_ipv4' => 'تعویض IPv4',
            'reboot', 'reset' => 'راه‌اندازی مجدد',
            'poweron' => 'روشن‌کردن',
            'poweroff', 'shutdown' => 'خاموش‌کردن',
            'rebuild' => 'نصب مجدد',
            default => self::short($command, 30),
        };
    }
}
