<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Sales\Application\ManageAnnouncements;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

final readonly class AdminAnnouncementsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAnnouncements $announcements,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $page = '1'): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $number = self::positiveId($page) ?? 1;
        $result = $this->announcements->listing($root, min($number, 10000));
        $rows = '';
        $plain = ['<b>📢 مدیریت اعلانات</b>', 'برای مشاهده یا ویرایش، یک اطلاعیه انتخاب کنید.', ''];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($result['items'] as $row) {
            $status = self::status((string) $row->status);
            $title = TelegramUi::escape((string) $row->title);
            $rows .= '<tr><td>'.$title.'</td><td>'.$status.'</td></tr>';
            $plain[] = '#'.$row->id.' · '.$title.' — '.$status;
            $keyboard->addRow(InlineKeyboardButton::make(
                '#'.$row->id.' · '.mb_substr((string) $row->title, 0, 28),
                callback_data: 'admin:ann:view:'.$row->id,
            ));
        }
        if ($result['items'] === []) {
            $rows = '<tr><td>هنوز اطلاعیه‌ای ثبت نشده است.</td></tr>';
            $plain[] = 'هنوز اطلاعیه‌ای ثبت نشده است.';
        }
        $plain[] = 'صفحه '.$result['page'].' از '.$result['pages'];
        $rich = '<h3>📢 مدیریت اعلانات</h3>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .'<footer>صفحه '.$result['page'].' از '.$result['pages'].'</footer>';

        if ($result['page'] > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'admin:ann:list:'.($result['page'] - 1)));
        }
        if ($result['page'] < $result['pages']) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'admin:ann:list:'.($result['page'] + 1)));
        }
        $keyboard->addRow(InlineKeyboardButton::make('➕ ثبت اطلاعیه', callback_data: 'admin:ann:new', style: ButtonStyle::SUCCESS));
        $keyboard->addRow(InlineKeyboardButton::make('📨 ارسال برای نمایندگان', callback_data: 'admin:ann:reseller:new'));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ کنترل پنل', callback_data: 'menu:admin:control'));

        $this->render($bot, implode("\n", $plain), $keyboard, $rich);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $number = self::positiveId($id);
        $row = $number === null ? null : $this->announcements->find($root, $number);
        if ($row === null) {
            $this->notice($bot, 'این اطلاعیه دیگر در دسترس نیست.');

            return;
        }
        $status = self::status((string) $row->status);
        $title = TelegramUi::escape((string) $row->title);
        $body = TelegramUi::escape((string) $row->body);
        $date = $row->published_at === null ? 'منتشر نشده' : self::date((string) $row->published_at);
        $plain = '<b>📢 '.$title.'</b>'."\n".'وضعیت: '.$status."\n".'آخرین انتشار: '.$date."\n\n".$body;
        $rich = '<h3>📢 '.$title.'</h3><table bordered striped compact>'
            .'<tr><td>وضعیت</td><td>'.$status.'</td></tr>'
            .'<tr><td>آخرین انتشار</td><td>'.$date.'</td></tr></table>'
            .'<p>'.str_replace("\n", '</p><p>', $body).'</p>';
        $keyboard = InlineKeyboardMarkup::make();
        if ($row->status === 'reseller_sent') {
            $stats = $this->announcements->resellerDeliveryStats($root, (int) $row->id);
            $summary = "ارسال موفق: {$stats['sent']} | در صف: {$stats['pending']}"
                ." | ناموفق: {$stats['failed']} | نامشخص: {$stats['uncertain']}";
            $plain .= "\n\n".$summary;
            $rich .= '<p>'.TelegramUi::escape($summary).'</p>';
        } else {
            $keyboard->addRow(
                InlineKeyboardButton::make('✏️ عنوان', callback_data: 'admin:ann:edit:'.$row->id.':title'),
                InlineKeyboardButton::make('📝 متن', callback_data: 'admin:ann:edit:'.$row->id.':body'),
            )->addRow(InlineKeyboardButton::make(
                $row->status === 'published' ? '⏸️ لغو انتشار' : '✅ انتشار اطلاعیه',
                callback_data: 'admin:ann:prepare:'.$row->id.':'.($row->status === 'published' ? 'unpublish' : 'publish'),
                style: $row->status === 'published' ? ButtonStyle::DANGER : ButtonStyle::SUCCESS,
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1'));

        $this->render($bot, $plain, $keyboard, $rich);
    }

    public function begin(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        $this->retireCompeting($root, (int) $this->private->id($bot));
        Cache::put($this->draftKey($root), ['mode' => 'new', 'step' => 'title'], now()->addMinutes(10));
        $this->notice($bot, "➕ ثبت اطلاعیه\n\nعنوان اطلاعیه را ارسال کنید (حداکثر ۱۲۰ نویسه).", $this->cancelKeyboard());
    }

    public function beginReseller(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }

        $this->retireCompeting($root, (int) $this->private->id($bot));
        Cache::put($this->draftKey($root), ['mode' => 'reseller', 'step' => 'title'], now()->addMinutes(10));
        $this->notice($bot, "📨 ارسال اطلاعیه برای نمایندگان\n\nعنوان کوتاه اطلاعیه را ارسال کنید (حداکثر ۱۲۰ نویسه).",
            $this->cancelKeyboard());
    }

    public function edit(Nutgram $bot, string $id, string $field): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $number = self::positiveId($id);
        if (! in_array($field, ['title', 'body'], true) || $number === null) {
            $this->notice($bot, 'درخواست ویرایش معتبر نیست.');

            return;
        }
        $row = $this->announcements->find($root, $number);
        if ($row === null || $row->status === 'reseller_sent') {
            $this->notice($bot, 'این اطلاعیه قابل ویرایش نیست.');

            return;
        }

        $this->retireCompeting($root, (int) $this->private->id($bot));
        Cache::put($this->draftKey($root), [
            'mode' => 'edit', 'step' => $field, 'id' => $number,
            'revision' => (int) $row->revision,
            'title' => (string) $row->title, 'body' => (string) $row->body,
        ], now()->addMinutes(10));
        $this->notice($bot, $field === 'title'
            ? '✏️ عنوان جدید را ارسال کنید (حداکثر ۱۲۰ نویسه).'
            : '📝 متن جدید اطلاعیه را ارسال کنید (حداکثر ۱۵۰۰ نویسه).',
            $this->cancelKeyboard());
    }

    public function text(Nutgram $bot, string $input): bool
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return false;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            return false;
        }
        $key = $this->draftKey($actor->userId);
        $draft = Cache::get($key);
        if (! is_array($draft) || ! in_array($draft['step'] ?? null, ['title', 'body'], true)) {
            return false;
        }
        if (! $actor->canManageRoles()) {
            Cache::forget($key);
            $this->menus->denyAccess($bot);

            return true;
        }
        if ($this->hasCompeting($actor->userId, $telegramId)) {
            Cache::forget($key);

            return false;
        }

        $step = $draft['step'];
        $value = trim(str_replace(["\r\n", "\r"], "\n", $input));
        if ($step === 'title') {
            $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        }
        if ($value === '' || str_starts_with($value, '/') || mb_strlen($value) > ($step === 'title' ? 120 : 1500)) {
            $this->notice($bot, $step === 'title'
                ? 'عنوان باید بین ۱ تا ۱۲۰ نویسه باشد. دوباره ارسال کنید.'
                : 'متن باید بین ۱ تا ۱۵۰۰ نویسه باشد. دوباره ارسال کنید.',
                $this->cancelKeyboard());

            return true;
        }

        $draft[$step] = $value;
        if (in_array($draft['mode'], ['new', 'reseller'], true) && $step === 'title') {
            $draft['step'] = 'body';
            Cache::put($key, $draft, now()->addMinutes(10));
            $this->notice($bot, "✅ عنوان ثبت شد.\n\nاکنون متن اطلاعیه را ارسال کنید (حداکثر ۱۵۰۰ نویسه).",
                $this->cancelKeyboard());

            return true;
        }

        $draft['step'] = 'preview';
        $draft['token'] = bin2hex(random_bytes(12));
        Cache::put($key, $draft, now()->addMinutes(10));
        $this->preview($bot, $draft);

        return true;
    }

    public function save(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $draft = $this->consume($this->draftKey($root), $token, 'preview');
        if ($draft === null) {
            $this->notice($bot, 'این تأیید دیگر معتبر نیست. ثبت اطلاعیه را دوباره شروع کنید.');

            return;
        }
        try {
            if ($draft['mode'] === 'new') {
                $id = $this->announcements->create($root, $draft['title'], $draft['body']);
            } else {
                $id = (int) $draft['id'];
                $this->announcements->update($root, $id, (int) $draft['revision'], $draft['title'], $draft['body']);
            }
            $this->notice($bot, '✅ تغییرات ذخیره شد.', InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('مشاهده اطلاعیه', callback_data: 'admin:ann:view:'.$id))
                ->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1')));
        } catch (ValidationException) {
            $this->notice($bot, 'ثبت ممکن نشد؛ اطلاعیه احتمالاً تغییر کرده است. دوباره بررسی کنید.');
        }
    }

    public function prepareReseller(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $key = $this->draftKey($root);
        $draft = Cache::get($key);
        if (! is_array($draft) || ($draft['mode'] ?? null) !== 'reseller'
            || ($draft['step'] ?? null) !== 'preview'
            || ! is_string($draft['token'] ?? null)
            || ! hash_equals($draft['token'], $token)) {
            $this->notice($bot, 'پیش‌نمایش منقضی شده است.');

            return;
        }

        $count = $this->announcements->resellerRecipientCount($root);
        $draft['step'] = 'confirm-reseller';
        Cache::put($key, $draft, now()->addMinutes(3));
        $this->notice($bot, "📨 تأیید ارسال به نمایندگان\n\n"
            ."عنوان: ".TelegramUi::escape((string) $draft['title'])
            ."\nمخاطبان قابل‌دسترسی در بات فروش: {$count} نفر"
            ."\n\nپس از تأیید، ارسال در صف قرار می‌گیرد و قابل برگشت نیست."
            ."\nاین اطلاعیه برای کاربران عادی منتشر نمی‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید و ارسال به نمایندگان',
                    callback_data: 'admin:ann:reseller:commit:'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ann:cancel')));
    }

    public function commitReseller(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $draft = $this->consume($this->draftKey($root), $token, 'confirm-reseller');
        if ($draft === null || ($draft['mode'] ?? null) !== 'reseller') {
            $this->notice($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.');

            return;
        }
        try {
            $result = $this->announcements->sendToResellers(
                $root, (string) $draft['title'], (string) $draft['body'],
            );
            $this->notice($bot, "✅ اطلاعیه در صف ارسال برای {$result['recipients']} نماینده قرار گرفت."
                ."\nپیام‌ها از طریق بات فروش ارسال می‌شوند.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('📋 وضعیت ارسال',
                        callback_data: 'admin:ann:view:'.$result['id']))
                    ->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1')));
        } catch (ValidationException $exception) {
            $this->notice($bot, $exception->validator->errors()->first('announcement')
                ?: 'ارسال ممکن نشد. وضعیت بات فروش و اعلان‌ها را بررسی کنید.');
        }
    }

    public function prepareNew(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $key = $this->draftKey($root);
        $draft = Cache::get($key);
        if (! is_array($draft) || ($draft['mode'] ?? null) !== 'new'
            || ($draft['step'] ?? null) !== 'preview'
            || ! hash_equals((string) ($draft['token'] ?? ''), $token)) {
            $this->notice($bot, 'پیش‌نمایش منقضی شده است.');

            return;
        }
        $draft['step'] = 'confirm-new';
        Cache::put($key, $draft, now()->addMinutes(3));
        $this->notice($bot, "📢 انتشار اطلاعیه\n\nبا تأیید، وضعیت این اطلاعیه به منتشرشده تغییر می‌کند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید انتشار', callback_data: 'admin:ann:commit:'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ann:cancel')));
    }

    public function commitNew(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $draft = $this->consume($this->draftKey($root), $token, 'confirm-new');
        if ($draft === null || ($draft['mode'] ?? null) !== 'new') {
            $this->notice($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.');

            return;
        }
        try {
            $id = $this->announcements->create($root, $draft['title'], $draft['body'], true);
            $this->notice($bot, '✅ اطلاعیه منتشر شد.', InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('مشاهده اطلاعیه', callback_data: 'admin:ann:view:'.$id))
                ->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1')));
        } catch (ValidationException) {
            $this->notice($bot, 'انتشار ممکن نشد. اطلاعیه را دوباره بررسی کنید.');
        }
    }

    public function prepare(Nutgram $bot, string $id, string $action): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $number = self::positiveId($id);
        if ($number === null || ! in_array($action, ['publish', 'unpublish'], true)) {
            $this->notice($bot, 'درخواست معتبر نیست.');

            return;
        }
        $row = $this->announcements->find($root, $number);
        if ($row === null || ($action === 'publish' && $row->status !== 'draft')
            || ($action === 'unpublish' && $row->status !== 'published')) {
            $this->notice($bot, 'وضعیت اطلاعیه تغییر کرده است. دوباره بررسی کنید.');

            return;
        }
        $token = bin2hex(random_bytes(12));
        Cache::put($this->transitionKey($root), [
            'id' => $number, 'revision' => (int) $row->revision, 'action' => $action, 'token' => $token,
        ], now()->addMinutes(3));
        $message = $action === 'publish'
            ? "✅ انتشار اطلاعیه\n\nپس از تأیید، اطلاعیه در فهرست موارد منتشرشده ثبت می‌شود."
            : "⏸️ لغو انتشار\n\nاین اطلاعیه از فهرست مشتریان پنهان می‌شود؛ متن آن حفظ خواهد شد.";
        $this->notice($bot, $message, InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                $action === 'publish' ? '✅ تأیید انتشار' : '⏸️ تأیید لغو انتشار',
                callback_data: 'admin:ann:apply:'.$token,
                style: $action === 'publish' ? ButtonStyle::SUCCESS : ButtonStyle::DANGER,
            ))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ann:view:'.$number)));
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $draft = $this->consume($this->transitionKey($root), $token);
        if ($draft === null) {
            $this->notice($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.');

            return;
        }
        try {
            $this->announcements->changeStatus($root, (int) $draft['id'], (int) $draft['revision'], $draft['action']);
            $this->notice($bot, $draft['action'] === 'publish' ? '✅ اطلاعیه منتشر شد.' : '✅ انتشار اطلاعیه لغو شد.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('مشاهده اطلاعیه', callback_data: 'admin:ann:view:'.$draft['id']))
                    ->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1')));
        } catch (ValidationException) {
            $this->notice($bot, 'وضعیت اطلاعیه تغییر کرده است. دوباره بررسی کنید.');
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget($this->draftKey($root));
        Cache::forget($this->transitionKey($root));
        $this->browse($bot);
    }

    /** @param array<string, mixed> $draft */
    private function preview(Nutgram $bot, array $draft): void
    {
        $title = TelegramUi::escape($draft['title']);
        $body = TelegramUi::escape($draft['body']);
        $plain = '<b>📢 پیش‌نمایش اطلاعیه</b>'."\n\n".'<b>'.$title.'</b>'."\n\n".$body;
        $rich = '<h3>📢 پیش‌نمایش اطلاعیه</h3><h4>'.$title.'</h4>'
            .'<p>'.str_replace("\n", '</p><p>', $body).'</p>';
        $keyboard = InlineKeyboardMarkup::make();
        if ($draft['mode'] === 'reseller') {
            $plain .= "\n\nمخاطب: فقط نمایندگان فعال در بات فروش";
            $rich .= '<p><b>مخاطب: فقط نمایندگان فعال در بات فروش</b></p>';
            $keyboard->addRow(InlineKeyboardButton::make('📨 ارسال برای نمایندگان',
                callback_data: 'admin:ann:reseller:prepare:'.$draft['token'], style: ButtonStyle::SUCCESS));
        } else {
            $keyboard->addRow(InlineKeyboardButton::make(
                $draft['mode'] === 'new' ? '💾 ذخیره پیش‌نویس' : '✅ ثبت تغییرات',
                callback_data: 'admin:ann:save:'.$draft['token'],
                style: ButtonStyle::SUCCESS,
            ));
            if ($draft['mode'] === 'new') {
                $keyboard->addRow(InlineKeyboardButton::make('📢 انتشار اطلاعیه',
                    callback_data: 'admin:ann:prepare-new:'.$draft['token']));
            }
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ann:cancel'));
        $this->render($bot, $plain, $keyboard, $rich);
    }

    private function root(Nutgram $bot): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null || TelegramBotContext::current()->key !== TelegramBotContext::ADMIN) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function draftKey(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('admin:ann:draft:'.$root);
    }

    private function transitionKey(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('admin:ann:transition:'.$root);
    }

    /** @return array<string, mixed>|null */
    private function consume(string $key, string $token, ?string $step = null): ?array
    {
        if (! preg_match('/\A[a-f0-9]{24}\z/D', $token)) {
            return null;
        }

        return Cache::lock($key.':lock', 10)->block(2, static function () use ($key, $token, $step): ?array {
            $draft = Cache::get($key);
            if (! is_array($draft) || ! is_string($draft['token'] ?? null)
                || ! hash_equals($draft['token'], $token)
                || ($step !== null && ($draft['step'] ?? null) !== $step)) {
                return null;
            }
            Cache::forget($key);

            return $draft;
        });
    }

    private function retireCompeting(int $root, int $telegramId): void
    {
        foreach (self::competingKeys($root, $telegramId) as $key) {
            Cache::forget($key);
        }
    }

    private function hasCompeting(int $root, int $telegramId): bool
    {
        foreach (self::competingKeys($root, $telegramId) as $key) {
            if (Cache::has($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function competingKeys(int $root, int $telegramId): array
    {
        $prefixes = [
            'nw01:draft:', 'node01:draft:', 'node02:draft:', 'node03:draft:',
            'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:',
            'telegram:finance:wallet:', 'telegram:bankreview:reference:',
            'telegram:planadmin:draft:', 'telegram:productadmin:draft:',
            'cf02:form:', 'admin:ops:draft:', 'tg:users:search-prompt:',
        ];
        $keys = array_map(
            static fn (string $prefix): string => TelegramBotContext::current()->cacheKey($prefix.$root),
            $prefixes,
        );
        $keys[] = 'admin:accounting:draft:'.$root;
        $keys[] = 'admin:sales-welcome:draft:'.$telegramId;
        $keys[] = 'nw03:hostDraft:'.$root;
        $keys[] = 'nw03:confirm:'.$root;

        return $keys;
    }

    private static function positiveId(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,8}\z/D', $value) === 1 ? (int) $value : null;
    }

    private static function status(string $status): string
    {
        return match ($status) {
            'published' => '🟢 منتشرشده',
            'reseller_sent' => '📨 اطلاعیه نمایندگان',
            default => '📝 پیش‌نویس',
        };
    }

    private static function date(string $value): string
    {
        return Carbon::parse($value)->timezone('Asia/Tehran')->format('Y/m/d H:i');
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'admin:ann:cancel'));
    }

    private function notice(Nutgram $bot, string $message, ?InlineKeyboardMarkup $keyboard = null): void
    {
        $this->render($bot, $message, $keyboard ?? InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ اطلاعیه‌ها', callback_data: 'admin:ann:list:1')));
    }

    private function render(Nutgram $bot, string $plain, InlineKeyboardMarkup $keyboard, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);

                return;
            } catch (TelegramException) {
                // Native rendering uses exactly the same authorized data and controls.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $plain, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $plain, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
