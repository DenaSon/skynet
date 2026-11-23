<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminArianetCloudLinks;
use App\Support\PersianCalendar;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/**
 * ARI-02B: private root-only Cloud <-> Node mapping UI.
 * The only mutation is to the local mapping table after a one-use confirmation.
 */
final readonly class AdminArianetCloudLinksUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminArianetCloudLinks $links,
        private MenuRenderer $menus,
    ) {}

    public function candidates(Nutgram $bot, string $rawId, string $rawParent, string $rawPage): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::int($rawId, PHP_INT_MAX);
        $parent = self::int($rawParent, 100);
        $page = self::int($rawPage, 100);
        if ($id === null || $parent === null || $page === null) {
            $this->render($bot, 'انتخاب معتبر نیست.', self::backToList());

            return;
        }

        try {
            $result = $this->links->candidates($root, $id, $page);
        } catch (Throwable) {
            $this->render($bot, 'دریافت نودها ممکن نیست؛ دوباره تلاش کنید.',
                self::retryCandidates($id, $parent, $page));

            return;
        }

        $token = bin2hex(random_bytes(8));
        Cache::put(self::candidateKey($root, $token), [
            'serverId' => $id, 'parent' => $parent, 'items' => $result['items'],
        ], now()->addMinutes(10));

        $buttons = InlineKeyboardMarkup::make();
        foreach ($result['items'] as $index => $node) {
            $buttons->addRow(InlineKeyboardButton::make(
                self::matchIcon($node['match']).' '.self::safe($node['name'], 36),
                callback_data: 'ari3:pick:'.$id.':'.$parent.':'.$index.':'.$token,
            ));
        }

        $nav = [];
        if ($page > 1) {
            $nav[] = InlineKeyboardButton::make('◀️ قبلی',
                callback_data: 'ari3:nodes:'.$id.':'.$parent.':'.($page - 1));
        }
        if ($page < $result['pages']) {
            $nav[] = InlineKeyboardButton::make('بعدی ▶️',
                callback_data: 'ari3:nodes:'.$id.':'.$parent.':'.($page + 1));
        }
        if ($nav !== []) {
            $buttons->addRow(...$nav);
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی',
            callback_data: 'ari3:nodes:'.$id.':'.$parent.':'.$page));
        $buttons->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور',
            callback_data: 'ari2:view:'.$id.':'.$parent));

        $rows = '';
        $simple = "<b>🔗 اتصال سرور به نود</b>\n\n"
            .'سرور: '.TelegramUi::escape($result['cloudName'])
            ."\nIP سرور: ".TelegramUi::escape($result['cloudIpv4'])
            ."\nصفحه ".PersianCalendar::digits((string) $page).' از '.PersianCalendar::digits((string) $result['pages']);
        foreach ($result['items'] as $node) {
            $status = self::matchText($node['match']);
            $rows .= self::row($node['name'], $node['address'].' · '.$status);
            $simple .= "\n".TelegramUi::escape($node['name']).' · '
                .TelegramUi::escape($node['address']).' · '.$status;
        }
        if ($result['items'] === []) {
            $simple .= "\nنود قابل انتخابی وجود ندارد.";
        }
        $rich = '<h2>🔗 اتصال سرور به نود</h2>'
            .'<p>سرور: '.TelegramUi::escape($result['cloudName'])
            .' · '.TelegramUi::escape($result['cloudIpv4']).'</p>'
            .'<p>صفحه '.PersianCalendar::digits((string) $page).' از '
            .PersianCalendar::digits((string) $result['pages']).'</p>'
            .($rows === '' ? '<p>نود قابل انتخابی وجود ندارد.</p>'
                : '<table bordered striped compact>'.$rows.'</table>')
            .'<footer>✅ تطبیق مستقیم IP در ابتدای فهرست است. دامنه یا IP متفاوت نیازمند بررسی دستی است. '
            .'فقط ارتباط داخلی ذخیره می‌شود؛ تنظیمات سرور و نود تغییر نمی‌کنند.</footer>';
        $this->render($bot, $simple, $buttons, $rich);
    }

    public function pick(Nutgram $bot, string $rawId, string $rawParent, string $rawIndex, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::int($rawId, PHP_INT_MAX);
        $parent = self::int($rawParent, 100);
        $index = self::index($rawIndex);
        $selection = preg_match('/\A[0-9a-f]{16}\z/D', $token) === 1
            ? Cache::get(self::candidateKey($root, $token)) : null;

        if ($id === null || $parent === null || $index === null || ! is_array($selection)
            || ($selection['serverId'] ?? null) !== $id
            || ($selection['parent'] ?? null) !== $parent
            || ! is_string($selection['items'][$index]['uuid'] ?? null)) {
            $this->render($bot, 'این انتخاب منقضی شده است؛ فهرست نودها را دوباره باز کنید.',
                $id === null || $parent === null ? self::backToList()
                    : self::retryCandidates($id, $parent, 1));

            return;
        }

        try {
            $preview = $this->links->preview($root, $id, $selection['items'][$index]['uuid']);
        } catch (Throwable) {
            $this->render($bot, 'اطلاعات سرور یا نود تغییر کرده است؛ دوباره بررسی کنید.',
                self::retryCandidates($id, $parent, 1));

            return;
        }

        $this->confirmScreen($bot, $root, $preview, $parent);
    }

    public function unlink(Nutgram $bot, string $rawId, string $rawParent): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::int($rawId, PHP_INT_MAX);
        $parent = self::int($rawParent, 100);
        if ($id === null || $parent === null) {
            $this->render($bot, 'درخواست معتبر نیست.', self::backToList());

            return;
        }
        try {
            $preview = $this->links->preview($root, $id, null);
        } catch (Throwable) {
            $this->render($bot, 'ارتباط ثبت‌شده‌ای یافت نشد؛ جزئیات را دوباره بررسی کنید.',
                self::backToDetail($id, $parent));

            return;
        }
        $this->confirmScreen($bot, $root, $preview, $parent);
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[0-9a-f]{24}\z/D', $token) !== 1) {
            $this->render($bot, 'تأیید معتبر نیست؛ از ابتدا شروع کنید.', self::backToList());

            return;
        }

        // Serialize redemption per operator, consuming one-use state *before*
        // the DB mutation. Replay cannot reissue a link change after failure.
        try {
            $result = Cache::lock('arianet:link:redeem:'.$root, 30)->block(2,
                function () use ($root, $token): ?array {
                    $state = Cache::pull(self::pendingKey($root, $token));
                    if (! is_array($state) || ! is_array($state['preview'] ?? null)
                        || ! is_int($state['parent'] ?? null)) {
                        return null;
                    }
                    $this->links->apply($root, $state['preview']);

                    return $state;
                });
        } catch (Throwable) {
            $this->render($bot, 'ثبت ارتباط انجام نشد. اطلاعات را دوباره بررسی و تأیید کنید.',
                self::backToList());

            return;
        }

        if ($result === null) {
            $this->render($bot, 'این تأیید منقضی شده یا قبلاً استفاده شده است.',
                self::backToList());

            return;
        }
        $id = $result['preview']['serverId'];
        $page = $result['parent'];
        $message = $result['preview']['mode'] === 'unlink'
            ? '✅ ارتباط سرور و نود در دناگیت قطع شد.'
            : '✅ ارتباط سرور ابری و نود در دناگیت ثبت شد.';
        $this->render($bot, $message."\n\nهیچ تغییری در IP، DNS یا تنظیمات نود انجام نشده است.",
            self::backToDetail($id, $page));
    }

    public function cancel(Nutgram $bot, string $token, string $rawId, string $rawParent): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $id = self::int($rawId, PHP_INT_MAX);
        $parent = self::int($rawParent, 100);
        if ($id === null || $parent === null || preg_match('/\A[0-9a-f]{24}\z/D', $token) !== 1) {
            $this->render($bot, 'درخواست معتبر نیست.', self::backToList());

            return;
        }
        try {
            // The same actor lock as apply() prevents cancellation from claiming
            // success while a confirmation has already been redeemed.
            $cancelled = Cache::lock('arianet:link:redeem:'.$root, 30)->block(2,
                function () use ($root, $token, $id, $parent): bool {
                    $pending = Cache::get(self::pendingKey($root, $token));
                    if (! is_array($pending) || ($pending['parent'] ?? null) !== $parent
                        || ($pending['preview']['serverId'] ?? null) !== $id) {
                        return false;
                    }
                    Cache::forget(self::pendingKey($root, $token));

                    return true;
                });
        } catch (Throwable) {
            $this->render($bot, 'لغو تأیید ممکن نشد؛ وضعیت ارتباط را بررسی کنید.',
                self::backToDetail($id, $parent));

            return;
        }
        $this->render($bot, $cancelled
            ? 'عملیات لغو شد؛ ارتباط ثبت‌شده تغییری نکرد.'
            : 'این تأیید قبلاً استفاده شده یا منقضی شده است.',
            self::backToDetail($id, $parent));
    }

    /** @param array<string,mixed> $preview */
    private function confirmScreen(Nutgram $bot, int $root, array $preview, int $parent): void
    {
        // 96-bit one-time nonce keeps cancellation callbacks below Telegram's 64-byte limit.
        $token = bin2hex(random_bytes(12));
        Cache::put(self::pendingKey($root, $token),
            ['preview' => $preview, 'parent' => $parent], now()->addMinutes(10));
        $id = $preview['serverId'];
        $unlink = $preview['mode'] === 'unlink';
        $warning = $unlink
            ? '⚠️ فقط ارتباط داخلی حذف می‌شود. سرور و نود دست‌نخورده می‌مانند.'
            : match ($preview['match']) {
                'match' => '✅ IPv4 دو طرف یکسان است.',
                'domain' => '⚠️ آدرس نود دامنه است؛ IP مقصد به‌صورت خودکار تأیید نشده. قبل از اتصال بررسی کنید.',
                default => '⚠️ IP نود با IP سرور متفاوت است؛ فقط اگر از هویت نود مطمئن هستید تأیید کنید.',
            };
        $fields = [['سرور', $preview['cloudName']]];
        if ($unlink) {
            $fields[] = ['ارتباط فعلی', 'نود متصل'];
        } else {
            $fields[] = ['نود انتخاب‌شده', $preview['nodeName']];
            $fields[] = ['IPv4 سرور', $preview['cloudIpv4']];
            $fields[] = ['آدرس نود', $preview['nodeAddress']];
            if ($preview['oldNodeUuid'] !== null) {
                $fields[] = ['نوع تغییر', 'جایگزینی ارتباط قبلی'];
            }
        }
        $rows = '';
        $simple = '<b>'.($unlink ? '⛓️ قطع ارتباط' : '🔗 تأیید اتصال نود').'</b>';
        foreach ($fields as [$name, $value]) {
            $rows .= self::row($name, $value);
            $simple .= "\n".TelegramUi::escape($name).': '.TelegramUi::escape($value);
        }
        $buttons = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make(
                $unlink ? '⛓️ تأیید قطع ارتباط' : '✅ تأیید اتصال',
                callback_data: 'ari3:apply:'.$token,
                style: $unlink || $preview['match'] !== 'match' ? ButtonStyle::DANGER : ButtonStyle::SUCCESS,
            ))
            ->addRow(InlineKeyboardButton::make('◀️ انصراف',
                callback_data: 'ari3:cancel:'.$token.':'.$id.':'.$parent));
        $rich = '<h2>'.($unlink ? '⛓️ قطع ارتباط' : '🔗 تأیید اتصال نود').'</h2>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .'<p>'.TelegramUi::escape($warning).'</p>'
            .'<footer>این تأیید فقط یک‌بار و تا ۱۰ دقیقه معتبر است. هیچ تنظیماتی در سرویس‌دهنده تغییر نمی‌کند.</footer>';
        $this->render($bot, $simple."\n\n".TelegramUi::escape($warning), $buttons, $rich);
    }

    private function root(Nutgram $bot): ?int
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->actors->handle($id);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function render(Nutgram $bot, string $fallback, InlineKeyboardMarkup $buttons, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $buttons);

                return;
            } catch (TelegramException) {
                // Same confirmed state, copy and return actions on older clients.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $fallback, reply_markup: $buttons, parse_mode: 'HTML');
    }

    private static function retryCandidates(int $id, int $parent, int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('🔄 تلاش مجدد',
                callback_data: 'ari3:nodes:'.$id.':'.$parent.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور',
                callback_data: 'ari2:view:'.$id.':'.$parent));
    }

    private static function backToDetail(int $id, int $parent): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ جزئیات سرور',
                callback_data: 'ari2:view:'.$id.':'.$parent));
    }

    private static function backToList(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ سرورهای ابری', callback_data: 'ari2:list:1'));
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
            .TelegramUi::escape($value).'</b></td></tr>';
    }

    private static function int(string $input, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $input) !== 1
            || strlen($input) > strlen((string) $max)) {
            return null;
        }
        $value = filter_var($input, FILTER_VALIDATE_INT);

        return is_int($value) && $value <= $max ? $value : null;
    }

    private static function index(string $input): ?int
    {
        return preg_match('/\A[0-7]\z/D', $input) === 1 ? (int) $input : null;
    }

    private static function candidateKey(int $root, string $token): string
    {
        return 'arianet:link:candidates:'.$root.':'.$token;
    }

    private static function pendingKey(int $root, string $token): string
    {
        return 'arianet:link:pending:'.$root.':'.$token;
    }

    private static function safe(string $text, int $limit): string
    {
        return mb_substr(trim((string) preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $text)), 0, $limit);
    }

    private static function matchIcon(string $match): string
    {
        return match ($match) {
            'match' => '✅',
            'domain' => '🌐',
            default => '⚠️',
        };
    }

    private static function matchText(string $match): string
    {
        return match ($match) {
            'match' => 'IP مطابق',
            'domain' => 'دامنه؛ نیازمند بررسی',
            default => 'IP متفاوت',
        };
    }
}
