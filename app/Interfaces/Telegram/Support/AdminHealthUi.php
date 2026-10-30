<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ReadAdminHealth;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/** Read-only Denaban health, authorized for private trusted Super Admins. */
final readonly class AdminHealthUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadAdminHealth $health,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, bool $simple = false): void
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return;
        }

        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return;
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        try {
            // Defense in depth: the underlying application action rechecks
            // current Super Admin trust before it reads operational history.
            $data = $this->health->handle($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        } catch (\Throwable) {
            $data = [
                'state' => 'unavailable', 'enabled' => false,
                'checkedAt' => null, 'items' => [], 'total' => 0,
            ];
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی گزارش', callback_data: 'denaban:health'))
            ->addRow(InlineKeyboardButton::make('◀️ دنابان', callback_data: 'denaban:show'))
            ->addRow(InlineKeyboardButton::make(
                $simple ? '✨ نمای جدید' : '📝 نمای ساده',
                callback_data: $simple ? 'denaban:health' : 'denaban:health:simple',
            ));

        if (! $simple) {
            try {
                TelegramUi::editRichText(
                    $bot,
                    new InputRichMessage(html: self::richReport($data), is_rtl: true),
                    $keyboard,
                );

                return;
            } catch (TelegramException $exception) {
                report($exception);
                // Preserve the same authorized, safe snapshot in native HTML.
            }
        }

        $text = self::report($data);
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    /** @param array{state:string,enabled:bool,checkedAt:string|null,items:list<array{label:string,status:string,summary:string,group:string}>,total:int} $data */
    private static function report(array $data): string
    {
        $lines = [
            '<b>🩺 دنابان | سلامت سیستم</b>',
            '',
            '<b>'.self::state($data['state']).'</b>',
            self::description($data['state']),
        ];

        foreach (['basic' => '🔍 بررسی‌های پایه', 'operations' => '📊 منابع و عملیات'] as $group => $heading) {
            $groupItems = array_values(array_filter(
                $data['items'], static fn (array $item): bool => $item['group'] === $group,
            ));
            if ($groupItems === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = '<b>'.$heading.'</b>';
            foreach ($groupItems as $item) {
                $lines[] = self::symbol($item['status']).' '
                    .TelegramUi::escape($item['label']).': '.self::status($item['status'])
                    .($item['summary'] === '' ? '' : ' · '.TelegramUi::escape($item['summary']));
            }
        }

        $lines[] = '';
        $lines[] = '🕒 آخرین ثبت: '.self::time($data['checkedAt']);
        $lines[] = '<blockquote>تازه‌سازی فقط اطلاعات ذخیره‌شده را می‌خواند و بررسی جدیدی روی Queue یا سرورها اجرا نمی‌کند. اطلاعات قدیمی یا ناقص، سالم گزارش نمی‌شوند.</blockquote>';

        return implode("\n", $lines);
    }

    /** @param array{state:string,enabled:bool,checkedAt:string|null,items:list<array{label:string,status:string,summary:string,group:string}>,total:int} $data */
    private static function richReport(array $data): string
    {
        $base = '';
        $operations = '';
        foreach ($data['items'] as $item) {
            $detail = self::symbol($item['status']).' '.self::status($item['status'])
                .($item['summary'] === '' ? '' : ' · '.$item['summary']);
            $row = '<tr><td>'.TelegramUi::escape($item['label']).'</td><td align="left"><b>'
                .TelegramUi::escape($detail).'</b></td></tr>';
            if ($item['group'] === 'operations') {
                $operations .= $row;
            } else {
                $base .= $row;
            }
        }

        $basic = $base === '' ? '<p>هنوز نتیجه‌ای ثبت نشده است.</p>'
            : '<table bordered striped compact>'.$base.'</table>';
        $ops = $operations === '' ? '<p>هنوز شاخص عملیاتی ثبت نشده است.</p>'
            : '<table bordered striped compact>'.$operations.'</table>';

        return '<h2>🩺 دنابان | سلامت سیستم</h2>'
            .'<p><b>'.TelegramUi::escape(self::state($data['state'])).'</b></p>'
            .'<p>'.TelegramUi::escape(self::description($data['state'])).'</p>'
            .'<h3>🔍 بررسی‌های پایه</h3>'
            .$basic
            .'<h3>📊 منابع و عملیات</h3>'
            .$ops
            .'<footer>🕒 آخرین ثبت: '.TelegramUi::escape(self::time($data['checkedAt'])).'</footer>';
    }

    private static function state(string $state): string
    {
        return match ($state) {
            'healthy' => '🟢 همه بررسی‌های ثبت‌شده سالم',
            'warning' => '🟠 نیازمند توجه',
            'failed' => '🔴 اختلال شناسایی شد',
            'stale' => '⚪ گزارش قدیمی است',
            'disabled' => '⏸️ پایش دوره‌ای غیرفعال است',
            'waiting' => '⏳ در انتظار اولین نتیجه',
            default => '⚪ وضعیت قابل تعیین نیست',
        };
    }

    private static function description(string $state): string
    {
        return match ($state) {
            'healthy' => 'آخرین نوبت بررسی کامل و به‌روز است.',
            'warning' => 'حداقل یکی از بررسی‌ها هشدار داده است.',
            'failed' => 'حداقل یکی از بررسی‌ها خطا ثبت کرده است.',
            'stale' => 'نتیجه تازه در سه دقیقه اخیر ثبت نشده؛ سلامت فعلی نامشخص است.',
            'disabled' => 'برای فعال‌سازی، پس از آماده‌بودن Migration و Worker، تنظیم DENAGATE_HEALTH_ENABLED را بررسی کنید.',
            'waiting' => 'پایش فعال است، اما هنوز تاریخچه‌ای ثبت نشده است.',
            default => 'دریافت اطلاعات سلامت کامل نیست؛ دیتابیس و Migration را بررسی کنید.',
        };
    }

    private static function symbol(string $status): string
    {
        return match ($status) {
            'ok' => '🟢',
            'warning' => '🟠',
            'failed', 'crashed' => '🔴',
            default => '⚪',
        };
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'ok' => 'سالم',
            'warning' => 'هشدار',
            'failed' => 'ناموفق',
            'crashed' => 'خطای اجرا',
            'skipped' => 'اجرا نشده',
            default => 'نامشخص',
        };
    }

    private static function time(?string $value): string
    {
        return $value === null ? 'ثبت نشده' : PersianCalendar::utcDateTime($value);
    }
}
