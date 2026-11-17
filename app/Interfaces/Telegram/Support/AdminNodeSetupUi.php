<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\CompleteNodeSetup;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use App\Modules\Provisioning\Application\ManageCloudflareDns;
use App\Modules\Provisioning\Application\ManageNodeInstallation;
use App\Modules\Provisioning\Application\NodeSetupReadiness;
use App\Modules\Provisioning\Application\ReplaceAdminNode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
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
        private ReplaceAdminNode $replacement,
        private ManageCloudflareDns $dns,
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
        $this->save($id, ['stage' => 'name']);
        $this->render($bot, "🖥️ راه‌اندازی سرور — گام ۱\n\n<b>نام سرور</b> را ارسال کنید.\nمثال: Germany-02", $this->cancelButton(false), true);
    }

    /** Begin an existing-Node replacement without creating or deleting a Node. */
    public function beginReplacement(Nutgram $bot, string $uuid): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        if (! $this->enabled() || config('services.remnawave.node_ssh_install_enabled') !== true
            || config('services.remnawave.writes_enabled') !== true) {
            $this->render($bot, 'جایگزینی سرور فعلاً غیرفعال است.', $this->back());

            return;
        }
        try {
            $target = $this->nodes->replacementSnapshot($id, $uuid);
            if ($target['disabled']) {
                throw new \RuntimeException('Disabled Node cannot be replaced.');
            }
            $profile = collect($this->nodes->profiles($id))->firstWhere('uuid', $target['profileUuid']);
            if (! is_array($profile)) {
                throw new \RuntimeException('Current profile is unavailable.');
            }
            $inbounds = $this->nodes->inbounds($id, $target['profileUuid']);
            $active = array_values(array_filter($inbounds,
                static fn (array $inbound): bool => in_array($inbound['uuid'], $target['inboundUuids'], true)));
            if (count($active) !== count($target['inboundUuids']) || $active === []) {
                throw new \RuntimeException('Current active inbounds are unavailable.');
            }
        } catch (Throwable) {
            $this->render($bot, 'مشخصات نود یا پروفایل‌های فعلی قابل بررسی نیستند. ابتدا وضعیت نود را بررسی کنید.', $this->back());

            return;
        }
        foreach (['node01:draft:', 'node02:draft:', 'node03:draft:', 'host01:draft:', 'host02:draft:', 'cfg02:draft:'] as $prefix) {
            Cache::forget(TelegramBotContext::current()->cacheKey($prefix.$id));
        }
        Cache::forget('nw02:ready:'.$id);
        Cache::forget('nw03:hostDraft:'.$id);
        Cache::forget('nw03:confirm:'.$id);
        Cache::forget('nw05:confirm:'.$id);
        $this->save($id, ['stage' => 'ip', 'name' => $target['name'], 'replacement' => $target,
            'profile' => $profile, 'inbounds' => $inbounds, 'activeInbounds' => $active]);
        $this->render($bot, "🔁 جایگزینی سرور — گام ۱\n\n<b>IP عمومی سرور جدید</b> را ارسال کنید.\n"
            .'نود فعلی: '.$target['name']."\nآدرس فعلی نود: ".$target['address']
            ."\n\nشناسه نود، پروفایل و ورودی‌های فعلی حفظ می‌شوند.", $this->cancelButton(false), true);
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
        if (! in_array($stage, ['name', 'ip', 'identity', 'dns_name', 'port', 'username', 'password'], true)) {
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
                $draft['secret'] = Crypt::encryptString($value);
                if (is_array($draft['replacement'] ?? null)) {
                    $current = $this->nodes->replacementSnapshot($id, $draft['replacement']['uuid']);
                    if (! $this->sameReplacementSnapshot($draft['replacement'], $current)) {
                        throw new \RuntimeException('Node changed during replacement setup.');
                    }
                    $draft['token'] = bin2hex(random_bytes(16));
                    $draft['stage'] = 'preview';
                    $this->save($id, $draft);
                    $this->previewPrompt($bot, $draft);

                    return true;
                }
                $draft['profiles'] = $this->nodes->profiles($id);
            } catch (Throwable) {
                Cache::forget($this->key($id));
                $this->render($bot, 'بررسی مشخصات نود یا دریافت پروفایل‌ها ممکن نشد.', $this->back());

                return true;
            }
            $draft['stage'] = 'profile';
            $this->save($id, $draft);
            $this->choices($bot, '<b>پروفایل اتصال</b> را انتخاب کنید.', $draft['profiles'], 'nw1:profile:');

            return true;
        }

        if ($stage === 'name') {
            $name = trim($value);
            if ($name === '' || mb_strlen($name) > 64 || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0) {
                $this->render($bot, 'نام سرور معتبر نیست. یک نام کوتاه ارسال کنید.', $this->cancelButton(false));

                return true;
            }
            $draft['name'] = $name;
            $draft['stage'] = 'ip';
            $this->save($id, $draft);
            $this->ipPrompt($bot);

            return true;
        }

        // Keep residual pre-deployment "name | IP" drafts compatible.
        if ($stage === 'ip') {
            $value = $draft['name'].' | '.$value;
        }
        if ($stage === 'identity' || $stage === 'ip') {
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
                    if (mb_strtolower($node['name']) === mb_strtolower($name)
                        && (! is_array($draft['replacement'] ?? null)
                            || strtolower($node['uuid']) !== strtolower($draft['replacement']['uuid']))) {
                        throw new \RuntimeException('Duplicate name.');
                    }
                }
            } catch (Throwable) {
                $this->render($bot, 'IP عمومی یا نام سرور نامعتبر است، یا نام تکراری است.', $this->cancelButton());

                return true;
            }
            $draft['name'] = $name;
            $draft['ip'] = $ip;
            if (! is_array($draft['replacement'] ?? null) && $this->dns->wizardEnabled()) {
                $draft['stage'] = 'dns_choice';
                $this->save($id, $draft);
                $this->dnsChoicePrompt($bot);

                return true;
            }
            $draft['stage'] = 'port';
            $next = "🖥️ اتصال به سرور\n\n<b>پورت SSH</b> را ارسال کنید (معمولاً 22).";
        } elseif ($stage === 'dns_name') {
            try {
                $draft['dnsName'] = $this->dns->hostname($id, $draft['dnsZone'], $value);
            } catch (Throwable) {
                $this->render($bot, 'زیردامنه معتبر نیست. مانند usa یا usa.denagate.ir وارد کنید.', $this->cancelButton());

                return true;
            }
            unset($draft['dnsZones'], $draft['dnsRecordExpected'], $draft['dnsRecords'],
                $draft['dnsRecordToken'], $draft['dnsRecordPage'], $draft['dnsRecordPages']);
            $draft['stage'] = 'port';
            $next = "🖥️ اتصال به سرور\n\n<b>پورت SSH</b> را ارسال کنید (معمولاً 22).";
        } elseif ($stage === 'port') {
            $port = ctype_digit($value) && strlen($value) <= 5 ? (int) $value : 0;
            if ($port < 1 || $port > 65535) {
                $this->render($bot, 'پورت باید بین ۱ تا ۶۵۵۳۵ باشد.', $this->portButtons());

                return true;
            }
            $draft['port'] = $port;
            $draft['stage'] = 'username';
            $next = "🖥️ اتصال به سرور\n\n<b>نام کاربری SSH</b> را ارسال کنید (برای نصب خودکار: root).";
        } else {
            if (preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/iD', $value) !== 1) {
                $this->render($bot, 'نام کاربری معتبر نیست.', $this->usernameButtons());

                return true;
            }
            $draft['username'] = $value;
            $draft['stage'] = 'password';
            $next = "🔐 رمز سرور\n\n<b>رمز SSH</b> را فقط در همین گفتگوی خصوصی بفرستید. پیام پس از دریافت حذف می‌شود؛ بعد از راه‌اندازی رمز را تغییر دهید.";
        }
        $this->save($id, $draft);
        $this->render($bot, $next, match ($draft['stage']) {
            'port' => $this->portButtons(),
            'username' => $this->usernameButtons(),
            default => $this->cancelButton(),
        }, true);

        return true;
    }

    public function dnsZones(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        if (($draft['stage'] ?? null) !== 'dns_choice' || ! $this->dns->wizardEnabled()) {
            $this->expired($bot);

            return;
        }
        try {
            $zones = $this->dns->zones($id);
            if ($zones === []) {
                throw new \RuntimeException('No available domains.');
            }
            $draft['dnsZones'] = $zones;
            $draft['stage'] = 'dns_zone';
            $this->save($id, $draft);
            $keyboard = InlineKeyboardMarkup::make();
            foreach ($zones as $index => $zone) {
                $keyboard->addRow(InlineKeyboardButton::make('🌐 '.$zone['name'], callback_data: 'nw1:dns:zone:'.$index, style: ButtonStyle::PRIMARY));
            }
            $keyboard->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه', callback_data: 'nw1:dns:skip'));
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'));
            $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
            $this->render($bot, 'دامنه اصلی را انتخاب کنید.', $keyboard);
        } catch (Throwable) {
            $this->render($bot, 'دریافت دامنه‌ها ممکن نشد. می‌توانید بدون دامنه ادامه دهید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 تلاش دوباره', callback_data: 'nw1:dns:zones', style: ButtonStyle::PRIMARY))
                    ->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه', callback_data: 'nw1:dns:skip'))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
        }
    }

    public function dnsZone(Nutgram $bot, string $index): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        $zone = $this->select($draft, 'dns_zone', 'dnsZones', $index);
        if ($zone === null) {
            $this->expired($bot);

            return;
        }
        $draft['dnsZone'] = $zone['name'];
        $draft['dnsZoneId'] = $zone['id'];
        $draft['stage'] = 'dns_records';
        unset($draft['dnsName'], $draft['dnsRecordExpected']);
        $this->save($id, $draft);
        $this->dnsRecords($bot, '1');
    }

    /** Show existing editable A records for the selected allowlisted Zone. */
    public function dnsRecords(Nutgram $bot, string $requested = '1'): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        $page = ctype_digit($requested) && strlen($requested) <= 3 ? (int) $requested : 0;
        if (($draft['stage'] ?? null) !== 'dns_records' || $page < 1 || $page > 100
            || ! is_string($draft['dnsZone'] ?? null) || ! is_string($draft['dnsZoneId'] ?? null)
            || ! $this->dns->wizardEnabled()) {
            $this->expired($bot);

            return;
        }

        try {
            $result = $this->dns->page($id, $draft['dnsZoneId'], $draft['dnsZone'], $page);
            if ($page > $result['pages']) {
                throw new \RuntimeException('Invalid DNS page.');
            }
            $records = array_values(array_filter(
                $result['items'],
                static fn (array $record): bool => ($record['type'] ?? null) === 'A'
                    && ($record['editable'] ?? null) === true
                    && is_string($record['id'] ?? null)
                    && is_string($record['name'] ?? null)
                    && is_string($record['content'] ?? null)
                    && is_bool($record['proxied'] ?? null),
            ));
            $token = bin2hex(random_bytes(16));
            $draft['dnsRecords'] = $records;
            $draft['dnsRecordToken'] = $token;
            $draft['dnsRecordPage'] = $page;
            $draft['dnsRecordPages'] = $result['pages'];
            $this->save($id, $draft);

            $keyboard = InlineKeyboardMarkup::make();
            foreach ($records as $index => $record) {
                $proxy = $record['proxied'] ? 'Proxy' : 'DNS Only';
                $keyboard->addRow(InlineKeyboardButton::make(
                    '🌐 '.$record['name'].' · '.$record['content'].' · '.$proxy,
                    callback_data: 'nw1:dns:record:'.$index.':'.$token,
                ));
            }
            $navigation = [];
            if ($page > 1) {
                $navigation[] = InlineKeyboardButton::make('◀️ قبلی',
                    callback_data: 'nw1:dns:records:'.($page - 1));
            }
            if ($page < $result['pages']) {
                $navigation[] = InlineKeyboardButton::make('بعدی ▶️',
                    callback_data: 'nw1:dns:records:'.($page + 1));
            }
            if ($navigation !== []) {
                $keyboard->addRow(...$navigation);
            }
            $keyboard->addRow(InlineKeyboardButton::make('➕ ایجاد زیردامنه جدید',
                callback_data: 'nw1:dns:new', style: ButtonStyle::PRIMARY));
            $keyboard->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه',
                callback_data: 'nw1:dns:skip'));
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت',
                callback_data: 'nw1:step:back'));
            $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف',
                callback_data: 'nw1:cancel'));

            $message = '🌐 زیردامنه‌های موجود — '.TelegramUi::escape($draft['dnsZone'])."\n\n"
                .'رکورد A موردنظر را انتخاب کنید. IP و وضعیت Proxy فعلی در دکمه‌ها نمایش داده می‌شود و وضعیت Proxy هنگام تخصیص حفظ خواهد شد.'
                .($records === [] ? "\n\nدر این صفحه زیردامنه A قابل استفاده‌ای وجود ندارد." : '')
                ."\n\nصفحه {$page} از ".$result['pages']
                ."\nرکوردهای چندگانه یا تغییرکرده پیش از تخصیص دوباره بررسی می‌شوند.";

            $richRows = '';
            foreach ($records as $record) {
                $richRows .= '<tr><td>'.TelegramUi::escape($record['name']).'</td>'
                    .'<td>'.TelegramUi::escape($record['content']).'</td>'
                    .'<td>'.($record['proxied'] ? 'Proxied' : 'DNS Only').'</td></tr>';
            }

            $richMessage = '<h2>🌐 زیردامنه‌های موجود</h2>'
                .'<p><b>دامنه اصلی:</b> '.TelegramUi::escape($draft['dnsZone']).'</p>'
                .'<p>رکورد A موردنظر را انتخاب کنید. IP فعلی و وضعیت Proxy نمایش داده می‌شود و وضعیت Proxy هنگام تخصیص حفظ خواهد شد.</p>';
            if ($records !== []) {
                $richMessage .= '<table bordered striped compact>'
                    .'<tr><td><b>زیردامنه</b></td><td><b>IP فعلی</b></td><td><b>Proxy</b></td></tr>'
                    .$richRows.'</table>';
            } else {
                $richMessage .= '<p>در این صفحه زیردامنه A قابل استفاده‌ای وجود ندارد.</p>';
            }
            $richMessage .= '<footer>صفحه '.$page.' از '.$result['pages']
                .' · رکوردهای چندگانه یا تغییرکرده پیش از تخصیص دوباره بررسی می‌شوند.</footer>';

            $this->renderRich($bot, $richMessage, $message, $keyboard);
        } catch (Throwable) {
            $this->render($bot, 'دریافت زیردامنه‌ها ممکن نشد. دوباره تلاش کنید یا بدون دامنه ادامه دهید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 تلاش دوباره',
                        callback_data: 'nw1:dns:records:'.$page, style: ButtonStyle::PRIMARY))
                    ->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه', callback_data: 'nw1:dns:skip'))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
                    ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
        }
    }

    public function dnsRecord(Nutgram $bot, string $index, string $token): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        $i = ctype_digit($index) && strlen($index) <= 2 ? (int) $index : -1;
        $currentToken = is_array($draft) ? ($draft['dnsRecordToken'] ?? null) : null;
        $selected = is_array($draft) ? ($draft['dnsRecords'][$i] ?? null) : null;
        if (($draft['stage'] ?? null) !== 'dns_records'
            || ! is_string($currentToken) || ! hash_equals($currentToken, $token)
            || ! is_array($selected) || ($selected['type'] ?? null) !== 'A'
            || ($selected['editable'] ?? null) !== true
            || ! is_string($selected['id'] ?? null)
            || ! is_string($selected['name'] ?? null)
            || ! is_string($selected['content'] ?? null)
            || ! is_bool($selected['proxied'] ?? null)
            || ! is_string($draft['dnsZone'] ?? null) || ! is_string($draft['dnsZoneId'] ?? null)) {
            $this->expired($bot);

            return;
        }

        try {
            $selectedName = $selected['name'];
            if ($this->dns->hostname($id, $draft['dnsZone'], $selectedName) !== $selectedName) {
                throw new \RuntimeException('Invalid selected hostname.');
            }
            $record = $this->dns->oneUniqueA($id, $draft['dnsZoneId'], $draft['dnsZone'], $selected['id']);
            $recordName = $record['name'] ?? null;
            $recordIp = $record['content'] ?? null;
            $proxied = $record['proxied'] ?? null;
            if ($record !== $selected || ! is_string($recordName) || ! is_string($recordIp) || ! is_bool($proxied)) {
                throw new \RuntimeException('Selected DNS record changed.');
            }

            $draft['dnsName'] = $recordName;
            $draft['dnsRecordExpected'] = $record;
            unset($draft['dnsRecords'], $draft['dnsRecordToken'], $draft['dnsRecordPage'], $draft['dnsRecordPages']);
            $draft['stage'] = 'port';
            $this->save($id, $draft);
            $this->render($bot, "🌐 دامنه انتخاب شد\n\n"
                .'نام: '.TelegramUi::code($recordName)."\n"
                .'IP فعلی: '.TelegramUi::code($recordIp)."\n"
                .'وضعیت فعلی: '.($proxied ? 'Proxied' : 'DNS Only')."\n"
                .'IP سرور جدید: '.TelegramUi::code($draft['ip'])
                ."\n\nپس از راه‌اندازی و ثبت نود، فقط IP تغییر می‌کند و وضعیت Proxy حفظ خواهد شد."
                ."\n\nاکنون پورت SSH را ارسال کنید (معمولاً 22).",
                $this->portButtons(), true);
        } catch (Throwable) {
            $this->render($bot, 'این رکورد تغییر کرده یا چند رکورد با این نام وجود دارد. فهرست را تازه‌سازی کنید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی',
                        callback_data: 'nw1:dns:records:'.($draft['dnsRecordPage'] ?? 1)))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back')));
        }
    }

    public function dnsNew(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        if (($draft['stage'] ?? null) !== 'dns_records'
            || ! is_string($draft['dnsZone'] ?? null) || ! is_string($draft['dnsZoneId'] ?? null)) {
            $this->expired($bot);

            return;
        }
        unset($draft['dnsName'], $draft['dnsRecordExpected'], $draft['dnsRecords'],
            $draft['dnsRecordToken'], $draft['dnsRecordPage'], $draft['dnsRecordPages']);
        $draft['stage'] = 'dns_name';
        $this->save($id, $draft);
        $this->dnsNamePrompt($bot, $draft);
    }

    public function dnsSkip(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        if (! in_array($draft['stage'] ?? null, ['dns_choice', 'dns_zone', 'dns_records', 'dns_name'], true)) {
            $this->expired($bot);

            return;
        }
        unset($draft['dnsZones'], $draft['dnsZone'], $draft['dnsZoneId'], $draft['dnsName'],
            $draft['dnsRecordExpected'], $draft['dnsRecords'], $draft['dnsRecordToken'],
            $draft['dnsRecordPage'], $draft['dnsRecordPages']);
        $draft['stage'] = 'port';
        $this->save($id, $draft);
        $this->render($bot, "🖥️ اتصال به سرور\n\n<b>پورت SSH</b> را ارسال کنید (معمولاً 22).", $this->portButtons(), true);
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
        $this->choices($bot, '<b>ورودی اتصال</b> را انتخاب کنید.', $draft['inbounds'], 'nw1:inbound:');
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
        $this->previewPrompt($bot, $draft);
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
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
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
            if (is_array($draft['replacement'] ?? null)) {
                $current = $this->nodes->replacementSnapshot($id, $draft['replacement']['uuid']);
                if (! $this->sameReplacementSnapshot($draft['replacement'], $current)) {
                    throw new \RuntimeException('Existing Node changed.');
                }
                $profiles = $this->nodes->profiles($id);
                if (! in_array($draft['replacement']['profileUuid'], array_column($profiles, 'uuid'), true)) {
                    throw new \RuntimeException('Existing Node profile changed.');
                }
                $available = array_column($this->nodes->inbounds($id, $draft['replacement']['profileUuid']), 'uuid');
                foreach ($draft['replacement']['inboundUuids'] as $inboundUuid) {
                    if (! in_array($inboundUuid, $available, true)) {
                        throw new \RuntimeException('Existing Node inbound changed.');
                    }
                }
            } elseif (! in_array($draft['profile']['uuid'], array_column($this->nodes->profiles($id), 'uuid'), true)
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

    /** Resume a recent setup from the existing Node menu, without reusing credentials. */
    public function resumeRecent(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! $this->enabled() || config('services.remnawave.node_ssh_install_enabled') !== true) {
            $this->render($bot, 'ادامه راه‌اندازی فعلاً فعال نیست.', $this->back());

            return;
        }
        $id = $this->installer->recent($root);
        if ($id === null) {
            $this->render($bot, 'راه‌اندازی قابل ادامه‌ای پیدا نشد. اگر زمان آن گذشته، وضعیت سرور را در پنل بررسی کنید.', $this->back());

            return;
        }

        // The regular status path already rechecks ownership and current privilege.
        $this->installationStatus($bot, $id);
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
            $this->render($bot, 'اطلاعات این راه‌اندازی در دسترس نیست یا زمان آن گذشته است. پیش از شروع دوباره، فهرست سرورها و هاست‌ها را بررسی کنید.', $this->back());

            return;
        }
        $replacementTarget = $this->installer->replacementTarget($root, $identifier);
        if ($status['state'] === 'running' && $replacementTarget !== null) {
            $this->render($bot, "🖥️ آماده‌سازی سرور جایگزین\n\nنصب روی سرور جدید کامل شده است. برای حفظ نود فعلی و انتقال دامنه‌ها ادامه دهید.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('➡️ ادامه جایگزینی سرور', callback_data: 'nw5:resume:'.$identifier, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی وضعیت نصب', callback_data: 'nw2:status:'.$identifier))
                    ->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1')));

            return;
        }
        if ($status['state'] === 'running' && $this->installer->matchingConnectedNode($root, $identifier)) {
            $this->installer->closeRecent($root, $identifier);
            $this->render($bot, '✅ سرور «'.$status['name']."» در پنل ثبت و متصل است.\n\nپیگیری راه‌اندازی قبلی بسته شد؛ هیچ تغییری روی سرور انجام نشد.", $this->back());

            return;
        }
        $description = match ($status['state']) {
            'queued' => 'در صف آماده‌سازی. اگر انتظار طولانی شد، وضعیت اجرای کارهای پس‌زمینه را بررسی کنید.',
            'launching', 'pending' => 'در حال شروع عملیات. می‌توانید کمی بعد وضعیت را تازه‌سازی کنید.',
            'started', 'installing' => 'در حال نصب نرم‌افزارها',
            'starting' => 'در حال راه‌اندازی سرویس',
            'running' => '✅ سرویس سرور راه‌اندازی شده است. ثبت در پنل و بررسی اتصال هنوز باقی مانده است.',
            'failed' => '❌ نصب کامل نشد. ابتدا وضعیت سرویس را روی سرور بررسی کنید؛ درخواست نصب جدید را بدون بررسی تکرار نکنید.',
            default => '⚠️ نتیجه نصب هنوز روشن نیست. وضعیت سرور را بررسی کنید؛ شروع دوباره ممکن است نصب قبلی را تکرار کند.',
        };
        $keyboard = $this->statusKeyboard($identifier);
        if (in_array($status['state'], ['running', 'failed', 'uncertain'], true)
            && $this->installer->recent($root) === $identifier) {
            $keyboard->addRow(InlineKeyboardButton::make('✅ بستن پیگیری راه‌اندازی', callback_data: 'nw4:close:'.$identifier));
        }
        if ($status['state'] === 'running'
            && config('services.remnawave.writes_enabled') === true
            && config('services.remnawave.node_ssh_install_enabled') === true) {
            $keyboard->addRow(InlineKeyboardButton::make('➡️ تکمیل اتصال سرور', callback_data: 'nw3:resume:'.$identifier));
        }
        $this->render($bot, "🖥️ وضعیت آماده‌سازی\n\nنام: ".$status['name']."\n".$description,
            $keyboard);
    }

    public function closeRecent(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            if (! $this->installer->closeRecent($root, $identifier)) {
                $this->expired($bot);

                return;
            }
            $this->render($bot, "✅ پیگیری راه‌اندازی از منوی نودها بسته شد.\n\nاطلاعات نصب حفظ شده و هیچ تغییری روی VPS یا پنل انجام نشده است.", $this->back());
        } catch (Throwable) {
            $this->expired($bot);
        }
    }

    public function completionOverview(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        Cache::forget('nw03:hostDraft:'.$root);
        Cache::forget('nw03:confirm:'.$root);
        if ($this->installer->replacementTarget($root, $identifier) !== null) {
            $this->replacementOverview($bot, $identifier);

            return;
        }
        try {
            if ($this->installer->matchingConnectedNode($root, $identifier)) {
                $this->installer->closeRecent($root, $identifier);
                $this->render($bot, "✅ این سرور قبلاً از مسیر ثبت نود آماده به پنل متصل شده است.\n\nنیازی به ثبت دوباره نیست.", $this->back());

                return;
            }
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
                } elseif ($step['dnsStage'] === 'ready') {
                    $message .= "\n\n🌐 دامنه «".$step['dnsName'].'» آماده ثبت است. ابتدا رکورد دامنه را بررسی و تأیید کنید.';
                    $keyboard->addRow(InlineKeyboardButton::make('🌐 تنظیم دامنه سرور', callback_data: 'nw3:dns:prepare:'.$identifier, style: ButtonStyle::PRIMARY));
                } elseif ($step['dnsStage'] === 'uncertain') {
                    $message .= "\n\n⚠️ نتیجه ثبت دامنه روشن نیست. فقط وضعیت رکورد بررسی می‌شود؛ ثبت دوباره انجام نمی‌شود.";
                    $keyboard->addRow(InlineKeyboardButton::make('🔎 بررسی مجدد دامنه', callback_data: 'nw3:dns:reconcile:'.$identifier, style: ButtonStyle::PRIMARY));
                } elseif ($step['hostStage'] === 'ready') {
                    $message .= "\n\n✅ سرور به پنل متصل است."
                        .($step['dnsStage'] === 'verified' ? "\n🌐 دامنه ".$step['dnsName'].' تأیید شد.' : '')
                        ."\nاکنون می‌توانید هاست را ایجاد کنید.";
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

    public function replacementOverview(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $target = $this->installer->replacementTarget($root, $identifier);
            if ($target === null) {
                throw new \RuntimeException('Not a replacement installation.');
            }
            $progress = $this->replacement->progress($root, $identifier);
            if ($progress['stage'] === 'ready') {
                $this->prepareReplacement($bot, $identifier);

                return;
            }

            $keyboard = InlineKeyboardMarkup::make();
            $message = '🔁 جایگزینی سرور «'.TelegramUi::escape($target['name'])."\n\n"
                .'IP قبلی: '.TelegramUi::code($progress['oldIp'])."\nIP جدید: ".TelegramUi::code($progress['newIp'])."\n";
            $rows = $this->replacementRichRow('نود', $target['name'])
                .$this->replacementRichRow('IP قبلی', $progress['oldIp'])
                .$this->replacementRichRow('IP جدید', $progress['newIp']);

            foreach ($progress['dnsRecords'] as $record) {
                $status = $record['stage'] === 'verified' ? 'منتقل شد' : 'در انتظار بررسی';
                $message .= "\n🌐 ".TelegramUi::code($record['hostname']).' — '.$status;
                $rows .= $this->replacementRichRow($record['hostname'], $status);
            }

            if ($progress['stage'] === 'applying') {
                $message .= "\n\n⚠️ نتیجه بعضی عملیات مشخص نیست. فقط وضعیت فعلی بررسی می‌شود و هیچ تغییری تکرار نخواهد شد.";
                $rows .= $this->replacementRichRow('وضعیت عملیات', 'نیازمند بررسی');
                $keyboard->addRow(InlineKeyboardButton::make('🔎 بررسی وضعیت عملیات', callback_data: 'nw5:reconcile:'.$identifier, style: ButtonStyle::PRIMARY));
            } elseif ($progress['stage'] === 'awaiting_client_test') {
                if ($progress['connected'] && ! $progress['disabled']) {
                    $message .= "\n\nپنل Node را متصل گزارش می‌کند؛ این وضعیت به‌تنهایی دسترسی کاربران به سرور جدید را ثابت نمی‌کند. ابتدا مطمئن شوید دامنه‌ها به IP جدید resolve می‌شوند و سپس با یک کلاینت واقعی از اینترنت کاربران اتصال را آزمایش کنید. تا ثبت نتیجه، VPS قدیمی را نگه دارید.";
                    $rows .= $this->replacementRichRow('وضعیت پنل', 'متصل · تست کاربران لازم است');
                    $keyboard->addRow(InlineKeyboardButton::make('✅ تأیید تست واقعی کلاینت', callback_data: 'nw5:accept:'.$identifier, style: ButtonStyle::SUCCESS));
                } else {
                    $message .= "\n\n⏳ هنوز اتصال Node در پنل تأیید نشده است. VPS قدیمی را نگه دارید و پس از بررسی دوباره تلاش کنید.";
                    $rows .= $this->replacementRichRow('وضعیت پنل', 'در انتظار اتصال');
                }
                $keyboard->addRow(InlineKeyboardButton::make('🔄 بررسی وضعیت', callback_data: 'nw5:resume:'.$identifier, style: ButtonStyle::PRIMARY));
            } elseif ($progress['stage'] === 'completed') {
                $message .= "\n\n✅ تست واقعی اتصال کلاینت توسط اپراتور تأیید شد. همان UUID و تنظیمات قبلی حفظ شده‌اند. حالا می‌توانید برای خاموش‌کردن VPS قدیمی برنامه‌ریزی کنید.";
                $rows .= $this->replacementRichRow('تست کلاینت', 'تأییدشده توسط اپراتور');
            }

            $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1'));
            $rich = '<h2>🔁 وضعیت جایگزینی سرور</h2><table bordered striped compact>'.$rows.'</table>'
                .'<footer>ابتدا تغییر DNS به IP جدید و اتصال واقعی کلاینت را بررسی کنید؛ تا پیش از تأیید، سرور قبلی را نگه دارید.</footer>';
            $this->renderRich($bot, $rich, $message, $keyboard);
        } catch (Throwable) {
            $this->render($bot, 'بررسی جایگزینی ممکن نشد. وضعیت نود و رکوردهای DNS را دستی بررسی کنید؛ عملیات را دوباره اجرا نکنید.', $this->back());
        }
    }

    public function prepareReplacement(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $review = $this->replacement->preview($root, $identifier);
            $token = bin2hex(random_bytes(16));
            Cache::put('nw05:confirm:'.$root, ['id' => $identifier, 'token' => $token, 'expected' => $review], now()->addMinutes(10));

            $message = "⚠️ تأیید نهایی جایگزینی\n\n"
                .'نود: '.TelegramUi::escape($review['node']['name'])."\nUUID: ".TelegramUi::code($review['node']['uuid'])."\n"
                .'IP فعلی: '.TelegramUi::code($review['oldIp'])."\nIP جدید: ".TelegramUi::code($review['newIp'])."\n"
                .'حالت آدرس Node: '.($review['nodeAddressMode'] === 'domain' ? 'حفظ دامنه فعلی' : 'تغییر IP مستقیم')."\n\n"
                .'Hostهای حفظ‌شده: '.count($review['hosts'])."\n"
                .'دامنه‌های قابل انتقال: '.count($review['dnsRecords'])."\n";

            $rows = $this->replacementRichRow('نود', $review['node']['name'])
                .$this->replacementRichRow('UUID', $review['node']['uuid'])
                .$this->replacementRichRow('IP فعلی', $review['oldIp'])
                .$this->replacementRichRow('IP جدید', $review['newIp'])
                .$this->replacementRichRow('آدرس Node', $review['nodeAddressMode'] === 'domain' ? 'حفظ دامنه فعلی' : 'IP مستقیم')
                .$this->replacementRichRow('Hostهای حفظ‌شده', (string) count($review['hosts']));

            foreach ($review['hosts'] as $host) {
                $endpoint = $host['address'].':'.$host['port'];
                $message .= "\n• ".TelegramUi::escape($host['remark']).' — '.TelegramUi::code($endpoint);
                $rows .= $this->replacementRichRow($host['remark'], $endpoint);
            }

            foreach ($review['dnsRecords'] as $record) {
                $proxy = $record['record']['proxied'] ? 'Proxied' : 'DNS Only';
                $message .= "\n• ".TelegramUi::code($record['hostname']).' — '.$proxy;
                $rows .= $this->replacementRichRow($record['hostname'], $proxy);
            }

            $message .= "\n\nUUID، پروفایل، Inboundها و Hostها حذف نمی‌شوند. فقط رکوردهای A موجود به IP جدید منتقل می‌شوند و وضعیت Proxy حفظ خواهد شد. پس از تأیید، درخواست‌ها تکرارپذیر نیستند.";
            $rich = '<h2>⚠️ بازبینی نهایی جایگزینی</h2><table bordered striped compact>'.$rows.'</table>'
                .'<footer>هیچ Node یا Hostی حذف یا بازسازی نمی‌شود. پس از انتقال، تست واقعی اتصال کاربران الزامی است.</footer>';
            $this->renderRich($bot, $rich, $message,
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ تأیید انتقال سرور و دامنه‌ها', callback_data: 'nw5:apply:'.$token, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw5:resume:'.$identifier)));
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات Node یا Hostها کامل نیست، دامنه‌ای خارج از Cloudflare وجود دارد یا رکورد DNS با IP فعلی تطبیق ندارد. هیچ تغییری انجام نشد.', $this->back());
        }
    }

    public function applyReplacement(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->expired($bot);

            return;
        }
        $key = 'nw05:confirm:'.$root;
        try {
            $confirmation = Cache::lock($key.':lock', 15)->block(1, static function () use ($key, $token): mixed {
                $data = Cache::get($key);
                if (! is_array($data) || ! is_string($data['token'] ?? null) || ! hash_equals($data['token'], $token)) {
                    return null;
                }
                Cache::forget($key);

                return $data;
            });
            if (! is_array($confirmation) || ! is_string($confirmation['id'] ?? null) || ! is_array($confirmation['expected'] ?? null)) {
                $this->expired($bot);

                return;
            }
            $this->replacement->apply($root, $confirmation['id'], $confirmation['expected']);
            $this->replacementOverview($bot, $confirmation['id']);
        } catch (Throwable) {
            $this->render($bot, 'نتیجه انتقال مشخص نیست. از گزینه بررسی وضعیت استفاده کنید و عملیات را دوباره تأیید نکنید.', $this->back());
        }
    }

    public function acceptReplacement(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $this->replacement->acceptClientTest($root, $identifier);
            $this->replacementOverview($bot, $identifier);
        } catch (Throwable) {
            $this->render($bot, 'تأیید انجام نشد. ابتدا اتصال واقعی کلاینت و وضعیت Node را بررسی کنید.', $this->back());
        }
    }

    public function reconcileReplacement(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $this->replacement->reconcile($root, $identifier);
            $this->replacementOverview($bot, $identifier);
        } catch (Throwable) {
            $this->render($bot, 'همه تغییرات قابل تأیید نیستند. بدون تکرار عملیات، وضعیت Node و رکوردهای Cloudflare را دستی بررسی کنید.', $this->back());
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private function sameReplacementSnapshot(array $expected, array $actual): bool
    {
        foreach (['uuid', 'name', 'address', 'port', 'disabled', 'profileUuid', 'inboundUuids'] as $field) {
            if (($expected[$field] ?? null) !== ($actual[$field] ?? null)) {
                return false;
            }
        }

        return true;
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
                .'IP: '.$review['address']."\n"
                .'پورت ارتباط نود: '.$review['port']."\n\n"
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

    public function prepareDns(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $review = $this->completion->previewDns($root, $identifier);
            $token = bin2hex(random_bytes(16));
            Cache::put('nw03:confirm:'.$root, [
                'kind' => 'dns', 'id' => $identifier, 'token' => $token,
                'expected' => $review['record'],
            ], now()->addMinutes(10));
            $detail = match ($review['mode']) {
                'update' => $review['previousIp'] === $review['ip']
                    ? 'IP فعلی: '.$review['previousIp']."\nIP هدف: بدون تغییر\nوضعیت Proxy: ".($review['proxied'] ? 'Proxied' : 'DNS Only')."\n\nاین رکورد از قبل به IP سرور اشاره می‌کند؛ تغییری لازم نیست."
                    : 'IP فعلی: '.$review['previousIp']."\nIP جدید: ".$review['ip']."\nوضعیت Proxy: ".($review['proxied'] ? 'Proxied' : 'DNS Only')."\n\nفقط IP تغییر می‌کند و وضعیت Proxy حفظ می‌شود.",
                default => $review['state'] === 'existing'
                    ? 'IP: '.$review['ip']."\n\nرکورد A با همین IP و DNS Only از قبل وجود دارد و دوباره استفاده می‌شود."
                    : 'IP جدید: '.$review['ip']."\nنوع رکورد: A · DNS Only\n\nپس از تأیید، یک رکورد جدید ساخته می‌شود.",
            };
            $impact = $review['mode'] === 'update' && $review['previousIp'] !== $review['ip']
                ? "\n\n⚠️ با تأیید، ترافیک این دامنه به سرور جدید منتقل می‌شود و ممکن است کاربران فعلی تحت تأثیر قرار بگیرند."
                : '';
            $this->render($bot, "🌐 تأیید تخصیص دامنه\n\nآدرس: ".$review['name']."\n\n".$detail.$impact
                ."\n\nاین عملیات فقط پس از تأیید نهایی اجرا می‌شود.",
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('✅ تأیید تنظیم دامنه', callback_data: 'nw3:dns:apply:'.$token, style: ButtonStyle::SUCCESS))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw3:resume:'.$identifier)));
        } catch (Throwable) {
            $this->render($bot, 'دامنه موجود با مشخصات سرور مطابقت ندارد، یا بررسی آن ممکن نیست. رکوردها را بررسی کنید.', $this->back());
        }
    }

    public function applyDns(Nutgram $bot, string $token): void
    {
        $this->applyConfirmation($bot, $token, 'dns');
    }

    public function reconcileDns(Nutgram $bot, string $identifier): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        try {
            $this->completion->reconcileDns($root, $identifier);
            $this->completionOverview($bot, $identifier);
        } catch (Throwable) {
            $this->render($bot, 'رکورد هنوز تأیید نشده است. بدون ساخت دوباره، وضعیت آن را در پنل دامنه بررسی کنید.',
                InlineKeyboardMarkup::make()
                    ->addRow(InlineKeyboardButton::make('🔎 بررسی دوباره', callback_data: 'nw3:dns:reconcile:'.$identifier, style: ButtonStyle::PRIMARY))
                    ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw3:resume:'.$identifier)));
        }
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
            } elseif ($kind === 'dns') {
                $expected = is_array($state['expected'] ?? null) ? $state['expected'] : null;
                $this->completion->ensureDns($root, $state['id'], $expected);
            } else {
                $this->completion->createHost($root, $state['id'], $state['port']);
            }
            $this->completionOverview($bot, $state['id']);
        } catch (Throwable) {
            $this->render($bot, 'نتیجه عملیات تأیید نشد. پیش از تلاش جدید، وضعیت سرور، هاست یا دامنه را بررسی کنید.', $this->back());
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
        Cache::forget('nw03:hostDraft:'.$id);
        Cache::forget('nw03:confirm:'.$id);
        Cache::forget('nw05:confirm:'.$id);
        $this->render($bot, 'راه‌اندازی لغو شد.', InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ کنترل پنل', callback_data: 'menu:admin:control')));
    }

    /** Stage-bound shortcuts; neither action can bypass draft validation. */
    public function defaultValue(Nutgram $bot, string $field): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $stage = $this->draft($id)['stage'] ?? null;
        if (($field === 'port' && $stage === 'port') || ($field === 'root' && $stage === 'username')) {
            $this->text($bot, $field === 'port' ? '22' : 'root');

            return;
        }
        $this->expired($bot);
    }

    /** Rewind an editable draft only; never repeat a remote operation. */
    public function stepBack(Nutgram $bot): void
    {
        $id = $this->root($bot);
        if ($id === null) {
            return;
        }
        $draft = $this->draft($id);
        if (! is_array($draft) || ! $this->enabled()) {
            $this->expired($bot);

            return;
        }
        switch ($draft['stage'] ?? null) {
            case 'ip':
                if (is_array($draft['replacement'] ?? null)) {
                    Cache::forget($this->key($id));
                    $this->render($bot, 'ورود به فرایند جایگزینی لغو شد.', $this->back());

                    return;
                }
                $draft['stage'] = 'name';
                unset($draft['ip']);
                $this->save($id, $draft);
                $this->render($bot, "🖥️ راه‌اندازی سرور — گام ۱\n\n<b>نام سرور</b> را ارسال کنید.\nمثال: Germany-02", $this->cancelButton(false), true);

                return;
            case 'dns_choice':
                $draft['stage'] = 'ip';
                $this->save($id, $draft);
                $this->ipPrompt($bot);

                return;
            case 'dns_zone':
                $draft['stage'] = 'dns_choice';
                unset($draft['dnsZones']);
                $this->save($id, $draft);
                $this->dnsChoicePrompt($bot);

                return;
            case 'dns_records':
                unset($draft['dnsZone'], $draft['dnsZoneId'], $draft['dnsName'], $draft['dnsZones'],
                    $draft['dnsRecordExpected'], $draft['dnsRecords'], $draft['dnsRecordToken'],
                    $draft['dnsRecordPage'], $draft['dnsRecordPages']);
                $draft['stage'] = 'dns_choice';
                $this->save($id, $draft);
                $this->dnsChoicePrompt($bot);

                return;
            case 'dns_name':
                unset($draft['dnsName'], $draft['dnsRecordExpected']);
                $draft['stage'] = 'dns_records';
                $this->save($id, $draft);
                $this->dnsRecords($bot, '1');

                return;
            case 'port':
                if (is_array($draft['replacement'] ?? null)) {
                    $draft['stage'] = 'ip';
                    unset($draft['ip']);
                    $this->save($id, $draft);
                    $this->render($bot, "🔁 جایگزینی سرور\n\n<b>IP عمومی سرور جدید</b> را ارسال کنید.", $this->cancelButton(), true);

                    return;
                }
                if (isset($draft['dnsRecordExpected'], $draft['dnsZone'])) {
                    unset($draft['dnsRecordExpected'], $draft['dnsName']);
                    $draft['stage'] = 'dns_records';
                    $this->save($id, $draft);
                    $this->dnsRecords($bot, '1');
                } elseif (isset($draft['dnsName'], $draft['dnsZone'])) {
                    $draft['stage'] = 'dns_name';
                    unset($draft['dnsName']);
                    $this->save($id, $draft);
                    $this->dnsNamePrompt($bot, $draft);
                } elseif ($this->dns->wizardEnabled()) {
                    $draft['stage'] = 'dns_choice';
                    $this->save($id, $draft);
                    $this->dnsChoicePrompt($bot);
                } else {
                    $draft['stage'] = 'ip';
                    $this->save($id, $draft);
                    $this->ipPrompt($bot);
                }

                return;
            case 'username':
                $draft['stage'] = 'port';
                unset($draft['username']);
                $this->save($id, $draft);
                $this->render($bot, "🖥️ اتصال به سرور\n\n<b>پورت SSH</b> را ارسال کنید (معمولاً 22).", $this->portButtons(), true);

                return;
            case 'password':
                $draft['stage'] = 'username';
                $this->save($id, $draft);
                $this->render($bot, "🖥️ اتصال به سرور\n\n<b>نام کاربری SSH</b> را ارسال کنید (برای نصب خودکار: root).", $this->usernameButtons(), true);

                return;
            case 'profile':
                $draft['stage'] = 'password';
                unset($draft['secret'], $draft['profiles'], $draft['profile'], $draft['inbounds'], $draft['inbound']);
                $this->save($id, $draft);
                $this->render($bot, "🔐 رمز سرور\n\n<b>رمز SSH</b> را فقط در همین گفتگوی خصوصی بفرستید. پیام پس از دریافت حذف می‌شود؛ بعد از راه‌اندازی رمز را تغییر دهید.", $this->cancelButton(), true);

                return;
            case 'inbound':
                $draft['stage'] = 'profile';
                unset($draft['profile'], $draft['inbounds'], $draft['inbound']);
                $this->save($id, $draft);
                $this->choices($bot, '<b>پروفایل اتصال</b> را انتخاب کنید.', $draft['profiles'], 'nw1:profile:');

                return;
            case 'preview':
                if (is_array($draft['replacement'] ?? null)) {
                    $draft['stage'] = 'ip';
                    unset($draft['ip'], $draft['host'], $draft['token'], $draft['secret']);
                    $this->save($id, $draft);
                    $this->render($bot, "🔁 جایگزینی سرور\n\n<b>IP عمومی سرور جدید</b> را ارسال کنید.", $this->cancelButton(), true);

                    return;
                }
                $draft['stage'] = 'inbound';
                unset($draft['token'], $draft['inbound'], $draft['host']);
                $this->save($id, $draft);
                $this->choices($bot, '<b>ورودی اتصال</b> را انتخاب کنید.', $draft['inbounds'], 'nw1:inbound:');

                return;
            case 'trust':
                $draft['stage'] = 'preview';
                unset($draft['host']);
                $draft['token'] = bin2hex(random_bytes(16));
                $this->save($id, $draft);
                $this->previewPrompt($bot, $draft);

                return;
            default:
                $this->expired($bot);
        }
    }

    private function dnsChoicePrompt(Nutgram $bot): void
    {
        $this->render($bot, "🌐 دامنه سرور\n\nمی‌خواهید یک دامنه برای اتصال کاربران تنظیم کنید؟\n\nدر غیر این صورت، اتصال با IP انجام می‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('🌐 انتخاب دامنه', callback_data: 'nw1:dns:zones', style: ButtonStyle::PRIMARY))
                ->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه', callback_data: 'nw1:dns:skip'))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel')));
    }

    /** @param array<string,mixed> $draft */
    private function dnsNamePrompt(Nutgram $bot, array $draft): void
    {
        $example = 'usa.'.$draft['dnsZone'];
        $this->render($bot, "🌐 زیردامنه سرور\n\n<b>نام زیردامنه</b> را ارسال کنید، مثلاً:\n"
            .TelegramUi::code($example)."\n\nبعد از راه‌اندازی سرور، ثبت دامنه را جداگانه تأیید می‌کنید.",
            InlineKeyboardMarkup::make()
                ->addRow(TelegramUi::copy($example, '📋 کپی نمونه'))
                ->addRow(InlineKeyboardButton::make('➡️ ادامه بدون دامنه', callback_data: 'nw1:dns:skip'))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel', style: ButtonStyle::DANGER)),
            true);
    }

    /** @param array<string,mixed> $draft */
    private function previewPrompt(Nutgram $bot, array $draft): void
    {
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔎 بررسی هویت سرور', callback_data: 'nw1:scan:'.$draft['token']))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));

        if (is_array($draft['replacement'] ?? null)) {
            $target = $draft['replacement'];
            $message = "🔁 بازبینی جایگزینی سرور\n\n"
                .'نود: '.TelegramUi::escape($draft['name'])."\n"
                .'آدرس فعلی نود: '.TelegramUi::code($target['address'])."\n"
                .'IP سرور جدید: '.TelegramUi::code($draft['ip'])."\n"
                .'پورت SSH: '.TelegramUi::code((string) $draft['port'])."\n"
                .'پروفایل فعلی: '.TelegramUi::escape($draft['profile']['name'])."\n"
                .'ورودی‌های فعال حفظ‌شده: '.count($target['inboundUuids'])
                ."\n\nUUID نود، پروفایل، ورودی‌ها و Hostهای فعلی حذف یا بازسازی نمی‌شوند. پس از نصب، رکوردهای DNS مرتبط به IP جدید منتقل خواهند شد.";
            $rows = $this->replacementRichRow('نود', $draft['name'])
                .$this->replacementRichRow('UUID', $target['uuid'])
                .$this->replacementRichRow('آدرس فعلی', $target['address'])
                .$this->replacementRichRow('IP سرور جدید', $draft['ip'])
                .$this->replacementRichRow('پورت SSH', (string) $draft['port'])
                .$this->replacementRichRow('پروفایل فعلی', $draft['profile']['name'])
                .$this->replacementRichRow('ورودی‌های حفظ‌شده', (string) count($target['inboundUuids']));
            $rich = '<h2>🔁 بازبینی جایگزینی سرور</h2><table bordered striped compact>'.$rows.'</table>'
                .'<footer>در این مرحله هیچ تغییری روی Node یا DNS انجام نمی‌شود.</footer>';
            $this->renderRich($bot, $rich, $message, $keyboard);

            return;
        }

        $message = "🖥️ بازبینی مشخصات سرور\n\n"
            .'نام: '.$draft['name']."\nIP: ".$draft['ip']."\nپورت SSH: ".$draft['port']."\nپورت ارتباط نود: 2222\n"
            .'کاربر: '.$draft['username']."\nپروفایل: ".$draft['profile']['name']."\nورودی: ".$draft['inbound']['name']
            .(isset($draft['dnsName']) ? "\nدامنه: ".$draft['dnsName'] : '')
            .(is_array($draft['dnsRecordExpected'] ?? null)
                ? "\nIP فعلی دامنه: ".(is_string($draft['dnsRecordExpected']['content'] ?? null) ? $draft['dnsRecordExpected']['content'] : '—')
                    ."\nوضعیت Proxy: ".(($draft['dnsRecordExpected']['proxied'] ?? null) === true ? 'Proxied' : 'DNS Only')
                    ."\nIP هدف پس از ثبت نود: ".$draft['ip']
                : (isset($draft['dnsName']) ? "\nنوع دامنه: زیردامنه جدید · رکورد A" : ''))
            ."\n\nدر این مرحله هیچ تغییری روی سرور یا Cloudflare انجام نمی‌شود.";
        $this->render($bot, $message, $keyboard);
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
        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'));
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
        $this->render($bot, $label.($options === [] ? "\n\nموردی موجود نیست." : ''), $keyboard, true);
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

    private function ipPrompt(Nutgram $bot): void
    {
        $this->render($bot, "🖥️ راه‌اندازی سرور — گام ۲\n\n<b>IP عمومی سرور</b> را ارسال کنید.\nمثال: 194.5.192.133", $this->cancelButton(), true);
    }

    private function portButtons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('۲۲ · پورت پیش‌فرض', callback_data: 'nw1:default:port', style: ButtonStyle::PRIMARY))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
    }

    private function usernameButtons(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('root · کاربر پیش‌فرض', callback_data: 'nw1:default:root', style: ButtonStyle::PRIMARY))
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
    }

    private function cancelButton(bool $canBack = true): InlineKeyboardMarkup
    {
        $keyboard = InlineKeyboardMarkup::make();
        if ($canBack) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'nw1:step:back'));
        }

        return $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'nw1:cancel'));
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('◀️ مدیریت نودها', callback_data: 'node1:list:1'));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این مرحله منقضی شده یا تمام شده است.', $this->back());
    }

    private function replacementRichRow(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'.TelegramUi::escape($value).'</b></td></tr>';
    }

    private function renderRich(
        Nutgram $bot,
        string $richHtml,
        string $fallback,
        InlineKeyboardMarkup $keyboard,
    ): void {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        try {
            TelegramUi::editRichText(
                $bot,
                new InputRichMessage(html: $richHtml, is_rtl: true),
                $keyboard,
            );

            return;
        } catch (TelegramException $exception) {
            report($exception);
        }

        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $fallback, reply_markup: $keyboard, parse_mode: 'HTML');
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard, bool $html = false): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $message, $keyboard, $html ? 'HTML' : null);

            return;
        }
        $bot->sendMessage(text: $message, reply_markup: $keyboard, parse_mode: $html ? 'HTML' : null);
    }
}
