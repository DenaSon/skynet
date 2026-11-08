<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Filtering\Application\ReadFilteringCenter;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** FLT-04: trusted-root, private-chat, RTL Rich-first filtering center. */
final readonly class AdminFilteringUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadFilteringCenter $data,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, bool $simple = false): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        try {
            $data = $this->data->overview($userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }

        $counts = $data['counts'];
        $rows = [
            ['🟢 سالم', self::number($counts['healthy'])],
            ['🟠 نیازمند بررسی', self::number($counts['suspicious'])],
            ['🔴 اختلال ثبت‌شده', self::number($counts['disrupted'])],
            ['⚪ نامشخص', self::number($counts['unknown'])],
        ];

        $node = ($data['node_enabled'] ? 'فعال' : 'غیرفعال').' · '.self::time($data['node_at']);
        $regional = ($data['regional_enabled'] ? 'فعال' : 'غیرفعال').' · '.self::time($data['regional_at']);
        $desc = $data['total'] === 0
            ? 'هنوز داده‌ای برای نمایش ثبت نشده است.'
            : 'آخرین وضعیت ثبت‌شده برای '.self::number($data['total']).' نود دارای سابقه';
        $rich = '<h2>🚦 فیلترینگ</h2>'
            .'<p>'.self::esc($desc).'</p>'
            .'<h3>📊 نمای کلی</h3>'.self::table($rows)
            .'<h3>🕒 وضعیت بررسی‌ها</h3>'.self::table([
                ['کاربران و ترافیک · هر ۲ دقیقه', $node],
                ['دسترسی از ایران · هر ۱۰ دقیقه', $regional],
            ])
            .'<footer>تازه‌سازی فقط داده‌های ذخیره‌شده را نمایش می‌دهد. نتیجه قدیمی یا ناموجود، سالم محسوب نمی‌شود.</footer>';
        $text = '<b>🚦 فیلترینگ</b>'
            ."\n".self::esc($desc)
            ."\n\n<b>📊 نمای کلی</b>".self::simpleRows($rows)
            ."\n\n<b>🕒 وضعیت بررسی‌ها</b>"
            ."\nکاربران و ترافیک: ".self::esc($node)
            ."\nدسترسی از ایران: ".self::esc($regional)
            ."\n\n<blockquote>تازه‌سازی فقط داده‌های ذخیره‌شده را نشان می‌دهد؛ وضعیت قدیمی یا ناموجود، سالم نیست.</blockquote>";

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🖥️ وضعیت نودها', callback_data: 'flt:nodes:1'),
                InlineKeyboardButton::make('🌐 بررسی دسترسی', callback_data: 'flt:access:1'),
            )
            ->addRow(InlineKeyboardButton::make('🚨 هشدارها و تاریخچه', callback_data: 'flt:history:1'))
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'flt:home'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'))
            ->addRow(self::mode($simple, 'flt:home', 'flt:home:simple'));
        $this->render($bot, $rich, $text, $keyboard, $simple);
    }

    public function nodes(Nutgram $bot, string $page = '1', bool $simple = false): void
    {
        $this->list($bot, $page, 'nodes', $simple);
    }

    public function access(Nutgram $bot, string $page = '1', bool $simple = false): void
    {
        $this->list($bot, $page, 'access', $simple);
    }

    public function node(Nutgram $bot, string $uuid, bool $simple = false): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        try {
            $node = $this->data->node($userId, $uuid);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }
        if ($node === null) {
            $this->unavailable($bot, 'این نود در سابقه بررسی‌های موجود پیدا نشد.');

            return;
        }

        $info = [
            ['کاربران آنلاین', self::value($node['online'])],
            ['دریافت در ثانیه', self::rate($node['rx'])],
            ['ارسال در ثانیه', self::rate($node['tx'])],
            ['آخرین مشاهده نود', self::time($node['node_at'])],
            ['بررسی دسترسی ایران', self::time($node['regional_at'])],
            ['وضعیت دسترسی', self::status($node['regional_outcome'])],
        ];
        $regional = $node['regional'];
        $region = self::regionRows($regional);
        $cause = self::causes($node['causes']);
        $rich = '<h2>🖥️ نود '.self::esc($node['label']).'</h2>'
            .'<p><b>'.self::esc(self::status($node['state'])).'</b></p>'
            .self::table($info)
            .'<h3>🌐 بررسی دسترسی</h3>'.self::table($region)
            .'<p>نشانه‌ها: '.self::esc($cause).'</p>'
            .'<footer>نتایج بر اساس آخرین مشاهده ذخیره‌شده‌اند؛ بررسی مجدد ممکن است چند دقیقه طول بکشد.</footer>';
        $text = '<b>🖥️ نود '.self::esc($node['label']).'</b>'
            ."\n".self::esc(self::status($node['state']))
            .self::simpleRows($info)
            ."\n\n<b>🌐 بررسی دسترسی</b>".self::simpleRows($region)
            ."\nنشانه‌ها: ".self::esc($cause)
            ."\n\n<blockquote>آمار ذخیره‌شده است و تازه‌سازی به‌معنای اجرای آزمون جدید نیست.</blockquote>";
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'flt:node:'.$node['uuid']))
            ->addRow(InlineKeyboardButton::make('🔎 بررسی مجدد', callback_data: 'flt:recheck:'.$node['uuid']))
            ->addRow(InlineKeyboardButton::make('◀️ وضعیت نودها', callback_data: 'flt:nodes:1'))
            ->addRow(InlineKeyboardButton::make('🏠 فیلترینگ', callback_data: 'flt:home'))
            ->addRow(self::mode($simple, 'flt:node:'.$node['uuid'], 'flt:node:simple:'.$node['uuid']));
        $this->render($bot, $rich, $text, $keyboard, $simple);
    }

    public function history(Nutgram $bot, string $page = '1', bool $simple = false): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        $number = self::pageNumber($page);
        if ($number === null) {
            $this->unavailable($bot, 'صفحه موردنظر معتبر نیست.');

            return;
        }
        try {
            $data = $this->data->history($userId, $number);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }

        $rows = [];
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($data['rows'] as $incident) {
            $label = $incident['label'];
            $rows[] = [
                $label,
                self::status($incident['state']).' · '.self::time($incident['opened_at']),
            ];
            $keyboard->addRow(InlineKeyboardButton::make(
                self::symbol($incident['state']).' نود '.$label,
                callback_data: 'flt:incident:'.$incident['id'],
            ));
        }
        $empty = $rows === [] ? 'در ۲۴ ساعت گذشته هشداری ثبت نشده است.' : '';
        $rich = '<h2>🚨 هشدارها و تاریخچه</h2>'
            .($empty !== '' ? '<p>'.self::esc($empty).'</p>' : self::table($rows))
            .'<footer>صفحه '.self::number($data['page']).' از '.self::number($data['pages'])
            .' · رخدادهای باز و رخدادهای رفع‌شده در ۲۴ ساعت اخیر</footer>';
        $text = '<b>🚨 هشدارها و تاریخچه</b>'
            .($empty !== '' ? "\n".self::esc($empty) : self::simpleRows($rows))
            ."\n\nصفحه ".self::number($data['page']).' از '.self::number($data['pages']);

        self::pagination($keyboard, 'flt:history:', $data);
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'flt:history:'.$data['page']))
            ->addRow(InlineKeyboardButton::make('◀️ فیلترینگ', callback_data: 'flt:home'))
            ->addRow(self::mode($simple, 'flt:history:'.$data['page'], 'flt:history:simple:'.$data['page']));
        $this->render($bot, $rich, $text, $keyboard, $simple);
    }

    public function incident(Nutgram $bot, string $id, bool $simple = false): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        if (! ctype_digit($id) || strlen($id) > 10 || (int) $id < 1) {
            $this->unavailable($bot, 'این هشدار در تاریخچه موجود نیست.');

            return;
        }
        try {
            $incident = $this->data->incident($userId, (int) $id);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }
        if ($incident === null) {
            $this->unavailable($bot, 'این هشدار در تاریخچه موجود نیست.');

            return;
        }

        $rows = [
            ['نود', $incident['label']],
            ['وضعیت', self::status($incident['state'])],
            ['شروع', self::time($incident['opened_at'])],
            ['آخرین مشاهده', self::time($incident['last_at'])],
            ['رفع اختلال', self::time($incident['resolved_at'])],
            ['نشانه‌ها', self::causes($incident['causes'])],
        ];
        $rich = '<h2>🚨 جزئیات هشدار</h2>'.self::table($rows)
            .'<footer>وجود هشدار، اثبات قطعی فیلترینگ نیست.</footer>';
        $text = '<b>🚨 جزئیات هشدار</b>'.self::simpleRows($rows)
            ."\n\n<blockquote>وجود هشدار، اثبات قطعی فیلترینگ نیست.</blockquote>";
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ تاریخچه', callback_data: 'flt:history:1'))
            ->addRow(InlineKeyboardButton::make('🏠 فیلترینگ', callback_data: 'flt:home'))
            ->addRow(self::mode($simple, 'flt:incident:'.$id, 'flt:incident:simple:'.$id));
        $this->render($bot, $rich, $text, $keyboard, $simple);
    }

    /** Manual check is a queued, cooldown-limited action: never a synchronous provider call. */
    public function recheck(Nutgram $bot, string $uuid): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        try {
            $status = $this->data->recheck($userId, $uuid);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }
        $message = match ($status) {
            'queued' => '✅ درخواست بررسی ثبت شد. نتیجه پس از اجرا در بخش دسترسی نمایش داده می‌شود.',
            'disabled' => '⏸️ بررسی دوره‌ای دسترسی غیرفعال است. امکان درخواست جدید وجود ندارد.',
            'cooldown' => '⏳ بررسی دیگری اخیراً درخواست شده است. کمی بعد دوباره اقدام کنید.',
            default => 'این نود برای بررسی مجدد در دسترس نیست.',
        };
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 مشاهده آخرین وضعیت', callback_data: 'flt:node:'.$uuid))
            ->addRow(InlineKeyboardButton::make('◀️ فیلترینگ', callback_data: 'flt:home'));
        $this->render($bot, '<h2>🔎 بررسی مجدد</h2><p>'.self::esc($message).'</p>', self::esc($message), $keyboard, false);
    }

    private function list(Nutgram $bot, string $requested, string $view, bool $simple): void
    {
        $userId = $this->root($bot);
        if ($userId === null) {
            return;
        }
        $page = self::pageNumber($requested);
        if ($page === null) {
            $this->unavailable($bot, 'صفحه موردنظر معتبر نیست.');

            return;
        }
        try {
            $data = $this->data->nodes($userId, $page);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (Throwable) {
            $this->failure($bot);

            return;
        }
        $access = $view === 'access';
        $title = $access ? '🌐 بررسی دسترسی ایران' : '🖥️ وضعیت نودها';
        $rows = [];
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($data['rows'] as $node) {
            if ($access) {
                $counts = $node['regional'];
                $summary = self::status($node['regional_outcome'])
                    .' · '.self::value($counts['completed_probes'] ?? null).' کامل'
                    .' · '.self::value($counts['failed_probes'] ?? null).' ناموفق'
                    .' · '.self::time($node['regional_at']);
            } else {
                $summary = self::status($node['state'])
                    .' · '.self::value($node['online']).' آنلاین'
                    .' · '.self::time($node['node_at']);
            }
            $rows[] = [$node['label'], $summary];
            $keyboard->addRow(InlineKeyboardButton::make(
                self::symbol($access ? $node['regional_outcome'] : $node['state']).' نود '.$node['label'],
                callback_data: 'flt:node:'.$node['uuid'],
            ));
        }
        $message = $rows === [] ? 'هنوز داده‌ای برای نمایش وجود ندارد.' : '';
        $rich = '<h2>'.$title.'</h2>'
            .($message === '' ? self::table($rows) : '<p>'.self::esc($message).'</p>')
            .'<footer>صفحه '.self::number($data['page']).' از '.self::number($data['pages'])
            .' · نتایج فقط از سابقه ذخیره‌شده خوانده می‌شوند.</footer>';
        $text = '<b>'.$title.'</b>'
            .($message === '' ? self::simpleRows($rows) : "\n".self::esc($message))
            ."\n\nصفحه ".self::number($data['page']).' از '.self::number($data['pages']);
        self::pagination($keyboard, 'flt:'.$view.':', $data);
        $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'flt:'.$view.':'.$data['page']))
            ->addRow(InlineKeyboardButton::make('◀️ فیلترینگ', callback_data: 'flt:home'))
            ->addRow(self::mode($simple, 'flt:'.$view.':'.$data['page'], 'flt:'.$view.':simple:'.$data['page']));
        $this->render($bot, $rich, $text, $keyboard, $simple);
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
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        return $actor->userId;
    }

    private function failure(Nutgram $bot): void
    {
        $this->unavailable($bot, 'دریافت داده‌های فیلترینگ فعلاً ممکن نیست.');
    }

    private function unavailable(Nutgram $bot, string $message): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فیلترینگ', callback_data: 'flt:home'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        TelegramUi::editText($bot, self::esc($message), $keyboard, 'HTML');
    }

    private function render(
        Nutgram $bot,
        string $rich,
        string $plain,
        InlineKeyboardMarkup $keyboard,
        bool $simple,
    ): void {
        if (! $simple) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException $exception) {
                report($exception);
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $plain, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $plain, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param list<array{string,string}> $rows */
    private static function table(array $rows): string
    {
        $html = '';
        foreach ($rows as [$label, $value]) {
            $html .= '<tr><td>'.self::esc($label).'</td><td><b>'.self::esc($value).'</b></td></tr>';
        }

        return '<table bordered striped compact>'.$html.'</table>';
    }

    /** @param list<array{string,string}> $rows */
    private static function simpleRows(array $rows): string
    {
        $text = '';
        foreach ($rows as [$label, $value]) {
            $text .= "\n".self::esc($label).': <b>'.self::esc($value).'</b>';
        }

        return $text;
    }

    /** @param array<string,mixed> $data
     * @return list<array{string,string}>
     */
    private static function regionRows(array $data): array
    {
        return [
            ['تکمیل‌شده', self::value($data['completed_probes'] ?? null)],
            ['موفق', isset($data['completed_probes'], $data['failed_probes'])
                ? self::value($data['completed_probes'] - $data['failed_probes']) : 'نامشخص'],
            ['ناموفق', self::value($data['failed_probes'] ?? null)],
            ['در انتظار', self::value($data['pending_probes'] ?? null)],
            ['شبکه‌های مستقل', self::value($data['asn_count'] ?? null)],
            ['شهرها', self::value($data['city_count'] ?? null)],
        ];
    }

    private static function mode(bool $simple, string $rich, string $plain): InlineKeyboardButton
    {
        return InlineKeyboardButton::make($simple ? '✨ نمای جدید' : '📝 نمای ساده',
            callback_data: $simple ? $rich : $plain);
    }

    /** @param array{page:int,pages:int} $data */
    private static function pagination(InlineKeyboardMarkup $keyboard, string $prefix, array $data): void
    {
        $buttons = [];
        if ($data['page'] > 1) {
            $buttons[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: $prefix.($data['page'] - 1));
        }
        if ($data['page'] < $data['pages']) {
            $buttons[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: $prefix.($data['page'] + 1));
        }
        if ($buttons !== []) {
            $keyboard->addRow(...$buttons);
        }
    }

    private static function pageNumber(string $page): ?int
    {
        return ctype_digit($page) && strlen($page) <= 2 && (int) $page >= 1 && (int) $page <= 20
            ? (int) $page : null;
    }

    private static function rate(mixed $value): string
    {
        return is_int($value) && $value >= 0 ? self::number($value).' بایت/ثانیه' : 'نامشخص';
    }

    private static function value(mixed $value): string
    {
        return is_int($value) && $value >= 0 ? self::number($value) : 'نامشخص';
    }

    private static function number(int $number): string
    {
        return PersianCalendar::digits(number_format($number));
    }

    private static function time(?string $at): string
    {
        return $at === null ? 'ثبت نشده' : PersianCalendar::utcDateTime($at);
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'healthy', 'clear' => 'سالم',
            'suspicious', 'possible' => 'نیازمند بررسی',
            'disrupted', 'likely' => 'اختلال ثبت‌شده',
            'resolved' => 'رفع‌شده',
            default => 'نامشخص',
        };
    }

    private static function symbol(string $status): string
    {
        return match ($status) {
            'healthy', 'clear', 'resolved' => '🟢',
            'suspicious', 'possible' => '🟠',
            'disrupted', 'likely' => '🔴',
            default => '⚪',
        };
    }

    /** @param list<string> $causes */
    private static function causes(array $causes): string
    {
        $map = [
            'online_zero' => 'کاربران آنلاین صفر',
            'traffic_zero' => 'ترافیک صفر',
            'regional_possible' => 'دسترسی نامطمئن از ایران',
            'regional_likely' => 'اختلال گسترده در دسترسی',
        ];
        $items = [];
        foreach ($causes as $cause) {
            if (isset($map[$cause])) {
                $items[] = $map[$cause];
            }
        }

        return $items === [] ? 'نشانه‌ای ثبت نشده' : implode('، ', $items);
    }

    private static function esc(string $text): string
    {
        return TelegramUi::escape($text);
    }
}
