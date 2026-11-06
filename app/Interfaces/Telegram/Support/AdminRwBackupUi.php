<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\RwBackupOperations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

final readonly class AdminRwBackupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private MenuRenderer $menus,
        private RwBackupOperations $backup,
    ) {}

    /** @return array{userId:int,telegramId:int}|null */
    private function root(Nutgram $bot): ?array
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

        return ['userId' => $actor->userId, 'telegramId' => $telegramId];
    }

    public function listing(Nutgram $bot, string $page = '1'): void
    {
        if ($this->root($bot) === null) {
            return;
        }
        $back = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به تنظیمات', callback_data: 'admin:welcome:show'));
        if (config('rw_backup.enabled') !== true) {
            $this->show($bot, '🗄️ مدیریت بک‌آپ', 'مدیریت بک‌آپ در حال حاضر غیرفعال است.', $back);

            return;
        }

        try {
            $items = $this->backup->all();
            $requested = ctype_digit($page) && strlen($page) <= 3 ? (int) $page : 0;
            $pages = max(1, (int) ceil(count($items) / 6));
            if ($requested < 1 || $requested > $pages) {
                throw new \RuntimeException('Invalid page.');
            }
            $keyboard = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('➕ ایجاد بک‌آپ جدید', callback_data: 'admin:rwbackup:prepare'));
            $rows = array_slice($items, ($requested - 1) * 6, 6);
            $table = '';
            foreach ($rows as $item) {
                $label = self::label($item['state']).' '.self::date($item['timestamp']).' · '.self::size($item['size']);
                $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: 'admin:rwbackup:view:'.$item['id']));
                $table .= '<tr><td><code>'.TelegramUi::escape(self::date($item['timestamp']))
                    .'</code></td><td>'.TelegramUi::escape(self::label($item['state'])).'</td></tr>';
            }
            $nav = [];
            if ($requested > 1) {
                $nav[] = InlineKeyboardButton::make('◀️ صفحه قبل', callback_data: 'admin:rwbackup:list:'.($requested - 1));
            }
            if ($requested < $pages) {
                $nav[] = InlineKeyboardButton::make('صفحه بعد ▶️', callback_data: 'admin:rwbackup:list:'.($requested + 1));
            }
            if ($nav !== []) {
                $keyboard->addRow(...$nav);
            }
            $keyboard->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی', callback_data: 'admin:rwbackup:list:'.$requested));
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به تنظیمات', callback_data: 'admin:welcome:show'));

            $total = self::size(array_sum(array_column($items, 'size')));
            $count = number_format(count($items));
            $notice = '⚠️ نسخه‌هایی که دانلود نشده‌اند، فقط روی سرور نگهداری می‌شوند.';
            $text = 'نسخه‌های موجود: '.$count.' · حجم کل: '.$total
                ."\nصفحه $requested از $pages\n"
                .($items === [] ? 'هنوز نسخه پشتیبانی ثبت نشده است.'
                    : 'برای مشاهده جزئیات، نسخه موردنظر را انتخاب کنید.')
                ."\n".$notice;
            $rich = '<p>نسخه‌ها: <b>'.TelegramUi::escape($count).'</b>'
                .' · حجم کل: <b>'.TelegramUi::escape($total).'</b></p>'
                .($table === '' ? '<p>هنوز نسخه پشتیبانی ثبت نشده است.</p>'
                    : '<table bordered striped compact><tr><td>تاریخ ایجاد</td><td>وضعیت</td></tr>'
                    .$table.'</table>')
                .'<p>صفحه '.$requested.' از '.$pages.'</p>'
                .'<footer>'.TelegramUi::escape($notice).'</footer>';
            $this->show($bot, '🗄️ مدیریت بک‌آپ', $text, $keyboard, $rich);
        } catch (Throwable) {
            $this->show($bot, '🗄️ مدیریت بک‌آپ', 'دریافت فهرست بک‌آپ‌ها در حال حاضر امکان‌پذیر نیست.', $back);
        }
    }

    public function detail(Nutgram $bot, string $id): void
    {
        if ($this->root($bot) === null) {
            return;
        }
        if (! RwBackupOperations::validId($id)) {
            $this->listing($bot);

            return;
        }
        try {
            $item = collect($this->backup->all())->firstWhere('id', $id);
            if (! is_array($item)) {
                throw new \RuntimeException('Backup missing.');
            }
            $keyboard = InlineKeyboardMarkup::make();
            if ($item['state'] === 'ready') {
                $keyboard->addRow(InlineKeyboardButton::make('⬇️ دریافت لینک دانلود', callback_data: 'admin:rwbackup:download:'.$id));
                $keyboard->addRow(InlineKeyboardButton::make('🗑️ حذف بک‌آپ', callback_data: 'admin:rwbackup:delete:'.$id));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1'));
            $state = self::label($item['state']);
            $date = self::date($item['timestamp']);
            $size = self::size($item['size']);
            $text = 'وضعیت: '.$state."\n"
                .'تاریخ ایجاد: '.$date."\n"
                .'حجم فایل: '.$size."\n"
                .'شناسه نسخه: '.TelegramUi::code($id);
            $rich = '<table bordered striped compact>'
                .'<tr><td>وضعیت</td><td><b>'.TelegramUi::escape($state).'</b></td></tr>'
                .'<tr><td>تاریخ ایجاد</td><td><code>'.TelegramUi::escape($date).'</code></td></tr>'
                .'<tr><td>حجم فایل</td><td>'.TelegramUi::escape($size).'</td></tr>'
                .'<tr><td>شناسه</td><td><code>'.TelegramUi::escape($id).'</code></td></tr>'
                .'</table>';
            if ($item['state'] !== 'ready') {
                $keyboard->addRow(InlineKeyboardButton::make('🔄 به‌روزرسانی وضعیت', callback_data: 'admin:rwbackup:view:'.$id));
            }
            $this->show($bot, '📦 جزئیات بک‌آپ', $text, $keyboard, $rich);
        } catch (Throwable) {
            $this->listing($bot);
        }
    }

    public function prepare(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null || config('rw_backup.enabled') !== true) {
            return;
        }
        $token = bin2hex(random_bytes(12));
        Cache::put(self::tokenKey('create', $root['userId'], $token), true, now()->addMinutes(3));
        $this->show($bot, '➕ تأیید ایجاد بک‌آپ',
            "یک نسخه رمزنگاری‌شده از پایگاه داده، تنظیمات و گواهی‌های سرور ایجاد خواهد شد.\nآیا ایجاد بک‌آپ جدید را تأیید می‌کنید؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید و شروع', callback_data: 'admin:rwbackup:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'admin:rwbackup:list:1')));
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null || config('rw_backup.enabled') !== true
            || preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            return;
        }
        if (Cache::pull(self::tokenKey('create', $root['userId'], $token)) !== true) {
            $this->listing($bot);

            return;
        }
        try {
            $id = $this->backup->start();
            $this->show($bot, '⏳ ایجاد بک‌آپ آغاز شد',
                'شناسه نسخه: '.TelegramUi::code($id)."\nبک‌آپ در پس‌زمینه ایجاد می‌شود. برای مشاهده نتیجه، وضعیت نسخه را به‌روزرسانی کنید.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 مشاهده وضعیت', callback_data: 'admin:rwbackup:view:'.$id))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1')));
        } catch (Throwable) {
            $this->show($bot, '⚠️ ایجاد بک‌آپ ناموفق بود', 'عملیات آغاز نشد. ممکن است بک‌آپ دیگری در حال ایجاد باشد یا ارتباط با سرور برقرار نباشد.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    public function download(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null || ! RwBackupOperations::validId($id) || config('rw_backup.enabled') !== true) {
            return;
        }

        // Only log a fixed stage and exception class: never a token, URL, chat
        // identifier or Telegram exception message.
        $stage = 'remote_info';
        try {
            $this->backup->info($id);
            $stage = 'url';
            $token = bin2hex(random_bytes(32));
            $url = RwBackupDownloadUrl::forToken($token);

            $stage = 'cache';
            if (Cache::put('rwbackup:download:'.hash('sha256', $token), [
                'userId' => $root['userId'], 'telegramId' => $root['telegramId'], 'id' => $id,
            ], now()->addMinutes(10)) !== true) {
                throw new \RuntimeException('Cannot store one-time download grant.');
            }

            $stage = 'callback';
            if ($bot->isCallbackQuery()) {
                TelegramUi::answerCallback($bot);
            }
            $stage = 'telegram_send';
            $bot->sendMessage(
                text: "🔐 لینک دانلود فقط یک‌بار و تا ۱۰ دقیقه اعتبار دارد.\nبرای حفظ محرمانگی بک‌آپ، لینک را در اختیار دیگران قرار ندهید.",
                reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                    '⬇️ دانلود بک‌آپ رمزنگاری‌شده',
                    url: $url,
                )),
            );
        } catch (Throwable $exception) {
            Log::warning('rw_backup.download_link_failed', [
                'stage' => $stage,
                'exception' => $exception::class,
            ]);
            $this->show($bot, '⚠️ دریافت لینک ناموفق بود', 'ایجاد لینک دانلود در حال حاضر امکان‌پذیر نیست.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    public function prepareDelete(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null || ! RwBackupOperations::validId($id) || config('rw_backup.enabled') !== true) {
            return;
        }
        try {
            $this->backup->info($id);
            $token = bin2hex(random_bytes(12));
            Cache::put(self::tokenKey('delete', $root['userId'], $token), $id, now()->addMinutes(3));
            $this->show($bot, '🗑️ تأیید حذف بک‌آپ',
                'شناسه نسخه: '.TelegramUi::code($id)."\nآیا حذف دائمی این نسخه را تأیید می‌کنید؟\nاین اقدام قابل بازگشت نیست. آخرین نسخه سالم قابل حذف نخواهد بود.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🗑️ تأیید حذف دائمی', callback_data: 'admin:rwbackup:remove:'.$token))
                    ->addRow(InlineKeyboardButton::make('◀️ انصراف', callback_data: 'admin:rwbackup:view:'.$id)));
        } catch (Throwable) {
            $this->listing($bot);
        }
    }

    public function remove(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null || config('rw_backup.enabled') !== true
            || preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            return;
        }
        $id = Cache::pull(self::tokenKey('delete', $root['userId'], $token));
        if (! is_string($id) || ! RwBackupOperations::validId($id)) {
            $this->listing($bot);

            return;
        }
        try {
            $this->backup->delete($id);
            $this->show($bot, '✅ بک‌آپ حذف شد', 'نسخه انتخاب‌شده با موفقیت از سرور حذف شد.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1')));
        } catch (Throwable) {
            $this->show($bot, '⚠️ حذف بک‌آپ ناموفق بود',
                'حذف این نسخه امکان‌پذیر نیست. نسخه ممکن است در حال استفاده یا آخرین بک‌آپ سالم باشد.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ بازگشت به فهرست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    private static function tokenKey(string $action, int $userId, string $token): string
    {
        return 'rwbackup:'.$action.':'.$userId.':'.$token;
    }

    private static function label(string $state): string
    {
        return match ($state) {
            'ready' => '✅ آماده', 'running' => '⏳ در حال ایجاد', default => '❌ ناموفق',
        };
    }

    private static function size(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' مگابایت';
    }

    private static function date(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, 'UTC')->setTimezone('Asia/Tehran')->format('Y/m/d H:i');
    }

    private function show(Nutgram $bot, string $title, string $detail, InlineKeyboardMarkup $keyboard, ?string $richBody = null): void
    {
        $html = '<h2>'.TelegramUi::escape($title).'</h2>'
            .($richBody ?? '<p>'.TelegramUi::escape(strip_tags($detail)).'</p>');
        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);

            return;
        } catch (TelegramException) {
            // Preserve identical authorized controls through native HTML fallback.
        }
        $plain = '<b>'.TelegramUi::escape($title).'</b>'."\n\n".$detail;
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $plain, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $plain, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
