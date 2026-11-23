<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\PreviewAdminArianetPurchase;
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
        foreach ($result['items'] as $plan) {
            $price = $plan['hourlyRials'] === null
                ? 'قیمت نامشخص' : TelegramUi::tomanPersian($plan['hourlyRials']).' تومان/ساعت';
            $buttons->addRow(InlineKeyboardButton::make(
                '📦 '.$plan['name'].' · '.$price,
                callback_data: 'ari8:images:'.$datacenter.':'.$plan['id'],
            ));
            $rows .= self::row($plan['name'].' #'.$plan['id'],
                ($plan['cores'] ?? '؟').' CPU · '.($plan['memoryMb'] ?? '؟').' MB RAM · '
                .($plan['diskGb'] ?? '؟').' GB · '.$price);
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
            .'<table bordered striped compact>'.$rows.'</table>'
            .'<footer>قیمت و موجودی پیش از پیش‌نمایش نهایی دوباره بررسی می‌شوند. خرید غیرفعال است.</footer>';
        $this->render($bot, '<b>📦 انتخاب پلن</b>'."\n".TelegramUi::escape($datacenter)
            ."\n\nپلن را از دکمه‌های زیر انتخاب کنید. هیچ خریدی انجام نمی‌شود.", $buttons, $rich);
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
        if ($state['stage'] === 'name') {
            if (preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,61}[a-zA-Z0-9])?$/D', trim($text)) !== 1) {
                $this->render($bot, 'نام معتبر نیست؛ حروف انگلیسی، عدد و خط تیره استفاده کنید.', self::cancelButton());

                return true;
            }
            $state['name'] = trim($text);
            $state['stage'] = 'key';
            Cache::put(self::key($actor), $state, now()->addMinutes(15));
            $this->render($bot, '<b>🔑 کلید SSH موجود</b>'."\n\n"
                .'شناسه عددی یک کلید عمومی که قبلاً در حساب آریاسرویس ثبت کرده‌اید را ارسال کنید.'
                ."\n\nکلید خصوصی یا رمز عبور نفرستید. این مرحله فقط خواندنی است.", self::cancelButton());

            return true;
        }
        $keyId = self::number(trim($text), PHP_INT_MAX);
        if ($keyId === null) {
            $this->render($bot, 'شناسه کلید SSH باید یک عدد مثبت باشد.', self::cancelButton());

            return true;
        }
        $state['keyId'] = $keyId;
        $state['stage'] = 'quote';
        Cache::put(self::key($actor), $state, now()->addMinutes(15));
        $this->showQuote($bot, $actor, $state);

        return true;
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
        $plan = $q['plan'];
        $data = [
            ['نام سرور', $q['name']],
            ['دیتاسنتر', $plan['datacenter']],
            ['پلن', $plan['name'].' #'.$plan['id']],
            ['پردازنده / RAM / دیسک', $plan['cores'].' CPU · '.$plan['memoryMb'].' MB · '.$plan['diskGb'].' GB'],
            ['ترافیک پلن', $plan['trafficGb'] === null ? 'نامشخص یا بدون محدودیت' : $plan['trafficGb'].' GB'],
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
        $notice = 'این فقط نرخ پایه پلن است؛ هزینه IPv4، مالیات یا موارد جانبی در صورت شمول باید هنگام خرید واقعی بررسی شوند. '
            .'استقرار و اتصال خودکار نود هنوز فعال نیست. هیچ سفارش یا هزینه‌ای ایجاد نشده است.';
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
