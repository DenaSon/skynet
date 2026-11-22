<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadCustomerLiveServices;
use App\Modules\Provisioning\Application\ReadCustomerServiceDiagnostics;
use App\Support\PersianCalendar;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** SUP-DIAG-01: a root-only addition to the existing customer service detail. */
final readonly class CustomerServiceDiagnosticsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadCustomerLiveServices $live,
        private ReadCustomerServiceDiagnostics $diagnostics,
        private MenuRenderer $menus,
    ) {}

    public function show(
        Nutgram $bot,
        string $kind,
        string $subjectId,
        string $orderId,
        string $userPage,
        string $mode,
        string $servicePage,
        string $days,
    ): void {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }
        $ipParams = [];
        $ipPage = preg_match('/\Aip([0-7])p([1-2])\z/D', $kind, $ipParams) === 1;
        if ((! in_array($kind, ['home', 'usage', 'history', 'nodes', 'start', 'result'], true) && ! $ipPage)
            || ! self::number($subjectId, 15) || ! self::number($orderId, 15)
            || ! self::number($userPage, 6) || (int) $userPage > 100000
            || ! UserDirectoryMode::valid($mode)
            || ! self::number($servicePage, 4) || (int) $servicePage > 1000
            || ! in_array($days, ['1', '7', '30'], true)) {
            $this->menus->showUserNotice($bot, 'درخواست معتبر نیست.');

            return;
        }

        try {
            $subject = (int) $subjectId;
            $order = (int) $orderId;
            // Detail pages only read the already-authorized job result; they never create another job.
            $connectionData = $kind === 'result' || $ipPage
                ? $this->diagnostics->connectionResult($actor->userId, $subject, $order)
                : ['state' => 'unavailable', 'nodes' => []];
            $data = match ($kind) {
                'home' => $this->home($actor->userId, $subject, $order),
                'usage' => $this->usage($actor->userId, $subject, $order, (int) $days),
                'history' => $this->history($actor->userId, $subject, $order),
                'nodes' => $this->nodes($actor->userId, $subject, $order),
                'start' => $this->connections($this->diagnostics->startConnections($actor->userId, $subject, $order)),
                'result' => $this->connections($connectionData),
                default => $this->connectionIps($connectionData, (int) $ipParams[1], (int) $ipParams[2]),
            };
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (ValidationException) {
            $this->menus->showUserNotice($bot, 'سرویس موردنظر پیدا نشد.');

            return;
        } catch (Throwable) {
            $this->menus->showUserNotice($bot, 'دریافت گزارش ممکن نیست. دوباره بررسی کنید.');

            return;
        }

        $back = fn (string $target, string $range = '7'): string => self::callback($target, $subjectId, $orderId, $userPage, $mode, $servicePage, $range);
        $keyboard = InlineKeyboardMarkup::make();
        if ($kind === 'home') {
            $keyboard->addRow(
                InlineKeyboardButton::make('📊 امروز', callback_data: $back('usage', '1')),
                InlineKeyboardButton::make('۷ روز', callback_data: $back('usage', '7')),
                InlineKeyboardButton::make('۳۰ روز', callback_data: $back('usage', '30')),
            );
            $keyboard->addRow(
                InlineKeyboardButton::make('📋 تاریخچه اشتراک', callback_data: $back('history')),
                InlineKeyboardButton::make('🌐 نودهای سرویس', callback_data: $back('nodes')),
            );
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔌 بررسی اتصال‌ها', callback_data: $back('start'),
            ));
        } elseif ($kind === 'start' || $kind === 'result') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔄 دریافت نتیجه', callback_data: $back('result'),
            ));
            if ($kind === 'result' && $connectionData['state'] === 'completed') {
                foreach ($connectionData['nodes'] as $index => $node) {
                    if ($node['ips'] !== []) {
                        $keyboard->addRow(InlineKeyboardButton::make(
                            '📋 IPهای '.$node['name'], callback_data: $back("ip{$index}p1"),
                        ));
                    }
                }
            }
        } elseif ($ipPage) {
            $nodeIndex = (int) $ipParams[1];
            $page = (int) $ipParams[2];
            $ips = $connectionData['nodes'][$nodeIndex]['ips'] ?? [];
            foreach (array_slice($ips, ($page - 1) * 5, 5) as $ip) {
                $keyboard->addRow(TelegramUi::copy($ip['ip'], '📋 کپی '.$ip['ip']));
            }
            $navigation = [];
            if ($page > 1) {
                $navigation[] = InlineKeyboardButton::make(
                    '◀️ قبلی', callback_data: $back("ip{$nodeIndex}p".($page - 1)),
                );
            }
            if ($page < 2 && count($ips) > $page * 5) {
                $navigation[] = InlineKeyboardButton::make(
                    'بعدی ▶️', callback_data: $back("ip{$nodeIndex}p".($page + 1)),
                );
            }
            if ($navigation !== []) {
                $keyboard->addRow(...$navigation);
            }
            $keyboard->addRow(InlineKeyboardButton::make(
                '◀️ نتیجه اتصال‌ها', callback_data: $back('result'),
            ));
        } else {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🔄 تازه‌سازی', callback_data: $back($kind, $days),
            ));
        }
        if ($kind !== 'home') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '◀️ عیب‌یابی سرویس', callback_data: $back('home'),
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ جزئیات سرویس',
            callback_data: "c360:v:{$subjectId}:{$orderId}:{$userPage}:{$mode}:{$servicePage}"));

        $title = $data['title'];
        $rows = $data['rows'];
        $note = $data['note'];
        $richRows = '';
        $textRows = '';
        foreach ($rows as [$label, $value]) {
            $richRows .= '<tr><td>'.TelegramUi::escape($label).'</td><td>'
                .TelegramUi::escape($value).'</td></tr>';
            $textRows .= "\n".TelegramUi::escape($label).': '.TelegramUi::escape($value);
        }
        $rich = '<h2>'.TelegramUi::escape($title).'</h2>'
            .($richRows === '' ? '' : '<table bordered striped compact>'.$richRows.'</table>')
            .'<p>'.TelegramUi::escape($note).'</p>'
            .'<footer>🔄 بررسی به درخواست مدیر انجام شد</footer>';
        $native = '<b>'.TelegramUi::escape($title).'</b>'
            .$textRows."\n\n".TelegramUi::escape($note);

        TelegramUi::answerCallback($bot);
        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);
        } catch (TelegramException) {
            TelegramUi::editText($bot, $native, $keyboard, 'HTML');
        }
    }

    /** @return array{title:string,rows:list<array{string,string}>,note:string} */
    private function home(int $actorId, int $subjectId, int $orderId): array
    {
        $data = $this->live->detail($actorId, $subjectId, $orderId);
        $status = $data['state'] === 'available'
            ? match ($data['remoteStatus']) {
                'ACTIVE' => 'فعال', 'DISABLED' => 'غیرفعال', 'EXPIRED' => 'منقضی',
                'LIMITED' => 'اتمام حجم', default => 'نامشخص',
            } : 'نامشخص';

        return [
            'title' => '🔎 عیب‌یابی سرویس #'.$orderId,
            'rows' => [
                ['سرویس', $data['name']],
                ['وضعیت در پنل', $status],
                ['مصرف تجمعی', self::volume($data['usedBytes'])],
                ['باقی‌مانده', $data['limitBytes'] === 0 ? 'نامحدود' : self::volume($data['remainingBytes'])],
                ['انقضا', $data['expiresAt'] === null ? 'نامشخص' : PersianCalendar::utcDateTime($data['expiresAt'])],
            ],
            'note' => 'برای بررسی جزئیات، یکی از گزینه‌ها را انتخاب کنید. اطلاعات لحظه‌ای به معنی سالم بودن اتصال اینترنت مشتری نیست.',
        ];
    }

    /** @return array{title:string,rows:list<array{string,string}>,note:string} */
    private function usage(int $actorId, int $subjectId, int $orderId, int $days): array
    {
        $data = $this->diagnostics->usage($actorId, $subjectId, $orderId, $days);
        $rows = [['دوره', match ($days) {
            1 => 'امروز', 7 => '۷ روز تقویمی', 30 => '۳۰ روز تقویمی',
            default => 'نامشخص',
        }]];
        if ($data['state'] !== 'available') {
            $rows[] = ['گزارش', 'در حال حاضر در دسترس نیست'];
        } else {
            $rows[] = ['مجموع مصرف دوره', self::volume($data['totalBytes'])];
            foreach ($data['nodes'] as $node) {
                $rows[] = [$node['name'], self::volume($node['bytes'])];
            }
            if ($data['nodes'] === []) {
                $rows[] = ['تفکیک نودها', 'موردی گزارش نشده'];
            }
        }

        return ['title' => '📊 گزارش مصرف سرویس', 'rows' => $rows,
            'note' => 'آمار روزانه پنل است؛ مصرف دوره‌ای با مصرف تجمعی اشتراک یکسان نیست.'];
    }

    /** @return array{title:string,rows:list<array{string,string}>,note:string} */
    private function history(int $actorId, int $subjectId, int $orderId): array
    {
        $data = $this->diagnostics->history($actorId, $subjectId, $orderId);
        $rows = [];
        if ($data['state'] !== 'available') {
            $rows[] = ['تاریخچه', 'در دسترس نیست'];
        } elseif ($data['records'] === []) {
            $rows[] = ['درخواست', 'موردی ثبت نشده'];
        } else {
            foreach ($data['records'] as $record) {
                $rows[] = ['درخواست اشتراک', PersianCalendar::utcDateTime($record['at'])];
            }
        }

        return ['title' => '📋 تاریخچه دریافت اشتراک', 'rows' => $rows,
            'note' => 'این تاریخچه نشان‌دهنده دریافت یا تلاش برای دریافت اشتراک است، نه اثبات اتصال VPN.'];
    }

    /** @return array{title:string,rows:list<array{string,string}>,note:string} */
    private function nodes(int $actorId, int $subjectId, int $orderId): array
    {
        $data = $this->diagnostics->nodes($actorId, $subjectId, $orderId);
        $rows = [];
        if ($data['state'] !== 'available') {
            $rows[] = ['وضعیت', 'در دسترس نیست'];
        } elseif ($data['nodes'] === []) {
            $rows[] = ['نود', 'موردی گزارش نشده'];
        } else {
            foreach ($data['nodes'] as $node) {
                $state = match ($node['state']) {
                    'healthy' => 'سالم', 'suspicious' => 'نیازمند بررسی',
                    'disrupted' => 'اختلال ثبت‌شده', default => 'نامشخص',
                };
                $rows[] = [$node['name'], $state];
            }
        }

        return ['title' => '🌐 وضعیت نودهای سرویس', 'rows' => $rows,
            'note' => 'وضعیت از آخرین پایش معتبر دناگیت خوانده می‌شود و تضمین‌کننده دسترسی از اینترنت مشتری نیست.'];
    }

    /**
     * @param  array{state:string,nodes:list<array{name:string,reports:int,lastSeenAt:?string,country:?string,ips:list<array{ip:string,lastSeenAt:string}>}>}  $data
     * @return array{title:string,rows:list<array{string,string}>,note:string}
     */
    private function connections(array $data): array
    {
        $message = match ($data['state']) {
            'disabled' => 'این بررسی هنوز فعال نشده است.',
            'pending' => 'در حال جمع‌آوری نتیجه؛ کمی بعد «دریافت نتیجه» را بزنید.',
            'completed' => 'گزارش دریافت شد.',
            default => 'نتیجه قابل اتکا در دسترس نیست.',
        };
        $rows = [['وضعیت', $message]];
        foreach ($data['nodes'] as $node) {
            $rows[] = [$node['name'].($node['country'] === null ? '' : ' ('.$node['country'].')'),
                PersianCalendar::number(number_format($node['reports'])).' نشانی گزارش‌شده'];
            $rows[] = ['آخرین مشاهده', $node['lastSeenAt'] === null
                ? 'نامشخص'
                : PersianCalendar::utcDateTime($node['lastSeenAt'])];
        }

        return ['title' => '🔌 بررسی اتصال‌های سرویس', 'rows' => $rows,
            'note' => 'این گزارش از نشانی‌های مشاهده‌شده توسط نودهاست؛ تعداد دستگاه یا اتصال فعال قطعی نیست.'];
    }

    /**
     * @param  array{state:string,nodes:list<array{name:string,reports:int,lastSeenAt:?string,country:?string,ips:list<array{ip:string,lastSeenAt:string}>}>}  $data
     * @return array{title:string,rows:list<array{string,string}>,note:string}
     */
    private function connectionIps(array $data, int $index, int $page): array
    {
        $node = $data['state'] === 'completed' ? ($data['nodes'][$index] ?? null) : null;
        $ips = $node['ips'] ?? [];
        $rows = [];
        if ($node === null || $ips === [] || ($page - 1) * 5 >= count($ips)) {
            $rows[] = ['اطلاعات', 'گزارشی برای این صفحه در دسترس نیست.'];
        } else {
            $rows[] = ['نود', $node['name']];
            $rows[] = ['کشور نود', $node['country'] ?? 'نامشخص'];
            $rows[] = ['تعداد گزارش‌شده', PersianCalendar::number(number_format($node['reports']))];
            $rows[] = ['نمایش', PersianCalendar::number((string) (min(count($ips), 10))).' مورد جدیدتر'];
            foreach (array_slice($ips, ($page - 1) * 5, 5) as $item) {
                $rows[] = ['IP', $item['ip']];
                $rows[] = ['آخرین مشاهده', PersianCalendar::utcDateTime($item['lastSeenAt'])
                    .' ('.self::observationAge($item['lastSeenAt']).')'];
            }
        }

        return ['title' => '📋 IPهای مشاهده‌شده', 'rows' => $rows,
            'note' => 'آخرین مشاهده به معنی اتصال فعال همین لحظه یا تعداد دستگاه‌ها نیست.'];
    }

    private static function observationAge(string $observedAt): string
    {
        $age = now('UTC')->getTimestamp() - (new DateTimeImmutable($observedAt))->getTimestamp();
        if ($age < -60) {
            return 'نامشخص';
        }
        if ($age < 60) {
            return 'همین حالا';
        }
        if ($age < 3600) {
            return PersianCalendar::number((string) intdiv($age, 60)).' دقیقه پیش';
        }
        if ($age < 86400) {
            return PersianCalendar::number((string) intdiv($age, 3600)).' ساعت پیش';
        }

        return PersianCalendar::number((string) intdiv($age, 86400)).' روز پیش';
    }

    private static function volume(?int $bytes): string
    {
        return $bytes === null ? 'نامشخص' : number_format($bytes / 1073741824, 2).' گیگابایت';
    }

    private static function number(string $value, int $maxDigits): bool
    {
        return preg_match('/\A[1-9][0-9]*\z/D', $value) === 1 && strlen($value) <= $maxDigits;
    }

    private static function callback(
        string $kind, string $subjectId, string $orderId,
        string $userPage, string $mode, string $servicePage, string $days,
    ): string {
        return "sd:{$kind}:{$subjectId}:{$orderId}:{$userPage}:{$mode}:{$servicePage}:{$days}";
    }
}
