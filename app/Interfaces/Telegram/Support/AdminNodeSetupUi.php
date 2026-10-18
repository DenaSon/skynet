<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\CompleteNodeSetup;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use App\Modules\Provisioning\Application\ManageNodeInstallation;
use App\Modules\Provisioning\Application\NodeSetupReadiness;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** NW-01: private, default-off preparation; NO provisioning or host writes. */
final readonly class AdminNodeSetupUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminNodes $nodes,
        private NodeSetupReadiness $ssh,
        private ManageNodeInstallation $installer,
        private CompleteNodeSetup $completion,
        private MenuRenderer $menus,
    ) {}

    public function begin(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        if (! $this->enabled()) {
            $this->render($bot, 'راه‌اندازی جدید فعلاً غیرفعال است.', $this->back());

            return;
        }
        // Keep existing wizards intact; retire their stale text drafts.
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:', 'host01:draft:', 'host02:draft:', 'cfg02:draft:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$id));
        }
        Cache::forget('nw02:ready:'.$id);
        Cache::forget('nw03:hostDraft:'.$id);
        Cache::forget('nw03:confirm:'.$id);
        $this->save($id, ['stage' => 'identity']);
        $this->render($bot, "🖥️ راه‌اندازی سرور — ۱ از ۵\n\nنام و IP عمومی سرور را وارد کنید:\nGermany-02 | 203.0.113.10", $this->cancelButton());
    }

    public function text(Nutgram $bot, string $value): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $id = $this->root($bot, false);
        if ($id === null) {
            return false;
        }
        if ($this->hostPortInput($bot, $id, $value)) {
            return true;
        }
        $draft = $this->draft($id);
        $stage = $draft['stage'] ?? null;
        if (! in_array($stage, ['identity', 'port', 'username', 'password'], true)) {
            return false;
        }

        if ($stage === 'password') {
            // If deletion fails, do not use or cache the secret.
            try {
                $messageId = $bot->message()?->message_id;
                $chatId = $bot->chat()?->id;
                if (! is_int($messageId) || ! is_int($chatId)) {
                    throw new \RuntimeException('Missing message identity');
                }
                if ($bot->deleteMessage(chat_id: $chatId, message_id: $messageId) !== true) {
                    throw new \RuntimeException('Telegram deletion not confirmed');
                }
            } catch (Throwable) {
                Cache::forget($this->key($id));
                $this->render($bot, 'حذف پیام رمز ممکن نشد؛ این تلاش لغو شد. رمز را تغییر دهید.', $this->back());

                return true;
            }
            if ($value === '' || strlen($value) > 256) {
                $this->render($bot, 'رمز معتبر نیست. دوباره تلاش کنید.', $this->cancelButton());

                return true;
            }
            try {
                $draft['profiles'] = $this->nodes->profiles($id);
                $draft['secret'] = Crypt::encryptString($value);
            } catch (Throwable) {
                Cache::forget($this->key($id));
                $this->render($bot, 'دریافت پروفایل‌ها ممکن نشد.', $this->back());

                return true;
            }
            $draft['stage'] = 'profile';
            $this->save($id, $draft);
            $this->choices($bot, 'پروفایل اتصال را انتخاب کنید.', $draft['profiles'], 'nw1:profile:');

            return true;
        }

        if ($stage === 'identity') {
            $parts = explode('|', $value);
            if (count($parts) !== 2 || strlen($value) > 330) {
                $this->render($bot, 'نام و IP را با علامت | جدا کنید.', $this->cancelButton());

                return true;
            }
            $name = trim($parts[0]);
            $ip = trim($parts[1]);
            try {
                $this->nodes->validateIdentity($id, $name, $ip);
                $this->ssh->validateTarget($id, $ip, 22);
                foreach ($this->nodes->all($id) as $node) {
                    if (mb_strtolower($node['name']) === mb_strtolower($name)) {
                        throw new \RuntimeException('Duplicate name.');
                    }
                }
            } catch (Throwable) {
                $this->render($bot, 'IP عمومی یا نام سرور نامعتبر است، یا نام تکراری است.', $this->cancelButton());

                return true;
            }
            $draft['name'] = $name;
            $draft['ip'] = $ip;
            $draft['stage'] = 'port';
            $next = "🖥️ راه‌اندازی سرور — ۲ از ۵\n\nپورت SSH را وارد کنید (معمولاً 22).";
        } elseif ($stage === 'port') {
            $port = ctype_digit($value) && strlen($value) <= 5 ? (int) $value : 0;
            if ($port < 1 || $port > 65535) {
                $this->render($bot, 'پورت باید بین ۱ تا ۶۵۵۳۵ باشد.', $this->cancelButton());

                return true;
            }
            $draft['port'] = $port;
            $draft['stage'] = 'username';
            $next = "🖥️ راه‌اندازی سرور — ۳ از ۵\n\nنام کاربری SSH را وارد کنید (مثلاً root).";
        } else {
            if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/iD', $value) !== 1) {
                $this->render($bot, 'نام کاربری معتبر نیست.', $this->cancelButton());

                return true;
            }
            $draft['username'] = $value;
            $draft['stage'] = 'password';
            $next = "🔐 راه‌اندازی سرور — ۴ از ۵\n\nرمز SSH را در همین گفتگوی خصوصی ارسال کنید.\nپیام رمز پس از دریافت حذف می‌شود.";
        }
        $this->save($id, $draft);
        $this->render($bot, $next, $this->cancelButton());

        return true;
    }

    public function profile(Nutgram $bot, string $index): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        $profile = $this->select($draft, 'profile', 'profiles', $index);
        if ($profile === null) {
            $this->expired($bot);

            return;
        }
        try {
            $draft['inbounds'] = $this->nodes->inbounds($id, $profile['uuid']);
        } catch (Throwable) {
            $this->render($bot, 'دریافت ورودی‌ها ممکن نشد.', $this->cancelButton());

            return;
        }
        $draft['profile'] = $profile;
        $draft['stage'] = 'inbound';
        $this->save($id, $draft);
        $this->choices($bot, 'ورودی اتصال را انتخاب کنید.', $draft['inbounds'], 'nw1:inbound:');
    }

    public function inbound(Nutgram $bot, string $index): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        $inbound = $this->select($draft, 'inbound', 'inbounds', $index);
        if ($inbound === null) {
            $this->expired($bot);

            return;
        }
        $draft['inbound'] = $inbound;
        $draft['token'] = bin2hex(random_bytes(16));
        $draft['stage'] = 'preview';
        $this->save($id, $draft);
        $this->render($bot, "🖥️ بررسی اطلاعات — ۵ از ۵\n\n"
            .'نام: '.$draft['name']."\nIP: ".$draft['ip']."\nپورت: ".$draft['port']."\n"
            .'کاربر: '.$draft['username']."\nپروفایل: ".$draft['profile']['name']."\nورودی: ".$inbound['name']
            ."\n\nدر این مرحله هیچ تغییری روی سرور انجام نمی‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🔎 بررسی هویت سرور', callback_data: 'nw1:scan:'.$draft['token']))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
    }

    public function scan(Nutgram $bot, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        if (! $this->valid($draft, $token, 'preview')) {
            $this->expired($bot);

            return;
        }
        try {
            $draft['host'] = $this->ssh->scan($id, $draft['ip'], $draft['port']);
        } catch (Throwable) {
            $this->render($bot, 'اتصال SSH برقرار نشد؛ IP و پورت را بررسی کنید.', $this->cancelButton());

            return;
        }
        $draft['stage'] = 'trust';
        $this->save($id, $draft);
        $this->render($bot, "🔐 تأیید هویت سرور\n\nاثر انگشت:\n".$draft['host']['fingerprint']
            ."\n\nبا اثر انگشت سرور در کنسول شرکت میزبان مقایسه کنید؛ فقط در صورت تطابق تأیید کنید.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ هویت سرور تأیید است', callback_data: 'nw1:trust:'.$token))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
    }

    public function trust(Nutgram $bot, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        if ($this->draft($id) === null) {
            $this->expired($bot);

            return;
        }
        $key = $this->key($id);
        $draft = Cache::lock($key.':lock', 20)->block(2, static function () use ($key, $token): mixed {
            $state = Cache::get($key);
            if (! is_array($state) || ($state['stage'] ?? null) !== 'trust'
                || ! is_string($state['token'] ?? null) || ! hash_equals($state['token'], $token)) {
                return null;
            }
            Cache::forget($key);

            return $state;
        });
        if (! is_array($draft) || ! $this->enabled()) {
            $this->expired($bot);

            return;
        }
        try {
            $this->nodes->validateIdentity($id, $draft['name'], $draft['ip']);
            if (! in_array($draft['profile']['uuid'], array_column($this->nodes->profiles($id), 'uuid'), true)
                || ! in_array($draft['inbound']['uuid'], array_column($this->nodes->inbounds($id, $draft['profile']['uuid']), 'uuid'), true)) {
                throw new \RuntimeException('Profile changed.');
            }
            $password = Crypt::decryptString($draft['secret']);
            $os = $this->ssh->check($id, $draft['ip'], $draft['port'], $draft['username'], $password, $draft['host']['knownHost']);
            unset($password);
            if (config('services.remnawave.node_ssh_install_enabled') === true) {
                $setupToken = bin2hex(random_bytes(16));
                $draft['installToken'] = $setupToken;
                Cache::put('nw02:ready:'.$id, $draft, now()->addMinutes(10));
                $this->render($bot,
                    "✅ اتصال امن بررسی شد.\n\nنام: ".$draft['name']."\nسیستم‌عامل: ".$os
                    ."\n\nنصب نرم‌افزار روی این سرور فقط پس از تأیید شما شروع می‌شود.",
                    InlineKeyboardMarkup::make()
                        ->addRow(InlineKeyboardButton::make('🚀 شروع نصب روی سرور', callback_data: 'nw2:start:'.$setupToken))
                        ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
            } else {
                $this->render($bot, "✅ سرور آماده ادامه راه‌اندازی است.\n\nنام: ".$draft['name']
                    ."\nسیستم‌عامل: ".$os."\n\nدر این مرحله هیچ نرم‌افزاری نصب نشده است.", $this->back());
            }
        } catch (Throwable) {
            $this->render($bot, 'بررسی SSH موفق نبود؛ برای تلاش بعدی دوباره شروع کنید.', $this->back());
        }
    }

    /** Confirmed, single-use installation action, separate from read-only NW-01. */
    public function install(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! $this->enabled() || config('services.remnawave.node_ssh_install_enabled') !== true
            || preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->expired($bot);

            return;
        }

        try {
            $key = 'nw02:ready:'.$root;
            $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key, $token): mixed {
                $ready = Cache::get($key);
                if (! is_array($ready) || ! is_string($ready['installToken'] ?? null)
                    || ! hash_equals($ready['installToken'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $ready;
            });
            if (! is_array($state)) {
                $this->expired($bot);

                return;
            }
            $identifier = $this->installer->enqueue($root, $state);
            $this->render($bot, "🕒 درخواست نصب ثبت شد.\n\nنتیجه از بخش وضعیت نصب قابل پیگیری است.",
                $this->statusKeyboard($identifier));
        } catch (Throwable) {
            $this->render($bot, 'شروع نصب ممکن نشد یا برای این سرور درخواست دیگری ثبت شده است.', $this->back());
        }
    }

    public function installationStatus(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $status = $this->installer->status($root, $identifier);
        } catch (Throwable) {
            $this->render($bot, 'وضعیت نصب در دسترس نیست یا منقضی شده است.', $this->back());

            return;
        }
        $description = match ($status['state']) {
            'queued' => 'در صف آماده‌سازی',
            'launching', 'pending' => 'در حال آغاز عملیات',
            'started', 'installing' => 'در حال نصب نرم‌افزارها',
            'starting' => 'در حال راه‌اندازی سرویس',
            'running' => '✅ نرم‌افزار سرور نصب و اجرا شده است. اتصال به پنل در مرحله بعد انجام می‌شود.',
            'failed' => '❌ نصب موفق نبود. پیش از درخواست مجدد، سرور باید بررسی شود.',
            default => '⚠️ نتیجه عملیات مشخص نیست. پیش از تلاش مجدد وضعیت سرور را بررسی کنید.',
        };
        $keyboard = $this->statusKeyboard($identifier);
        if ($status['state'] === 'running'
            && config('services.remnawave.writes_enabled') === true
            && config('services.remnawave.node_ssh_install_enabled') === true) {
            $keyboard->addRow(InlineKeyboardButton::make('➡️ تکمیل اتصال سرور', callback_data: 'nw3:resume:'.$identifier));
        }
        $this->render($bot, "🖥️ وضعیت آماده‌سازی\n\nنام: ".$status['name']."\n".$description,
            $keyboard);
    }

    public function completionOverview(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget('nw03:hostDraft:'.$root);
        Cache::forget('nw03:confirm:'.$root);
        try {
            $step = $this->completion->progress($root, $identifier);
            $keyboard = InlineKeyboardMarkup::make();
            $message = '🖥️ تکمیل اتصال سرور';

            if ($step['nodeStage'] === 'ready') {
                $message .= "\n\nنرم‌افزار روی سرور اجرا شده است. ثبت این سرور در پنل نیاز به تأیید شما دارد.";
                $keyboard->addRow(InlineKeyboardButton::make('➕ ثبت سرور در پنل', callback_data: 'nw3:node:prepare:'.$identifier));
            } elseif ($step['nodeStage'] !== 'registered') {
                $message .= "\n\n⚠️ نتیجه ثبت سرور مشخص نیست. فهرست سرورها را در پنل بررسی کنید؛ ثبت مجدد خودکار انجام نمی‌شود.";
            } else {
                $node = $this->completion->nodeStatus($root, $identifier);
                if (! $node['connected'] || $node['disabled']) {
                    $message .= "\n\n⏳ سرور ثبت شده است، ولی هنوز اتصال فعال آن از پنل تأیید نشده است.";
                } elseif ($step['hostStage'] === 'ready') {
                    $message .= "\n\n✅ سرور به پنل متصل است. اکنون می‌توانید هاست را ایجاد کنید.";
                    $keyboard->addRow(InlineKeyboardButton::make('➕ ساخت هاست', callback_data: 'nw3:host:begin:'.$identifier));
                } elseif ($step['hostStage'] !== 'created') {
                    $message .= "\n\n⚠️ نتیجه ساخت هاست مشخص نیست؛ فهرست هاست‌ها را بررسی کنید.";
                } else {
                    $host = $this->completion->hostStatus($root, $identifier);
                    $message .= $host['verified']
                        ? "\n\n✅ هاست به‌صورت غیرفعال ساخته و مشخصات آن تأیید شد.\nقبل از فعال‌سازی، دسترسی گروه اشتراک و اتصال کلاینت را بررسی کنید."
                        : "\n\n⚠️ هاست ساخته شده، ولی تمام مشخصات ارتباطی آن از پنل تأیید نشده است. آن را در بخش هاست‌ها بررسی کنید.";
                    $keyboard->addRow(InlineKeyboardButton::make('🌐 مشاهده هاست', callback_data: 'host1:view:'.$host['uuid']));
                }
            }

            $keyboard->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی', callback_data: 'nw3:resume:'.$identifier));
            $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1'));
            $this->render($bot, $message, $keyboard);
        } catch (Throwable) {
            $this->render($bot, 'بررسی وضعیت اتصال ممکن نشد. تنظیمات و وضعیت پنل را بررسی کنید.', $this->back());
        }
    }

    public function prepareNodeRegistration(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $review = $this->completion->previewNode($root, $identifier);
            $token = bin2hex(random_bytes(16));
            Cache::put('nw03:confirm:'.$root, [
                'kind' => 'node', 'id' => $identifier, 'token' => $token,
            ], now()->addMinutes(10));
            $this->render($bot, "➕ تأیید ثبت سرور\n\n"
                .'نام: '.$review['name']."\n"
                .'IP: '.$review['address']."\n\n"
                .'سرور در پنل ثبت می‌شود؛ هیچ کاربری جابه‌جا نخواهد شد.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ ثبت سرور', callback_data: 'nw3:node:apply:'.$token))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw3:resume:'.$identifier)));
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات سرور یا تنظیمات اتصال معتبر نیست، یا سرور قبلاً ثبت شده است.', $this->back());
        }
    }

    public function applyNodeRegistration(Nutgram $bot, string $token): void
    {
        $this->applyConfirmation($bot, $token, 'node');
    }

    public function beginHostCreation(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $step = $this->completion->progress($root, $identifier);
            $node = $this->completion->nodeStatus($root, $identifier);
            if ($step['hostStage'] !== 'ready' || ! $node['connected'] || $node['disabled']) {
                throw new \RuntimeException('Not ready');
            }
            Cache::put('nw03:hostDraft:'.$root, ['id' => $identifier], now()->addMinutes(10));
            $this->render($bot, "🌐 افزودن هاست\n\nپورت ورودی اتصال کاربران را ارسال کنید (مثلاً 1234).\nاین پورت باید با تنظیمات ورودی انتخاب‌شده در پنل یکسان باشد.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw3:resume:'.$identifier)));
        } catch (Throwable) {
            $this->render($bot, 'ابتدا اتصال فعال سرور را در پنل تأیید کنید.', $this->back());
        }
    }

    private function hostPortInput(Nutgram $bot, int $root, string $value): bool
    {
        $draft = Cache::get('nw03:hostDraft:'.$root);
        if (! is_array($draft) || ! is_string($draft['id'] ?? null)) {
            return false;
        }
        // Newer legacy Node/Host/Profile wizards always own their text input.
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:', 'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:'] as $prefix) {
            if (Cache::has(TelegramBotContext::current()->cacheKey($prefix.$root))) {
                Cache::forget('nw03:hostDraft:'.$root);

                return false;
            }
        }
        $identifier = $draft['id'];
        if (! ctype_digit($value) || strlen($value) > 5 || (int) $value < 1 || (int) $value > 65535) {
            $this->render($bot, 'لطفاً یک پورت معتبر بین ۱ تا ۶۵۵۳۵ وارد کنید.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw3:resume:'.$identifier)));

            return true;
        }
        try {
            $port = (int) $value;
            $review = $this->completion->previewHost($root, $identifier, $port);
            $token = bin2hex(random_bytes(16));
            Cache::forget('nw03:hostDraft:'.$root);
            Cache::put('nw03:confirm:'.$root, [
                'kind' => 'host', 'id' => $identifier, 'port' => $port, 'token' => $token,
            ], now()->addMinutes(10));
            $this->render($bot, "🌐 تأیید ساخت هاست\n\n"
                .'نام: '.$review['remark']."\nآدرس: ".$review['address']."\nپورت: ".$port
                ."\n\nهاست ابتدا غیرفعال ساخته می‌شود. فعال‌سازی به تأیید جداگانه نیاز دارد.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ ساخت هاست غیرفعال', callback_data: 'nw3:host:apply:'.$token))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw3:resume:'.$identifier)));
        } catch (Throwable) {
            Cache::forget('nw03:hostDraft:'.$root);
            $this->render($bot, 'تنظیمات هاست معتبر نیست یا هاست مشابهی وجود دارد. تنظیمات پنل را بررسی کنید.', $this->back());
        }

        return true;
    }

    public function applyHostCreation(Nutgram $bot, string $token): void
    {
        $this->applyConfirmation($bot, $token, 'host');
    }

    private function applyConfirmation(Nutgram $bot, string $token, string $kind): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->expired($bot);

            return;
        }
        $key = 'nw03:confirm:'.$root;
        try {
            $state = Cache::lock($key.':lock', 15)->block(1, static function () use ($key, $token, $kind): mixed {
                $data = Cache::get($key);
                if (! is_array($data) || ($data['kind'] ?? null) !== $kind
                    || ! is_string($data['token'] ?? null) || ! hash_equals($data['token'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $data;
            });
            if (! is_array($state)) {
                $this->expired($bot);

                return;
            }
            if ($kind === 'node') {
                $this->completion->registerNode($root, $state['id']);
            } else {
                $this->completion->createHost($root, $state['id'], $state['port']);
            }
            $this->completionOverview($bot, $state['id']);
        } catch (Throwable) {
            $this->render($bot, 'نتیجه عملیات تأیید نشد. پیش از هر تلاش جدید، فهرست سرورها یا هاست‌ها را بررسی کنید.', $this->back());
        }
    }

    private function statusKeyboard(string $identifier): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 بررسی وضعیت نصب', callback_data: 'nw2:status:'.$identifier))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1'));
    }

    public function cancel(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        Cache::forget($this->key($id));
        Cache::forget('nw02:ready:'.$id);
        $this->render($bot, 'راه‌اندازی لغو شد.', $this->back());
    }

    /** @param array<string,mixed>|null $draft
     * @return array<string,mixed>|null
     */
    private function select(?array $draft, string $stage, string $field, string $index): ?array
    {
        $i = ctype_digit($index) && strlen($index) <= 2 ? (int) $index : -1;

        return ($draft['stage'] ?? null) === $stage ? ($draft[$field][$i] ?? null) : null;
    }

    /** @param list<array{uuid:string,name:string}> $options */
    private function choices(Nutgram $bot, string $label, array $options, string $prefix): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        foreach ($options as $i => $option) {
            $keyboard->addRow(InlineKeyboardButton::make($option['name'], callback_data: $prefix.$i));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
        $this->render($bot, $label.($options === [] ? "\n\nموردی موجود نیست." : ''), $keyboard);
    }

    /** @param array<string,mixed>|null $draft */
    private function valid(?array $draft, string $token, string $stage): bool
    {
        return is_array($draft) && ($draft['stage'] ?? null) === $stage
            && preg_match('/\A[a-f0-9]{32}\z/D', $token) === 1
            && is_string($draft['token'] ?? null) && hash_equals($draft['token'], $token);
    }

    private function enabled(): bool
    {
        return config('services.remnawave.node_ssh_setup_enabled') === true;
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

    /** @return array<string,mixed>|null */
    private function draft(int $id): ?array
    {
        // A newer legacy wizard takes precedence over any stale SSH draft.
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:', 'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:'] as $prefix) {
            if (Cache::has(TelegramBotContext::current()->cacheKey($prefix.$id))) {
                Cache::forget($this->key($id));

                return null;
            }
        }
        $state = Cache::get($this->key($id));

        return is_array($state) ? $state : null;
    }

    /** @param array<string,mixed> $state */
    private function save(int $id, array $state): void
    {
        Cache::put($this->key($id), $state, now()->addMinutes(10));
    }

    private function key(int $id): string
    {
        return TelegramBotContext::current()->cacheKey('nw01:draft:'.$id);
    }

    private function cancelButton(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا تمام شده است.', $this->back());
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard);

            return;
        }
        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
