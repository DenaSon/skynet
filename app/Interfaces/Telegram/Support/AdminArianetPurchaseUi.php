<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\PreviewAdminArianetPurchase;
use App\Modules\Provisioning\Application\PurchaseAdminArianetCloud;
use App\Modules\Provisioning\Application\ReadAdminArianetCloud;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** ARI-04A: private GET-only purchase quote, never a purchase action. */
final readonly class AdminArianetPurchaseUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private PreviewAdminArianetPurchase $preview,
        private PurchaseAdminArianetCloud $purchase,
        private ReadAdminArianetCloud $catalog,
        private MenuRenderer $menus,
    ) {}

    public function begin(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        Cache::forget(self::key($actor));
        try {
            $datacenters = $this->preview->datacenters($actor);
        } catch (Throwable) {
            $this->render($bot, 'دریافت دیتاسنترهای قابل سفارش ممکن نیست.', self::home());

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        foreach ($datacenters as $dc) {
            $buttons->addRow(InlineKeyboardButton::make('🌍 '.$dc['name'],
                callback_data: 'ari8:plans:'.$dc['code'].':1'));
        }
        $buttons->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
        $message = '☁️ پیش‌نمایش خرید سرور'."\n\n"
            .($datacenters === [] ? 'فعلاً دیتاسنتر قابل سفارش وجود ندارد.' : 'دیتاسنتر را انتخاب کنید.')
            ."\n\n🔒 هیچ خرید یا برداشت اعتباری انجام نمی‌شود.";
        $this->render($bot, TelegramUi::escape($message), $buttons,
            '<h2>☁️ پیش‌نمایش خرید سرور</h2><p>'
                .TelegramUi::escape($datacenters === [] ? 'دیتاسنتر قابل سفارش وجود ندارد.' : 'دیتاسنتر را انتخاب کنید.')
                .'</p><footer>🔒 فقط بررسی؛ بدون خرید</footer>');
    }

    public function plans(Nutgram $bot, string $datacenter, string $rawPage): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $page = self::number($rawPage);
        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ دیتاسنترها', callback_data: 'ari8:begin'));
        if ($page === null || ! self::datacenter($datacenter)) {
            $this->render($bot, 'انتخاب معتبر نیست.', $back);

            return;
        }
        try {
            if (! in_array($datacenter, array_column($this->preview->datacenters($actor), 'code'), true)) {
                throw new \RuntimeException('Datacenter is not orderable.');
            }
            $result = $this->catalog->plans($actor, $datacenter, $page);
        } catch (Throwable) {
            $this->render($bot, 'پلن‌ها در دسترس نیستند یا دیتاسنتر قابل سفارش نیست.', $back);

            return;
        }

        $buttons = InlineKeyboardMarkup::make();
        $rows = '';
        $simple = '<b>📦 انتخاب پلن</b>'."\n".TelegramUi::escape($datacenter);
        foreach ($result['items'] as $plan) {
            $name = $plan['name'].' #'.$plan['id'];
            $ram = $plan['memoryMb'] === null ? 'N/A RAM'
                : ($plan['memoryMb'] % 1024 === 0
                    ? number_format(intdiv($plan['memoryMb'], 1024)).' GB RAM'
                    : number_format($plan['memoryMb']).' MB RAM');
            $specs = ($plan['cores'] === null ? 'N/A' : number_format($plan['cores'])).' vCPU · '
                .$ram.' · '.($plan['diskGb'] === null ? 'N/A' : number_format($plan['diskGb'])).' GB Disk';
            $price = $plan['hourlyRials'] === null ? 'قیمت نامشخص'
                : 'ساعتی '.TelegramUi::tomanPersian($plan['hourlyRials']).' تومان';
            $buttons->addRow(InlineKeyboardButton::make(
                '📦 انتخاب '.$name, callback_data: 'ari8:images:'.$datacenter.':'.$plan['id'],
            ));
            $rows .= self::row($name, $specs)
                .self::row('هزینه پایه', $price);
            $simple .= "\n\n<b>".TelegramUi::escape($name).'</b>'
                ."\n".TelegramUi::escape($specs)
                ."\n".TelegramUi::escape($price);
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'ari8:plans:'.$datacenter.':'.($page - 1));
        }
        if ($page < $result['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'ari8:plans:'.$datacenter.':'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('◀️ دیتاسنترها', callback_data: 'ari8:begin'));
        $rich = '<h2>📦 انتخاب پلن</h2><p>دیتاسنتر: '.TelegramUi::escape($datacenter).'</p>'
            .'<table bordered striped compact>'.$rows.'</table>';
        $this->render($bot, $simple, $buttons, $rich);
    }

    public function images(Nutgram $bot, string $datacenter, string $rawPlan): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $planId = self::number($rawPlan, PHP_INT_MAX);
        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'ari8:plans:'.$datacenter.':1'));
        if ($planId === null || ! self::datacenter($datacenter)) {
            $this->render($bot, 'انتخاب معتبر نیست.', self::home());

            return;
        }
        try {
            $plan = $this->preview->plan($actor, $datacenter, $planId);
            $images = $this->preview->images($actor, $datacenter, $planId);
        } catch (Throwable) {
            $this->render($bot, 'موجودی پلن یا سیستم‌عامل‌های قابل نصب تأیید نشد.', $back);

            return;
        }
        $buttons = InlineKeyboardMarkup::make();
        foreach ($images as $image) {
            $buttons->addRow(InlineKeyboardButton::make('🐧 '.$image['name'],
                callback_data: 'ari8:image:'.$datacenter.':'.$planId.':'.$image['image']));
        }
        $buttons->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'ari8:plans:'.$datacenter.':1'));
        $rich = '<h2>🐧 انتخاب سیستم‌عامل</h2>'
            .'<p>'.TelegramUi::escape($plan['name']).' · '.TelegramUi::escape($datacenter).'</p>'
            .'<footer>'.TelegramUi::escape($images === [] ? 'سیستم‌عامل سازگار موجود نیست.' : 'یک سیستم‌عامل را انتخاب کنید؛ خریدی انجام نمی‌شود.').'</footer>';
        $this->render($bot, '<b>🐧 انتخاب سیستم‌عامل</b>'."\n"
            .TelegramUi::escape($plan['name'])."\n\n"
            .($images === [] ? 'سیستم‌عامل سازگار موجود نیست.' : 'یک مورد را انتخاب کنید.'), $buttons, $rich);
    }

    public function image(Nutgram $bot, string $datacenter, string $rawPlan, string $image): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $planId = self::number($rawPlan, PHP_INT_MAX);
        if ($planId === null || ! self::datacenter($datacenter)) {
            $this->render($bot, 'انتخاب معتبر نیست.', self::home());

            return;
        }
        try {
            if (! in_array($image, array_column($this->preview->images($actor, $datacenter, $planId), 'image'), true)) {
                throw new \RuntimeException('Image unavailable.');
            }
        } catch (Throwable) {
            $this->render($bot, 'سیستم‌عامل یا پلن تغییر کرده است؛ دوباره انتخاب کنید.', self::home());

            return;
        }
        Cache::put(self::key($actor), [
            'stage' => 'name', 'datacenter' => $datacenter, 'planId' => $planId, 'image' => $image,
        ], now()->addMinutes(15));
        $this->render($bot, '<b>📝 نام سرور</b>'."\n\n"
            .'نام انگلیسی سرور را ارسال کنید. نمونه: <code>denagate-nl-01</code>'
            ."\n\nنام فقط برای پیش‌نمایش است.", self::cancelButton());
    }

    public function text(Nutgram $bot, string $text): bool
    {
        $actor = $this->root($bot, deny: false);
        if ($actor === null || str_starts_with(trim($text), '/')) {
            return false;
        }
        $state = Cache::get(self::key($actor));
        if (! is_array($state) || ! in_array($state['stage'] ?? null, ['name', 'key'], true)) {
            return false;
        }
        if ($state['stage'] === 'key') {
            // Do not accept manually typed key IDs, including in an old draft.
            $this->showKeys($bot, $actor, 1);

            return true;
        }
        if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/D', trim($text)) !== 1) {
            $this->render($bot, 'نام معتبر نیست؛ حروف انگلیسی، عدد و خط تیره استفاده کنید.', self::cancelButton());

            return true;
        }
        $state['name'] = trim($text);
        $state['stage'] = 'key';
        Cache::put(self::key($actor), $state, now()->addMinutes(15));
        $this->showKeys($bot, $actor, 1);

        return true;
    }

    public function keys(Nutgram $bot, string $rawPage): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $page = self::number($rawPage);
        if ($page === null) {
            $this->render($bot, 'صفحه معتبر نیست.', self::home());

            return;
        }
        $this->showKeys($bot, $actor, $page);
    }

    public function selectKey(Nutgram $bot, string $rawId, string $rawPage): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $state = Cache::get(self::key($actor));
        $id = self::number($rawId, PHP_INT_MAX);
        $page = self::number($rawPage);
        if (! is_array($state) || ($state['stage'] ?? null) !== 'key'
            || $id === null || $page === null) {
            $this->render($bot, 'این انتخاب منقضی شده است؛ دوباره شروع کنید.', self::home());

            return;
        }
        try {
            $list = $this->preview->sshKeys($actor, $page);
            if (! in_array($id, array_column($list['items'], 'id'), true)) {
                throw new \RuntimeException('SSH key is not listed on this page.');
            }
        } catch (Throwable) {
            $this->render($bot, 'این کلید دیگر قابل انتخاب نیست؛ فهرست را تازه‌سازی کنید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 فهرست کلیدها', callback_data: 'ari8:keys:1'))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'ari8:cancel')));

            return;
        }

        $state['keyId'] = $id;
        $state['stage'] = 'quote';
        Cache::put(self::key($actor), $state, now()->addMinutes(15));
        $this->showQuote($bot, $actor, $state);
    }

    private function showKeys(Nutgram $bot, int $actor, int $page): void
    {
        $state = Cache::get(self::key($actor));
        if (! is_array($state) || ($state['stage'] ?? null) !== 'key') {
            $this->render($bot, 'این مرحله منقضی شده است؛ دوباره شروع کنید.', self::home());

            return;
        }
        $retry = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تلاش مجدد', callback_data: 'ari8:keys:'.$page))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'ari8:cancel'));
        try {
            $list = $this->preview->sshKeys($actor, $page);
        } catch (Throwable) {
            $this->render($bot, '⚠️ دریافت کلیدهای SSH ممکن نیست؛ مجوز ssh-keys:read توکن آریاسرویس را بررسی کنید.'
                ."\nهیچ خریدی انجام نشده است.", $retry);

            return;
        }

        $buttons = InlineKeyboardMarkup::make();
        $rows = '';
        foreach ($list['items'] as $key) {
            $buttons->addRow(InlineKeyboardButton::make('🔑 '.$key['name'].' #'.$key['id'],
                callback_data: 'ari8:key:'.$key['id'].':'.$page));
            $rows .= self::row('کلید عمومی', $key['name'].' #'.$key['id']);
        }
        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'ari8:keys:'.($page - 1));
        }
        if ($page < $list['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'ari8:keys:'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'ari8:keys:'.$page));
        $buttons->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'ari8:cancel'));

        $message = $list['items'] === []
            ? 'کلید SSH ثبت‌شده‌ای در این صفحه پیدا نشد؛ از پنل آریاسرویس یک کلید عمومی ثبت کنید.'
            : 'کلید SSH موجود را از دکمه‌های زیر انتخاب کنید.';
        $notice = 'فقط نام و شناسه کلید عمومی نمایش داده می‌شود؛ کلید خصوصی یا رمز عبور ارسال نکنید. هیچ خریدی انجام نمی‌شود.';
        $this->render($bot, '<b>🔑 انتخاب کلید SSH</b>'."\n\n".$message."\n\n".$notice, $buttons,
            '<h2>🔑 انتخاب کلید SSH</h2><p>'.TelegramUi::escape($message).'</p>'
            .($rows === '' ? '' : '<table bordered striped compact>'.$rows.'</table>')
            .'<footer>'.TelegramUi::escape($notice).'</footer>');
    }

    public function review(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $state = Cache::get(self::key($actor));
        if (! is_array($state) || ($state['stage'] ?? null) !== 'quote') {
            $this->render($bot, 'پیش‌نمایش منقضی شده است؛ از ابتدا شروع کنید.', self::home());

            return;
        }
        $this->showQuote($bot, $actor, $state);
    }

    /** Prepare one immutable, actor-scoped financial confirmation. No POST. */
    public function preparePurchase(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $draft = Cache::get(self::key($actor));
        if (! is_array($draft) || ($draft['stage'] ?? null) !== 'quote') {
            $this->render($bot, 'پیش‌نمایش منقضی شده است؛ دوباره شروع کنید.', self::home());

            return;
        }
        try {
            $prepared = $this->purchase->prepare($actor, $draft);
        } catch (Throwable) {
            $this->render($bot, 'آماده‌سازی خرید ممکن نیست؛ فعال‌بودن مجوز خرید، موجودی پلن و درخواست‌های قبلی را بررسی کنید. هیچ خرید جدیدی انجام نشد.',
                self::purchaseBack());

            return;
        }
        $id = $prepared['id'];
        $q = $prepared['quote'];
        $p = $q['plan'];
        $fields = [
            ['نام سرور', $q['name']],
            ['دیتاسنتر', $p['datacenter']],
            ['پلن', $p['name'].' #'.$p['id']],
            ['سیستم‌عامل', $q['image']],
            ['کلید SSH', $q['sshKeyName'].' #'.$q['sshKeyId']],
            ['هزینه پایه هر ساعت', TelegramUi::tomanPersian($p['hourlyRials']).' تومان'],
            ['برآورد ماهانه پایه', TelegramUi::tomanPersian($p['monthlyRials']).' تومان'],
            ['حداقل هزینه پایه', TelegramUi::tomanPersian($q['minimumRials']).' تومان'],
        ];
        $rows = '';
        $fallback = '<b>🔴 تأیید خرید واقعی یک VPS</b>';
        foreach ($fields as [$label, $value]) {
            $rows .= self::row($label, $value);
            $fallback .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $warning = 'تأیید، خرید واقعی و هزینه ساعتی حتی در حالت خاموش را آغاز می‌کند. '
            .'قیمت پایه است؛ IPv4، مالیات و هزینه‌های جانبی ممکن است اضافه شوند و موجودی ۲۴ ساعت لازم باشد. '
            .'تأیید یک‌بارمصرف است؛ نصب نود جداگانه انجام می‌شود.';
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔴 تأیید خرید و هزینه ساعتی',
                callback_data: 'ari8:purchase:apply:'.$id.':'.$prepared['token']))
            ->addRow(InlineKeyboardButton::make('❌ لغو درخواست', callback_data: 'ari8:purchase:cancel:'.$id.':'.$prepared['token']))
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش', callback_data: 'ari8:review'));
        $this->render($bot, $fallback."\n\n".TelegramUi::escape($warning), $buttons,
            '<h2>🔴 تأیید خرید واقعی یک VPS</h2><table bordered striped compact>'.$rows.'</table>'
            .'<footer>'.TelegramUi::escape($warning).'</footer>');
    }

    public function applyPurchase(Nutgram $bot, string $rawId, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        if ($id === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::purchaseBack());

            return;
        }
        try {
            $result = $this->purchase->confirm($actor, $id, $token);
        } catch (Throwable) {
            $this->render($bot, 'تأیید خرید انجام نشد یا قبلاً مصرف شده است. فقط وضعیت همان سفارش را بررسی کنید؛ درخواست جدید نسازید.',
                self::purchaseStatusButtons($id));

            return;
        }

        $this->purchaseStatusMessage($bot, $result);
    }

    public function cancelPurchase(Nutgram $bot, string $rawId, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        if ($id === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::purchaseBack());

            return;
        }
        try {
            $this->purchase->cancel($actor, $id, $token);
            $this->render($bot, 'درخواست خرید لغو شد؛ هیچ سفارش جدیدی ارسال نشده است.', self::purchaseBack());
        } catch (Throwable) {
            $this->render($bot, 'لغو این درخواست ممکن نیست؛ ممکن است قبلاً تأیید شده باشد. وضعیت را بررسی کنید.',
                self::purchaseStatusButtons($id));
        }
    }

    public function recentPurchase(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $id = $this->purchase->recent($actor);
        } catch (Throwable) {
            $id = null;
        }
        if ($id === null) {
            $this->render($bot, 'سفارش قبلی برای نمایش پیدا نشد.', self::purchaseBack());

            return;
        }
        $this->statusPurchase($bot, (string) $id);
    }

    public function statusPurchase(Nutgram $bot, string $rawId): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        if ($id === null) {
            $this->render($bot, 'شماره سفارش معتبر نیست.', self::purchaseBack());

            return;
        }
        try {
            $result = $this->purchase->status($actor, $id);
        } catch (Throwable) {
            $this->render($bot, 'دریافت وضعیت سفارش ممکن نیست.', self::purchaseBack());

            return;
        }
        $this->purchaseStatusMessage($bot, $result);
    }

    public function reconcilePurchase(Nutgram $bot, string $rawId): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $id = self::number($rawId, PHP_INT_MAX);
        if ($id === null) {
            $this->render($bot, 'شماره سفارش معتبر نیست.', self::purchaseBack());

            return;
        }
        try {
            $result = $this->purchase->reconcile($actor, $id);
        } catch (Throwable) {
            $this->render($bot, 'بررسی نتیجه در آریاسرویس ممکن نیست؛ درخواست خرید را تکرار نکنید.',
                self::purchaseStatusButtons($id));

            return;
        }
        $this->purchaseStatusMessage($bot, $result);
    }

    /** @param array<string,mixed> $result */
    private function purchaseStatusMessage(Nutgram $bot, array $result): void
    {
        $state = match ($result['status']) {
            'prepared' => 'منتظر تأیید خرید',
            'accepted' => 'در حال ساخت',
            'completed' => 'ساخت تأیید شد؛ اتصال SSH بررسی نشده',
            'reserved', 'uncertain' => 'نتیجه خرید نامشخص',
            'needs_review' => 'نیازمند بررسی',
            'cancelled' => 'لغوشده',
            'expired' => 'منقضی‌شده',
            default => 'وضعیت نامشخص',
        };
        $fields = [
            ['شماره درخواست', (string) $result['id']],
            ['نام سرور', $result['name']],
            ['وضعیت', $state],
            ['هزینه پایه ساعتی', TelegramUi::tomanPersian($result['hourlyRials']).' تومان'],
            ['شناسه سرور ابری', $result['serverId'] === null ? 'هنوز تأیید نشده' : (string) $result['serverId']],
            ['شناسه عملیات', $result['actionId'] === null ? 'هنوز تأیید نشده' : (string) $result['actionId']],
        ];
        $rows = '';
        $fallback = '<b>☁️ وضعیت درخواست VPS</b>';
        foreach ($fields as [$label, $value]) {
            $rows .= self::row($label, $value);
            $fallback .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $warning = in_array($result['status'], ['reserved', 'uncertain', 'needs_review'], true)
            ? '⚠️ وضعیت قطعی نیست؛ سفارش جدید ثبت نکنید.'
            : '';
        $this->render($bot, $fallback.($warning === '' ? '' : "\n\n".TelegramUi::escape($warning)),
            self::purchaseStatusButtons($result['id']),
            '<h2>☁️ وضعیت درخواست VPS</h2><table bordered striped compact>'.$rows.'</table>'
            .($warning === '' ? '' : '<footer>'.TelegramUi::escape($warning).'</footer>'));
    }

    private static function purchaseStatusButtons(int $id): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی وضعیت در آریاسرویس', callback_data: 'ari8:purchase:reconcile:'.$id))
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    private static function purchaseBack(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📋 آخرین درخواست', callback_data: 'ari8:purchase:recent'))
            ->addRow(InlineKeyboardButton::make('◀️ پیش‌نمایش خرید', callback_data: 'ari8:review'))
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    public function cancel(Nutgram $bot): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        Cache::forget(self::key($actor));
        $this->render($bot, 'پیش‌نمایش لغو شد. هیچ خریدی انجام نشده است.', self::home());
    }

    /** @param array<string,mixed> $state */
    private function showQuote(Nutgram $bot, int $actor, array $state): void
    {
        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی دوباره', callback_data: 'ari8:review'))
            ->addRow(InlineKeyboardButton::make('✏️ انتخاب مجدد', callback_data: 'ari8:begin'))
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
        try {
            $q = $this->preview->quote($actor, $state['datacenter'], $state['planId'],
                $state['image'], $state['name'], $state['keyId']);
        } catch (Throwable) {
            $this->render($bot, '⚠️ قیمت، موجودی، سیستم‌عامل یا کلید SSH قابل تأیید نیست.'
                ."\nمجوز خواندن کلید SSH حساب را هم بررسی کنید؛ هیچ خریدی انجام نشده است.", $back);

            return;
        }
        $purchaseEnabled = $this->purchase->enabled();
        if ($purchaseEnabled) {
            $back->addRow(InlineKeyboardButton::make('🛒 ادامه خرید', callback_data: 'ari8:purchase:prepare'));
        }
        $back->addRow(InlineKeyboardButton::make('📋 آخرین سفارش', callback_data: 'ari8:purchase:recent'));
        $plan = $q['plan'];
        $data = [
            ['نام سرور', $q['name']],
            ['دیتاسنتر', $plan['datacenter']],
            ['پلن', $plan['name'].' #'.$plan['id']],
            ['مشخصات', $plan['cores'].' vCPU · '
                .($plan['memoryMb'] % 1024 === 0
                    ? intdiv($plan['memoryMb'], 1024).' GB RAM'
                    : $plan['memoryMb'].' MB RAM')
                .' · '.$plan['diskGb'].' GB Disk'],
            ['ترافیک', $plan['trafficGb'] === null ? 'نامشخص یا بدون محدودیت'
                : number_format($plan['trafficGb']).' GB Traffic'],
            ['سیستم‌عامل', $q['image']],
            ['کلید عمومی SSH', $q['sshKeyName'].' #'.$q['sshKeyId']],
            ['نرخ ساعتی پلن', TelegramUi::tomanPersian($plan['hourlyRials']).' تومان'],
            ['برآورد ماهانه پلن', TelegramUi::tomanPersian($plan['monthlyRials']).' تومان'],
            ['حداقل هزینه اولیه پلن', TelegramUi::tomanPersian($q['minimumRials']).' تومان'],
        ];
        $rows = '';
        $fallback = '<b>☁️ پیش‌نمایش خرید یک سرور</b>';
        foreach ($data as [$label, $value]) {
            $rows .= self::row($label, $value);
            $fallback .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $notice = 'مبلغ‌ها فقط نرخ پایه پلن هستند؛ IPv4، مالیات و هزینه‌های جانبی ممکن است اضافه شوند. '
            .($purchaseEnabled ? 'خرید فقط پس از تأیید نهایی انجام می‌شود.' : 'خرید فعلاً غیرفعال است.');
        $this->render($bot, $fallback."\n\n".TelegramUi::escape($notice), $back,
            '<h2>☁️ پیش‌نمایش خرید یک سرور</h2><table bordered striped compact>'.$rows.'</table>'
            .'<footer>🔒 '.TelegramUi::escape($notice).'</footer>');
    }

    private function root(Nutgram $bot, bool $deny = true): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            if ($deny) {
                $this->menus->denyAccess($bot);
            }

            return null;
        }

        return $actor->userId;
    }

    private static function key(int $actor): string
    {
        return 'ari8:quote:'.$actor;
    }

    private static function datacenter(string $code): bool
    {
        return preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $code) === 1;
    }

    private static function number(string $raw, int $max = 100): ?int
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $raw) === 1
            && filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]) !== false
            ? (int) $raw : null;
    }

    private static function home(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('☁️ شروع دوباره', callback_data: 'ari8:begin'))
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    private static function cancelButton(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'ari8:cancel'));
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td>'.TelegramUi::escape($value).'</td></tr>';
    }

    private function render(
        Nutgram $bot,
        string $text,
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
                // Keep the normal safe HTML fallback.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $buttons, parse_mode: 'HTML');
    }
}
