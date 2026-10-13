<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\ManageBaseCatalog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/**
 * Manage F09 base products + duration rates using one-shot, actor-bound
 * confirmations. Parent plan catalog and existing paid/legacy orders untouched.
 */
final readonly class ProductAdminUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private ManageBaseCatalog $catalog,
        private ProductAdminForms $forms,
        private ProductAdminDrafts $drafts,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->drafts->forget($actor->userId);
        $products = $this->catalog->all($actor->userId);
        $pages = max(1, (int) ceil(count($products) / 5));
        $page = min(max($page, 1), $pages);
        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['📚 محصولات پایه SkyNet', 'تعداد: '.count($products)." | صفحه {$page} از {$pages}", '',
            'این محصولات، والد پلن‌های جدید و تعرفه‌های خرید قدیمی F09 هستند.', ''];

        foreach (array_slice($products, ($page - 1) * 5, 5) as $entry) {
            $status = $entry['is_active'] ? '🟢' : '⚪';
            $lines[] = "{$status} #{$entry['id']} — ".$this->safe($entry['name'])." ({$entry['slug']})";
            $keyboard->addRow(InlineKeyboardButton::make(
                "#{$entry['id']} · ".mb_substr($this->safe($entry['name']), 0, 27),
                callback_data: 'product:view:'.$entry['id'],
            ));
        }

        if ($products === []) {
            $lines[] = 'هنوز محصول پایه‌ای تعریف نشده است.';
        }
        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'product:list:'.($page - 1)));
        }
        if ($page < $pages) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'product:list:'.($page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('➕ محصول پایه جدید', callback_data: 'product:form:create:0:0'));
        $keyboard->addRow(InlineKeyboardButton::make('📦 مدیریت پلن‌ها', callback_data: 'plan:list:1'));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        try {
            $product = $this->catalog->find($actor->userId, $this->id($id));
        } catch (ValidationException) {
            $this->notice($bot, 'محصول وجود ندارد یا شناسه نامعتبر است.');

            return;
        }

        $pid = $product['id'];
        $lines = [
            '📦 محصول پایه #'.$pid,
            '',
            'نام: '.$this->safe($product['name']),
            'شناسه ثابت (slug): '.$product['slug'],
            'وضعیت: '.($product['is_active'] ? '🟢 فعال' : '⏸️ غیرفعال'),
            "حجم F09: {$product['min_gb']} تا {$product['max_gb']} گیگ",
            'پلن‌های وابسته: '.$product['managed_plan_count'],
            '',
            '💵 تعرفه‌های قدیمی F09 (تومان):',
        ];
        $rates = collect($product['rates'])->keyBy('duration_days');
        $keyboard = InlineKeyboardMarkup::make();

        foreach ([15, 30, 60, 90] as $days) {
            $rate = $rates->get($days);
            if ($rate === null) {
                $lines[] = "{$days} روز: تعریف نشده";
            } else {
                $state = $rate['is_active'] ? '🟢' : '⏸️';
                $lines[] = "{$state} {$days} روز: پایه ".number_format(intdiv($rate['base_price_rials'] + 9, 10))
                    .' + هر GB '.number_format(intdiv($rate['per_gb_rials'] + 9, 10));
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                "💵 تعرفه {$days} روزه".($rate === null ? ' (جدید)' : ''),
                callback_data: "product:form:rate:{$pid}:{$days}",
            ));

            if ($rate !== null) {
                $operation = $rate['is_active'] ? 'rate-disable' : 'rate-enable';
                $keyboard->addRow(InlineKeyboardButton::make(
                    $rate['is_active'] ? "⏸️ توقف {$days} روزه" : "✅ انتشار {$days} روزه",
                    callback_data: "product:status:{$operation}:{$pid}:{$days}",
                ));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('✏️ ویرایش مشخصات', callback_data: "product:form:edit:{$pid}:0"));
        $operation = $product['is_active'] ? 'disable' : 'enable';
        $keyboard->addRow(InlineKeyboardButton::make(
            $product['is_active'] ? '⏸️ غیرفعال‌کردن محصول' : '✅ فعال‌کردن محصول',
            callback_data: "product:status:{$operation}:{$pid}:0",
        ));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ محصولات پایه', callback_data: 'product:list:1'));

        $lines[] = '';
        $lines[] = '⚠️ تغییر محصول فعال بر Catalog و خرید F09 اثر می‌گذارد. تاریخچه سفارش‌ها و پلن‌ها حذف نمی‌شوند.';
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function form(Nutgram $bot, string $operation, string $id, string $days): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (! in_array($operation, ['create', 'edit', 'rate'], true)) {
            $this->notice($bot, 'فرم ناشناخته است.');

            return;
        }

        $pid = $this->idOrZero($id);
        $duration = $this->idOrZero($days);

        try {
            $snapshot = $this->catalog->snapshot($actor->userId, $operation, $pid, $duration);
            $product = $operation === 'create' ? null : $this->catalog->find($actor->userId, $pid);
        } catch (ValidationException) {
            $this->notice($bot, 'محصول یا تعرفه معتبر نیست.');

            return;
        }

        $this->drafts->store($actor->userId, $this->state($operation, $pid, $duration, '', 'input', '', $snapshot));
        $existing = $operation === 'rate'
            ? collect($product['rates'])->firstWhere('duration_days', $duration)
            : null;
        $hint = match ($operation) {
            'create' => "۴ فیلد: slug | name | minGB | maxGB\nمثال:\nvpn-access-2 | سرویس ویژه | 1 | 100",
            'edit' => "۳ فیلد: name | minGB | maxGB\nشناسه ثابت و قابل تغییر نیست.\nمتن فعلی:\n{$product['name']} | {$product['min_gb']} | {$product['max_gb']}",
            'rate' => "تعرفه {$duration} روزه: baseToman | perGBToman\nمثال:\n50000 | 2000".($existing === null
                ? ''
                : "\nمقادیر فعلی (تقریبی):\n".intdiv($existing['base_price_rials'], 10)
                    .' | '.intdiv($existing['per_gb_rials'], 10)),
        };

        $this->render($bot, "📝 فرم مدیریت محصول\n\n{$hint}\n\nفقط یک خط با علامت | ارسال کنید (اعداد انگلیسی)."
            ."\nفرم ۱۰ دقیقه معتبر است. پس از ثبت، محصول/تعرفه ابتدا غیرفعال خواهد بود."
            ."\n\n⚠️ تغییر تعرفه فعال، آن را تا انتشار مجدد غیرفعال می‌کند.",
            InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'product:cancel')));
    }

    public function text(Nutgram $bot, string $text): bool
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return false;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            return false;
        }

        $s = $this->drafts->peek($actor->userId);
        if ($s === null || str_starts_with(trim($text), '/')) {
            return false;
        }

        if (! $actor->canManageRoles()) {
            $this->drafts->forget($actor->userId);
            $this->menus->denyAccess($bot);

            return true;
        }

        if ($s['stage'] !== 'input') {
            $this->notice($bot, 'فرم منتظر تأیید نهایی است؛ دکمه تأیید یا انصراف را بزنید.');

            return true;
        }

        try {
            $values = $this->forms->parse($s['operation'], $text);
            $this->catalog->validate($actor->userId, $s['operation'], $s['id'], $s['days'], $values);
            if (! hash_equals($s['snapshot'],
                $this->catalog->snapshot($actor->userId, $s['operation'], $s['id'], $s['days']))) {
                throw ValidationException::withMessages(['catalog' => 'Stale form.']);
            }
        } catch (ValidationException) {
            $this->notice($bot, 'ورودی یا نسخه محصول معتبر نیست. اصلاح و دوباره ارسال کنید یا فرم جدید باز کنید.');

            return true;
        }

        $token = bin2hex(random_bytes(12));
        $this->drafts->store($actor->userId, $this->state(
            $s['operation'], $s['id'], $s['days'], $text, 'preview', $token, $s['snapshot'],
        ), true);

        $name = $s['operation'] === 'rate' ? 'تعرفه '.$s['days'].' روزه' : 'محصول پایه';
        $this->render($bot, "🔎 پیش‌نمایش {$name}\n\n".$this->safe($text)
            ."\n\n⚠️ اعمال این عملیات در دیتابیس واقعی و ثبت Audit انجام می‌شود."
            ."\nمحصول/تعرفه جدید یا ویرایش‌شده غیرفعال خواهد ماند."
            ."\nاین تأیید فقط یک‌بار و تا ۳ دقیقه معتبر است.",
            $this->confirmButtons($token));
        return true;
    }

    public function status(Nutgram $bot, string $operation, string $id, string $days): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (! in_array($operation, ['enable', 'disable', 'rate-enable', 'rate-disable'], true)) {
            $this->notice($bot, 'عملیات معتبر نیست.');

            return;
        }

        $pid = $this->idOrZero($id);
        $duration = $this->idOrZero($days);
        try {
            $s = $this->catalog->snapshot($actor->userId, $operation, $pid, $duration);
            $item = $this->catalog->find($actor->userId, $pid);
            $this->catalog->validate($actor->userId, $operation, $pid, $duration, []);
        } catch (ValidationException) {
            $this->notice($bot, 'عملیات مجاز نیست یا وضعیت محصول/تعرفه تغییر کرده است.');

            return;
        }

        $token = bin2hex(random_bytes(12));
        $this->drafts->store($actor->userId,
            $this->state($operation, $pid, $duration, '', 'preview', $token, $s), true);
        $label = match ($operation) {
            'enable' => 'فعال‌سازی محصول',
            'disable' => 'غیرفعال‌سازی محصول (همه پلن‌ها و خریدهای جدید مرتبط مخفی می‌شوند)',
            'rate-enable' => 'انتشار تعرفه F09',
            'rate-disable' => 'توقف تعرفه F09',
        };
        $this->render($bot, "⚠️ تأیید نهایی\n\n{$label}\n"
            ."محصول: ".$this->safe($item['name'])."\n"
            ."شناسه: {$item['slug']}"
            .($duration ? "\nمدت تعرفه: {$duration} روز" : '')
            ."\n\nتأیید یک‌بارمصرف با اعتبار ۳ دقیقه. سفارش‌ها و پلن‌های قبلی پاک نمی‌شوند.",
            $this->confirmButtons($token));
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            $this->notice($bot, 'شناسه تأیید معتبر نیست.');

            return;
        }

        $s = $this->drafts->consume($actor->userId, $token);
        if ($s === null) {
            $this->notice($bot, 'درخواست منقضی، لغو یا قبلاً مصرف شده است.');

            return;
        }

        try {
            $values = $s['input'] === '' ? [] : $this->forms->parse($s['operation'], $s['input']);
            $id = $this->catalog->apply($actor->userId, $s['operation'], $s['id'], $s['days'], $values, $s['snapshot']);
        } catch (ValidationException|AuthorizationException|QueryException) {
            $this->notice($bot, 'ثبت انجام نشد: وضعیت محصول یا تعرفه تغییر کرده است. از فهرست دوباره تلاش کنید.');

            return;
        }

        $this->detail($bot, (string) $id);
    }

    public function cancel(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->drafts->forget($actor->userId);
        $this->browse($bot);
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    /** @return array{operation:string,id:int,days:int,input:string,stage:string,token:string,snapshot:string} */
    private function state(string $operation, int $id, int $days, string $input, string $stage, string $token, string $snapshot): array
    {
        return compact('operation', 'id', 'days', 'input', 'stage', 'token', 'snapshot');
    }

    private function id(string $id): int
    {
        if (preg_match('/\A[1-9][0-9]{0,13}\z/D', $id) !== 1) {
            throw ValidationException::withMessages(['id' => 'Invalid product ID.']);
        }

        return (int) $id;
    }

    private function idOrZero(string $id): int
    {
        return preg_match('/\A(?:0|[1-9][0-9]{0,13})\z/D', $id) === 1 ? (int) $id : 0;
    }

    private function safe(string $s): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $s));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "📚 محصولات پایه\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ محصولات', callback_data: 'product:list:1')));
    }

    private function confirmButtons(string $token): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: 'product:apply:'.$token))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'product:cancel'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $bot->editMessageText(text: $text, reply_markup: $keyboard);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard);
    }
}
