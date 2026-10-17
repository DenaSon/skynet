<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManageAdminNodeOperations;
use App\Modules\Provisioning\Application\ManageAdminNodes;
use Illuminate\Support\Facades\Cache;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** NODE-02: one private, short-lived confirmation per single-node mutation. */
final readonly class AdminNodeOperationsUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ManageAdminNodes $nodes,
        private ManageAdminNodeOperations $operations,
        private MenuRenderer $menus,
    ) {}

    public function prepare(Nutgram $bot, string $uuid, string $mode): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (! in_array($mode, ['edit', 'enable', 'disable', 'restart', 'reset', 'delete'], true)) {
            $this->notice($bot, 'عملیات معتبر نیست.');

            return;
        }
        if (config('services.remnawave.writes_enabled') !== true) {
            $this->notice($bot, 'تغییر تنظیمات سرورها فعلاً غیرفعال است.');

            return;
        }

        try {
            $node = $this->nodes->one($root, $uuid);
        } catch (Throwable) {
            $this->notice($bot, 'نود انتخاب‌شده پیدا نشد یا اطلاعات آن در دسترس نیست.');

            return;
        }

        if ($mode === 'enable' && ! $node['disabled'] || $mode === 'disable' && $node['disabled']) {
            $this->notice($bot, 'وضعیت نود همین حالا مطابق درخواست شماست.');

            return;
        }
        if ($mode === 'delete' && ! $node['disabled']) {
            $this->notice($bot, 'برای حذف نود، ابتدا آن را غیرفعال کنید.');

            return;
        }

        Cache::put($this->key($root), [
            'stage' => $mode === 'edit' ? 'edit' : 'confirm',
            'mode' => $mode,
            'node' => $node,
            'changes' => [],
            'token' => $mode === 'edit' ? '' : bin2hex(random_bytes(16)),
        ], now()->addMinutes(3));

        if ($mode === 'edit') {
            $this->render($bot,
                "✏️ ویرایش نود\n\nنود: ".$this->safe($node['name'])
                ."\n\nنام جدید و آدرس جدید را در یک پیام بفرستید:\n"
                ."Germany-01 | node.example.com\n\n"
                .'آدرس باید IP یا دامنه، بدون پورت و http باشد.',
                $this->cancelKeyboard(),
            );

            return;
        }

        $this->confirmation($bot, $node['name'], $mode, Cache::get($this->key($root))['token']);
    }

    /** Consume only NODE-02 pending edit input; otherwise leave legacy text routes intact. */
    public function text(Nutgram $bot, string $input): bool
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return false;
        }
        $actor = $this->actors->handle($id);
        if ($actor?->canManageRoles() !== true) {
            return false;
        }

        $root = $actor->userId;
        $state = Cache::get($this->key($root));
        if (! is_array($state) || ($state['stage'] ?? null) !== 'edit') {
            return false;
        }
        $parts = explode('|', $input);
        if (strlen($input) > 330 || count($parts) !== 2) {
            $this->render($bot, 'نام و آدرس را با یک | جدا کنید.', $this->cancelKeyboard());

            return true;
        }
        $name = trim($parts[0]);
        $address = trim($parts[1]);
        try {
            $this->nodes->validateIdentity($root, $name, $address);
        } catch (Throwable) {
            $this->render($bot, 'نام یا آدرس معتبر نیست؛ دوباره وارد کنید.', $this->cancelKeyboard());

            return true;
        }

        $state['changes'] = ['name' => $name, 'address' => $address];
        $state['stage'] = 'confirm';
        $state['token'] = bin2hex(random_bytes(16));
        Cache::put($this->key($root), $state, now()->addMinutes(3));
        $this->confirmation($bot, $state['node']['name'], 'edit', $state['token'], $state['changes']);

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1) {
            $this->notice($bot, 'تأیید معتبر نیست یا منقضی شده است.');

            return;
        }

        $key = $this->key($root);
        $state = Cache::lock($key.':lock', 10)->block(2, static function () use ($key, $token): mixed {
            $value = Cache::get($key);
            if (! is_array($value) || ($value['stage'] ?? null) !== 'confirm'
                || ! is_string($value['token'] ?? null) || ! hash_equals($value['token'], $token)) {
                return null;
            }

            // Before the first remote request, atomically consume this ticket.
            Cache::forget($key);

            return $value;
        });
        if (! is_array($state)) {
            $this->notice($bot, 'این تأیید قبلاً استفاده شده یا منقضی است.');

            return;
        }

        try {
            $this->operations->perform($root, $state['mode'], $state['node'], $state['changes']);
            $this->render($bot,
                "✅ درخواست ثبت شد.\n\nبرای مشاهده نتیجه نهایی، فهرست نودها را تازه‌سازی کنید.",
                $this->listKeyboard(),
            );
        } catch (Throwable) {
            // Includes timeout or unverified response AFTER the remote write.
            // Always require a read-only refresh; never retry this ticket.
            $this->render($bot,
                "نتیجه عملیات قطعی نیست.\n\nقبل از درخواست دوباره، وضعیت نود را بررسی کنید.",
                $this->listKeyboard(),
            );
        }
    }

    public function cancel(Nutgram $bot): void
    {
        $root = $this->root($bot);
        if ($root === null) {
            return;
        }
        $state = Cache::get($this->key($root));
        Cache::forget($this->key($root));
        $uuid = is_array($state) ? ($state['node']['uuid'] ?? null) : null;
        if (is_string($uuid)) {
            app(AdminNodesUi::class)->detail($bot, $uuid);

            return;
        }
        app(AdminNodesUi::class)->browse($bot);
    }

    /** @param array{name:string,address:string}|array{} $changes */
    private function confirmation(Nutgram $bot, string $name, string $mode, string $token, array $changes = []): void
    {
        $label = match ($mode) {
            'edit' => 'ویرایش مشخصات',
            'enable' => 'فعال‌سازی',
            'disable' => 'غیرفعال‌سازی',
            'restart' => 'راه‌اندازی مجدد',
            'reset' => 'صفرکردن آمار ترافیک',
            'delete' => 'حذف دائمی',
            default => 'تغییر',
        };
        $detail = $mode === 'edit'
            ? "\nنام جدید: ".$this->safe($changes['name'])."\nآدرس جدید: ".$this->safe($changes['address'])
            : '';
        $warning = $mode === 'delete' ? "\n\n⚠️ این کار برگشت‌پذیر نیست و ممکن است سرویس کاربران را قطع کند." : '';

        $this->render($bot,
            '⚠️ تأیید '.$label."\n\nنود: ".$this->safe($name).$detail.$warning
            ."\n\nفقط در صورت اطمینان تأیید کنید. اعتبار: ۳ دقیقه.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'node2:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'node2:cancel')),
        );
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

        return $actor->userId;
    }

    private function key(int $root): string
    {
        return TelegramBotContext::current()->cacheKey('node02:draft:'.$root);
    }

    private function safe(string $value): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value)), 0, 80);
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, '🖥️ مدیریت نودها'."\n\n".$message, $this->listKeyboard());
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '❌ انصراف', callback_data: 'node2:cancel',
        ));
    }

    private function listKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ فهرست نودها', callback_data: 'node1:list:1',
        ));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard);

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}
