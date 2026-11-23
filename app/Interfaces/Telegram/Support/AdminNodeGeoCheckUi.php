<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\NodeGeoCheck;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** An independent, on-demand path inspector; never a filtering verdict. */
final readonly class AdminNodeGeoCheckUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private NodeGeoCheck $geoCheck,
        private MenuRenderer $menus,
    ) {}

    public function listing(Nutgram $bot, string $requested = '1'): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $nodes = $this->geoCheck->nodes($actor);
            $page = self::page($requested);
            $pages = max(1, (int) ceil(count($nodes) / 8));
            if ($page === null || $page > $pages) {
                $this->unavailable($bot, 'صفحه معتبر نیست.');

                return;
            }

            $rows = [];
            $keyboard = InlineKeyboardMarkup::make();
            foreach (array_slice($nodes, ($page - 1) * 8, 8) as $node) {
                $state = $node['disabled'] ? 'غیرفعال'
                    : ($node['connected'] ? 'متصل' : 'قطع');
                $rows[] = [$node['name'], $state];
                $keyboard->addRow(InlineKeyboardButton::make(
                    ($node['disabled'] ? '⏸️ ' : ($node['connected'] ? '🟢 ' : '🔴 ')).self::short($node['name']),
                    callback_data: 'geoc:node:'.$node['uuid'],
                ));
            }
            $this->pages($keyboard, 'geoc:list:', $page, $pages);
            $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی فهرست', callback_data: 'geoc:list:'.$page))
                ->addRow(InlineKeyboardButton::make('◀️ کنترل پنل', callback_data: 'menu:admin:control'));

            $this->show($bot, '🌐 مسیرسنج | نودها', $rows,
                'برای بررسی مسیر خروجی سرور، یک نود انتخاب کنید. هیچ آزمایشی خودکار شروع نمی‌شود.', $keyboard,
                'صفحه '.self::value($page).' از '.self::value($pages));
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'فهرست نودها فعلاً در دسترس نیست.');
        }
    }

    public function node(Nutgram $bot, string $uuid): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $node = $this->geoCheck->node($actor, $uuid);
            $result = $this->geoCheck->result($actor, $uuid);
            if ($result['state'] === 'ready') {
                $this->overview($bot, $uuid, $node['name'], $result);

                return;
            }
            $keyboard = InlineKeyboardMarkup::make();
            if ($result['state'] === 'missing' && $node['connected'] && ! $node['disabled']) {
                $keyboard->addRow(InlineKeyboardButton::make(
                    '▶️ شروع بررسی مسیر', callback_data: 'geoc:start:'.$uuid, style: ButtonStyle::SUCCESS,
                ));
            } elseif ($result['state'] === 'pending') {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 دریافت نتیجه', callback_data: 'geoc:status:'.$uuid));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'geoc:list:1'));
            $state = $node['disabled'] ? 'غیرفعال' : ($node['connected'] ? 'متصل' : 'قطع');
            $this->show($bot, '🌐 مسیرسنج | '.$node['name'], [
                ['وضعیت نود', $state],
                ['آخرین بررسی', self::state($result['state'])],
            ], 'GeoCheck مسیر خروجی نود را بررسی می‌کند؛ نتیجه نشان‌دهنده دسترسی کاربران ایران نیست.', $keyboard);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'مشخصات این نود در دسترس نیست.');
        }
    }

    public function start(Nutgram $bot, string $uuid): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $state = $this->geoCheck->start($actor, $uuid);
            if ($state === 'existing') {
                $this->node($bot, $uuid);

                return;
            }
            $keyboard = InlineKeyboardMarkup::make();
            if ($state === 'pending') {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 دریافت نتیجه', callback_data: 'geoc:status:'.$uuid));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به نود', callback_data: 'geoc:node:'.$uuid));
            $this->show($bot, '🌐 مسیرسنج | بررسی مسیر', [
                ['وضعیت درخواست', self::state($state)],
            ], $state === 'pending'
                ? 'بررسی در پنل آغاز شد. برای دریافت گزارش دکمه بالا را انتخاب کنید.'
                : 'درخواست جدیدی ثبت نشد. برای جلوگیری از بررسی تکراری، نتیجه قبلی را بررسی کنید.', $keyboard);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'شروع بررسی ممکن نیست.');
        }
    }

    public function status(Nutgram $bot, string $uuid): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $result = $this->geoCheck->result($actor, $uuid, true);
            if ($result['state'] === 'ready') {
                $node = $this->geoCheck->node($actor, $uuid);
                $this->overview($bot, $uuid, $node['name'], $result);

                return;
            }
            $keyboard = InlineKeyboardMarkup::make();
            if (in_array($result['state'], ['pending', 'unavailable'], true)) {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 دریافت نتیجه', callback_data: 'geoc:status:'.$uuid));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به نود', callback_data: 'geoc:node:'.$uuid));
            $this->show($bot, '🌐 مسیرسنج | وضعیت بررسی', [
                ['وضعیت', self::state($result['state'])],
            ], 'دریافت نتیجه درخواست قبلی است؛ بررسی تازه‌ای آغاز نمی‌شود.', $keyboard);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'دریافت نتیجه فعلاً ممکن نیست.');
        }
    }

    /** Section page, always using sanitized cached data; no remote GeoCheck call. */
    public function section(Nutgram $bot, string $uuid, string $section, string $requested): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        if (! in_array($section, ['routes', 'web', 'stash', 'ai', 'geo', 'tech'], true)) {
            $this->unavailable($bot, 'این بخش معتبر نیست.');

            return;
        }
        try {
            $result = $this->geoCheck->result($actor, $uuid);
            if ($result['state'] !== 'ready') {
                $this->node($bot, $uuid);

                return;
            }
            if ($section === 'tech') {
                $this->technical($bot, $uuid, $result['summary']);

                return;
            }
            $page = self::page($requested);
            $items = $result[$section] ?? [];
            $pages = max(1, (int) ceil(count($items) / 5));
            if ($page === null || $page > $pages) {
                $this->unavailable($bot, 'صفحه معتبر نیست.');

                return;
            }

            $rows = [];
            $keyboard = InlineKeyboardMarkup::make();
            foreach (array_slice($items, ($page - 1) * 5, 5, true) as $index => $item) {
                $name = $item['name'] ?? null;
                $name = is_string($name) ? $name : 'نامشخص';
                $detail = match ($section) {
                    'routes' => self::verdict($item['verdict'] ?? null)
                        .' · RTT '.self::ms($item['rtt_ms'] ?? null)
                        .' · Loss '.self::value($item['loss'] ?? null),
                    'web' => self::verdict($item['verdict'] ?? null)
                        .' · HTTP '.self::value($item['status'] ?? null)
                        .' · '.self::ms($item['rtt_ms'] ?? null),
                    'stash' => self::verdict($item['state'] ?? null)
                        .' · '.self::ms($item['rtt_ms'] ?? null),
                    'ai' => self::verdict($item['state'] ?? null)
                        .' · HTTP '.self::value($item['status'] ?? null)
                        .' · '.self::ms($item['rtt_ms'] ?? null),
                    default => ($item['group'] ?? 'منبع').' · '.self::verdict($item['kind'] ?? null),
                };
                $rows[] = [$name, $detail];
                if ($section === 'routes') {
                    $keyboard->addRow(InlineKeyboardButton::make(
                        '🔎 '.self::short($name), callback_data: 'geoc:route:'.$uuid.':'.$index,
                    ));
                }
            }
            $this->pages($keyboard, 'geoc:section:'.$uuid.':'.$section.':', $page, $pages);
            $keyboard->addRow(InlineKeyboardButton::make('◀️ خلاصه گزارش', callback_data: 'geoc:node:'.$uuid));

            $title = match ($section) {
                'routes' => '🌐 مسیرهای اینترنت',
                'web' => '🔗 بررسی دسترسی وب',
                'stash' => '📦 دسترسی سرویس‌ها',
                'ai' => '🤖 سرویس‌های هوش مصنوعی',
                default => '📍 منابع موقعیت‌یابی',
            };
            $this->show($bot, $title, $rows,
                'این نتایج درباره خروجی نود هستند، نه وضعیت فیلترینگ یا موفقیت اتصال VPN.', $keyboard,
                'صفحه '.self::value($page).' از '.self::value($pages).' · '.self::value(count($items)).' مورد');
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'جزئیات گزارش در دسترس نیست.');
        }
    }

    public function route(Nutgram $bot, string $uuid, string $index): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        try {
            $result = $this->geoCheck->result($actor, $uuid);
            if ($result['state'] !== 'ready' || ! ctype_digit($index) || strlen($index) > 2) {
                $this->node($bot, $uuid);

                return;
            }
            $number = (int) $index;
            $route = $result['routes'][$number] ?? null;
            if (! is_array($route)) {
                $this->unavailable($bot, 'مسیر موردنظر پیدا نشد.');

                return;
            }
            $rows = [
                ['طبقه‌بندی', self::verdict($route['verdict'])],
                ['روش آزمایش', self::value($route['method'])],
                ['امتیاز مسیر', self::value($route['score']).' از ۱۰۰'],
                ['RTT', self::ms($route['rtt_ms'])],
                ['Jitter', self::ms($route['jitter_ms'])],
                ['Loss', self::value($route['loss'])],
            ];
            foreach (array_slice($route['hops'], 0, 10) as $hop) {
                $rows[] = ['Hop '.self::value($hop['ttl']),
                    self::value($hop['ip']).' · AS'.self::value($hop['asn'])
                    .' · '.self::ms($hop['avg_ms'])];
            }
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ مسیرهای اینترنت',
                    callback_data: 'geoc:section:'.$uuid.':routes:'.((int) floor($number / 5) + 1)))
                ->addRow(InlineKeyboardButton::make('◀️ خلاصه گزارش', callback_data: 'geoc:node:'.$uuid));
            $this->show($bot, '🔎 مسیر | '.($route['name'] ?? 'نامشخص'), $rows,
                'Hopها حداکثر تا ۱۰ مورد نمایش داده می‌شوند. ICMP به‌تنهایی تضمین‌کننده اتصال سرویس نیست.', $keyboard);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);
        } catch (Throwable) {
            $this->unavailable($bot, 'جزئیات مسیر در دسترس نیست.');
        }
    }

    /** @param array<string,mixed> $result */
    private function overview(Nutgram $bot, string $uuid, string $name, array $result): void
    {
        $s = $result['summary'];
        $counts = $s['breakdown'];
        $rows = [
            ['امتیاز مسیرهای خروجی', self::value($s['score']).' از ۱۰۰'],
            ['ریسک اعتبار IP', self::value($s['risk']).' از ۱۰۰'],
            ['IP خروجی', self::value($s['ip'])],
            ['کشور / ASN', self::value($s['country']).' · AS'.self::value($s['asn'])],
            ['سازمان شبکه', self::value($s['as_name'])],
            ['موقعیت غالب', self::value($s['consensus_country']).' · '.self::value($s['consensus_percent']).'٪'],
            ['مسیر مستقیم / غیرمستقیم',
                self::value($counts[0]).' / '.self::value(($counts[1] ?? 0) + ($counts[2] ?? 0) + ($counts[3] ?? 0))],
            ['اتصال HTTP', self::value($s['http_ok']).' موفق · '.self::value($s['http_altered']).' تغییر‌یافته'],
            ['مدت آزمایش', self::value($s['duration'] === null ? null : $s['duration'] / 1000).' ثانیه'],
        ];
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('🌐 مسیرها', callback_data: 'geoc:section:'.$uuid.':routes:1'),
                InlineKeyboardButton::make('🔗 دسترسی وب', callback_data: 'geoc:section:'.$uuid.':web:1'),
            )
            ->addRow(
                InlineKeyboardButton::make('📦 سرویس‌ها', callback_data: 'geoc:section:'.$uuid.':stash:1'),
                InlineKeyboardButton::make('🤖 هوش مصنوعی', callback_data: 'geoc:section:'.$uuid.':ai:1'),
            )
            ->addRow(
                InlineKeyboardButton::make('📍 موقعیت و IP', callback_data: 'geoc:section:'.$uuid.':geo:1'),
                InlineKeyboardButton::make('🔬 جزئیات فنی', callback_data: 'geoc:section:'.$uuid.':tech:1'),
            )
            ->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'geoc:list:1'));
        $this->show($bot, '🌐 مسیرسنج | '.$name, $rows,
            'این گزارش مسیر خروجی نود است و به‌تنهایی دسترسی کاربران داخل ایران را تأیید نمی‌کند.', $keyboard,
            'نتیجه آزمایش اخیر · '.self::value($s['at']));
    }

    /** @param array<string,mixed> $s */
    private function technical(Nutgram $bot, string $uuid, array $s): void
    {
        $counts = $s['breakdown'];
        $rows = [
            ['Direct', self::value($counts[0] ?? null)],
            ['Peered', self::value($counts[1] ?? null)],
            ['Transit', self::value($counts[2] ?? null)],
            ['Detour', self::value($counts[3] ?? null)],
            ['Intercepted', self::value($counts[4] ?? null)],
            ['Failed', self::value($counts[5] ?? null)],
            ['حداقل تأخیر', self::ms($s['latency_floor_ms'])],
            ['آزمایش ICMP', self::value($s['icmp_available'])],
            ['DNS Resolver', self::value($s['resolver'])],
            ['Hosting', self::value($s['hosting'])],
            ['Proxy / VPN / Tor', self::value($s['proxy']).' / '.self::value($s['vpn']).' / '.self::value($s['tor'])],
            ['یافته‌های تکمیلی', self::value($s['findings_count'])],
        ];
        $this->show($bot, '🔬 مسیرسنج | جزئیات فنی', $rows,
            'امتیازها براساس اندازه‌گیری GeoCheck هستند؛ معیار مستقیم برای سرعت VPN نیستند.',
            InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                '◀️ خلاصه گزارش', callback_data: 'geoc:node:'.$uuid,
            )));
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
        TelegramUi::answerCallback($bot);

        return $actor->userId;
    }

    private function unavailable(Nutgram $bot, string $text): void
    {
        $this->show($bot, '🌐 مسیرسنج', [['وضعیت', $text]], '',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('◀️ فهرست نودها', callback_data: 'geoc:list:1'))
                ->addRow(InlineKeyboardButton::make('◀️ کنترل پنل', callback_data: 'menu:admin:control')));
    }

    /** @param list<array{0:string,1:string}> $rows */
    private function show(Nutgram $bot, string $title, array $rows, string $footer,
        InlineKeyboardMarkup $keyboard, string $subtitle = ''): void
    {
        $rich = '<h2>'.self::esc($title).'</h2>'
            .($subtitle === '' ? '' : '<p>'.self::esc($subtitle).'</p>');
        if ($rows !== []) {
            $rich .= '<table bordered striped compact>';
            foreach ($rows as [$key, $value]) {
                $rich .= '<tr><td>'.self::esc($key).'</td><td>'.self::esc($value).'</td></tr>';
            }
            $rich .= '</table>';
        }
        if ($footer !== '') {
            $rich .= '<footer>'.self::esc($footer).'</footer>';
        }
        $plain = '<b>'.self::esc($title).'</b>'
            .($subtitle === '' ? '' : "\n".self::esc($subtitle));
        foreach ($rows as [$key, $value]) {
            $plain .= "\n".self::esc($key).': '.self::esc($value);
        }
        if ($footer !== '') {
            $plain .= "\n\n".self::esc($footer);
        }
        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

            return;
        } catch (TelegramException $exception) {
            report($exception);
        }
        TelegramUi::editText($bot, $plain, $keyboard, 'HTML');
    }

    private function pages(InlineKeyboardMarkup $keyboard, string $prefix, int $page, int $pages): void
    {
        $buttons = [];
        if ($page > 1) {
            $buttons[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: $prefix.($page - 1));
        }
        if ($page < $pages) {
            $buttons[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: $prefix.($page + 1));
        }
        if ($buttons !== []) {
            $keyboard->addRow(...$buttons);
        }
    }

    private static function page(string $value): ?int
    {
        return ctype_digit($value) && strlen($value) <= 2 && (int) $value >= 1 && (int) $value <= 99
            ? (int) $value : null;
    }

    private static function short(string $value): string
    {
        return mb_substr((string) preg_replace('/[\x00-\x1F\x7F\p{Cf}]/u', ' ', $value), 0, 30);
    }

    private static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'بله' : 'خیر';
        }
        if (is_int($value) || is_float($value)) {
            return PersianCalendar::digits(number_format($value, is_float($value) ? 2 : 0));
        }

        return is_string($value) && $value !== '' ? $value : 'نامشخص';
    }

    private static function ms(mixed $value): string
    {
        return self::value($value).' ms';
    }

    private static function verdict(mixed $value): string
    {
        return match ($value) {
            'direct' => 'مستقیم',
            'peered' => 'پیرینگ',
            'transit' => 'ترانزیت',
            'detour' => 'مسیر انحرافی',
            'intercepted' => 'تغییر مسیر',
            'failed' => 'ناموفق',
            'ok', 'available', 'reachable' => 'دردسترس',
            'altered' => 'پاسخ تغییر‌یافته',
            'unreachable' => 'غیرقابل‌دسترسی',
            'error' => 'خطا',
            'country' => 'شناسایی کشور',
            'availability' => 'بررسی دسترسی',
            'blocked' => 'محدودیت گزارش‌شده',
            default => 'نامشخص',
        };
    }

    private static function state(string $value): string
    {
        return match ($value) {
            'missing' => 'هنوز بررسی نشده',
            'pending' => 'در حال بررسی',
            'existing' => 'درخواست قبلی موجود است',
            'disabled' => 'ابزار غیرفعال است',
            'ineligible' => 'نود متصل و فعال نیست',
            'failed' => 'بررسی ناموفق',
            'unavailable' => 'نتیجه در دسترس نیست',
            default => 'نامشخص',
        };
    }

    private static function esc(string $value): string
    {
        return TelegramUi::escape($value);
    }
}
