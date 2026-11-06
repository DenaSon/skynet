<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Infrastructure\Backup\RwBackupSsh;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        private RwBackupSsh $backup,
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
            ->addRow(InlineKeyboardButton::make('◀️ تنظیمات', callback_data: 'admin:welcome:show'));
        if (config('rw_backup.enabled') !== true) {
            $this->show($bot, '🗄️ مدیریت بک‌آپ', 'قابلیت هنوز در سرور فعال نشده است.', $back);

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
                ->addRow(InlineKeyboardButton::make('➕ تهیه بک‌آپ جدید', callback_data: 'admin:rwbackup:prepare'));
            $rows = array_slice($items, ($requested - 1) * 6, 6);
            $table = '';
            foreach ($rows as $item) {
                $label = self::label($item['state']).' '.self::date($item['timestamp']).' · '.self::size($item['size']);
                $keyboard->addRow(InlineKeyboardButton::make($label, callback_data: 'admin:rwbackup:view:'.$item['id']));
                $table .= '<tr><td>'.TelegramUi::escape(self::date($item['timestamp']))
                    .'</td><td>'.TelegramUi::escape(self::label($item['state'])).'</td></tr>';
            }
            $nav = [];
            if ($requested > 1) {
                $nav[] = InlineKeyboardButton::make('◀️ قبلی', callback_data: 'admin:rwbackup:list:'.($requested - 1));
            }
            if ($requested < $pages) {
                $nav[] = InlineKeyboardButton::make('بعدی ▶️', callback_data: 'admin:rwbackup:list:'.($requested + 1));
            }
            if ($nav !== []) {
                $keyboard->addRow(...$nav);
            }
            $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'admin:rwbackup:list:'.$requested));
            $keyboard->addRow(InlineKeyboardButton::make('◀️ تنظیمات', callback_data: 'admin:welcome:show'));

            $total = array_sum(array_column($items, 'size'));
            $text = 'تعداد نسخه‌ها: '.count($items).' · حجم آرشیوها: '.self::size($total)
                ."\nصفحه $requested از $pages\n"
                .'رمز بک‌آپ جداگانه نگهداری می‌شود. نسخه دانلودنشده خارج از سرور محفوظ نیست.';
            $rich = $table === '' ? '<p>هنوز نسخه‌ای موجود نیست.</p>'
                : '<table bordered striped compact>'.$table.'</table>';
            $this->show($bot, '🗄️ مدیریت بک‌آپ Remnawave', $text, $keyboard, $rich);
        } catch (Throwable) {
            $this->show($bot, '🗄️ مدیریت بک‌آپ', 'ارتباط با سرور بک‌آپ برقرار نشد یا پاسخ معتبر نبود.', $back);
        }
    }

    public function detail(Nutgram $bot, string $id): void
    {
        if ($this->root($bot) === null) {
            return;
        }
        if (! RwBackupSsh::validId($id)) {
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
                $keyboard->addRow(InlineKeyboardButton::make('🗑️ حذف نسخه', callback_data: 'admin:rwbackup:delete:'.$id));
            }
            $keyboard->addRow(InlineKeyboardButton::make('◀️ لیست بک‌آپ‌ها', callback_data: 'admin:rwbackup:list:1'));
            $text = 'شناسه: '.TelegramUi::code($id)
                ."\nزمان: ".self::date($item['timestamp'])
                ."\nحجم: ".self::size($item['size'])
                ."\nوضعیت: ".self::label($item['state']);
            $this->show($bot, '📦 جزئیات بک‌آپ', $text, $keyboard);
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
        $this->show($bot, '➕ تهیه بک‌آپ جدید',
            'بک‌آپ دیتابیس، پیکربندی Remnawave و گواهی‌ها روی سرور RW ساخته و با Restic رمزنگاری می‌شود. تأیید می‌کنی؟',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ شروع', callback_data: 'admin:rwbackup:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:rwbackup:list:1')));
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
            $this->show($bot, '⏳ بک‌آپ آغاز شد',
                'شناسه: '.TelegramUi::code($id)."\nعملیات روی سرور در پس‌زمینه اجرا می‌شود. برای مشاهده نتیجه لیست را تازه‌سازی کن.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 مشاهده وضعیت', callback_data: 'admin:rwbackup:view:'.$id))
                    ->addRow(InlineKeyboardButton::make('◀️ لیست', callback_data: 'admin:rwbackup:list:1')));
        } catch (Throwable) {
            $this->show($bot, '⚠️ شروع ناموفق', 'عملیات آغاز نشد یا بک‌آپ دیگری در حال اجراست.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ لیست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    public function download(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null || ! RwBackupSsh::validId($id) || config('rw_backup.enabled') !== true) {
            return;
        }
        try {
            $this->backup->info($id);
            $token = bin2hex(random_bytes(32));
            Cache::put('rwbackup:download:'.hash('sha256', $token), [
                'userId' => $root['userId'], 'telegramId' => $root['telegramId'], 'id' => $id,
            ], now()->addMinutes(10));

            if ($bot->isCallbackQuery()) {
                TelegramUi::answerCallback($bot);
            }
            $bot->sendMessage(
                text: "🔐 لینک دانلود یک‌بارمصرف تا ۱۰ دقیقه معتبر است.\nفایل بک‌آپ محرمانه است؛ لینک را برای دیگران ارسال نکن.",
                reply_markup: InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                    '⬇️ دانلود فایل رمزنگاری‌شده',
                    url: route('rw-backup.download', ['token' => $token]),
                )),
            );
        } catch (Throwable) {
            $this->show($bot, '⚠️ دانلود', 'ساخت لینک دانلود ممکن نیست.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ لیست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    public function prepareDelete(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null || ! RwBackupSsh::validId($id) || config('rw_backup.enabled') !== true) {
            return;
        }
        try {
            $this->backup->info($id);
            $token = bin2hex(random_bytes(12));
            Cache::put(self::tokenKey('delete', $root['userId'], $token), $id, now()->addMinutes(3));
            $this->show($bot, '🗑️ حذف بک‌آپ',
                'آیا حذف دائمی '.TelegramUi::code($id)." را تأیید می‌کنی؟\nاین عملیات قابل بازگشت نیست. آخرین نسخه سالم قابل حذف نخواهد بود.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🗑️ تأیید حذف', callback_data: 'admin:rwbackup:remove:'.$token))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:rwbackup:view:'.$id)));
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
        if (! is_string($id) || ! RwBackupSsh::validId($id)) {
            $this->listing($bot);

            return;
        }
        try {
            $this->backup->delete($id);
            $this->show($bot, '✅ نسخه حذف شد', 'فایل انتخابی از سرور پاک شد.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ لیست', callback_data: 'admin:rwbackup:list:1')));
        } catch (Throwable) {
            $this->show($bot, '⚠️ حذف انجام نشد',
                'فایل در حال استفاده است، یا آخرین نسخه سالم است، یا ارتباط قطع شده.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ لیست', callback_data: 'admin:rwbackup:list:1')));
        }
    }

    private static function tokenKey(string $action, int $userId, string $token): string
    {
        return 'rwbackup:'.$action.':'.$userId.':'.$token;
    }

    private static function label(string $state): string
    {
        return match ($state) {
            'ready' => '✅ آماده', 'running' => '⏳ در حال تهیه', default => '🔴 ناموفق',
        };
    }

    private static function size(int $bytes): string
    {
        return number_format($bytes / 1048576, 1).' MB';
    }

    private static function date(int $timestamp): string
    {
        return Carbon::createFromTimestamp($timestamp, 'UTC')->setTimezone('Asia/Tehran')->format('Y/m/d H:i');
    }

    private function show(Nutgram $bot, string $title, string $detail, InlineKeyboardMarkup $keyboard, ?string $table = null): void
    {
        $html = '<h2>'.TelegramUi::escape($title).'</h2>'
            .($table ?? '').'<p>'.TelegramUi::escape(strip_tags($detail)).'</p>';
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
