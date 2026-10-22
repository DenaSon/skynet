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
        private PlanAdminDrafts $planDrafts,
        private PendingBuilderSelection $pendingBuilder,
        private PendingShopVolume $pendingShop,
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
        $lines = ['📚 محصولات پایه '.config('app.name'), 'تعداد: '.count($products)." | صفحه {$page} از {$pages}", '',
            'محصول موردنظر را برای مشاهده قیمت‌ها یا ویرایش انتخاب کنید.', ''];

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
            'شناسه محصول: '.$product['slug'],
            'وضعیت: '.($product['is_active'] ? '🟢 فعال' : '⏸️ غیرفعال'),
            "محدوده حجم: {$product['min_gb']} تا {$product['max_gb']} گیگ",
            'پلن‌های وابسته: '.$product['managed_plan_count'],
            '',
            '💵 قیمت‌های فروش مستقیم (تومان):',
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
        $lines[] = '💡 غیرفعال‌کردن محصول، آن را از فهرست فروش پنهان می‌کند. سفارش‌های قبلی باقی می‌مانند.';
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

        $this->planDrafts->forget($actor->userId);
        $this->pendingBuilder->forget($actor->userId);
        $this->pendingShop->forget($actor->userId);
        $this->drafts->store($actor->userId, $this->state($operation, $pid, $duration, '', 'step:0', '', $snapshot));
        $this->showStep($bot, $actor->userId);
    }

    /** @return list<array{0:string,1:string,2:bool}> */
    private function fields(string $operation): array
    {
        return match ($operation) {
            'create' => [
                ['شناسه کوتاه انگلیسی محصول', 'vpn-premium', false],
                ['نام محصول', 'اشتراک ویژه', false],
                ['حداقل حجم (گیگابایت)', '1', true],
                ['حداکثر حجم (گیگابایت)', '100', true],
            ],
            'edit' => [
                ['نام محصول', 'اشتراک ویژه', false],
                ['حداقل حجم (گیگابایت)', '1', true],
                ['حداکثر حجم (گیگابایت)', '100', true],
            ],
            'rate' => [
                ['قیمت پایه (تومان)', '50000', true],
                ['قیمت هر گیگ (تومان)', '2000', true],
            ],
            default => [],
        };
    }

    private function showStep(Nutgram $bot, int $actorId): void
    {
        $state = $this->drafts->peek($actorId);
        if ($state === null || ! str_starts_with($state['stage'], 'step:')) {
            return;
        }

        $index = (int) substr($state['stage'], 5);
        $fields = $this->fields($state['operation']);
        if (! isset($fields[$index])) {
            $this->notice($bot, 'فرم معتبر نیست. دوباره شروع کنید.');

            return;
        }

        [$label, $example] = $fields[$index];
        $count = count($fields);
        if ($state['operation'] === 'edit' && $state['id'] > 0) {
            $product = $this->catalog->find($actorId, $state['id']);
            $example = match ($index) {
                0 => (string) $product['name'],
                1 => (string) $product['min_gb'],
                2 => (string) $product['max_gb'],
                default => $example,
            };
        }

        $keyboard = InlineKeyboardMarkup::make();
        if (mb_strlen($example) <= 256) {
            $keyboard->addRow(TelegramUi::copy($example, '📋 کپی مثال'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('⚡ ورود سریع همه اطلاعات', callback_data: 'product:quick'));
        if ($index > 0) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ مرحله قبل', callback_data: 'product:back'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'product:cancel'));

        $title = match ($state['operation']) {
            'create' => 'ساخت محصول',
            'edit' => 'ویرایش محصول',
            'rate' => 'تنظیم قیمت فروش',
            default => 'فرم محصول',
        };
        $this->render($bot, "📝 {$title} — مرحله ".($index + 1)." از {$count}\n\n"
            .htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ."\n\nمثال: ".TelegramUi::code($example)
            ."\n\nلطفاً فقط همین مقدار را بفرستید.",
            $keyboard, true);
    }

    public function back(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $s = $this->drafts->peek($actor->userId);
        if ($s === null) {
            $this->browse($bot);

            return;
        }

        $index = str_starts_with($s['stage'], 'step:') ? (int) substr($s['stage'], 5) : 0;
        $parts = $s['input'] === '' ? [] : explode(' | ', $s['input']);
        if ($index > 0) {
            array_pop($parts);
            $s['input'] = implode(' | ', $parts);
            $s['stage'] = 'step:'.($index - 1);
        } else {
            $s['input'] = '';
            $s['stage'] = 'step:0';
        }
        $s['token'] = '';
        $this->drafts->store($actor->userId, $s);
        $this->showStep($bot, $actor->userId);
    }

    public function quick(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $s = $this->drafts->peek($actor->userId);
        if ($s === null || $s['stage'] === 'preview') {
            $this->notice($bot, 'فرم منقضی شده است. دوباره شروع کنید.');

            return;
        }

        $s['input'] = '';
        $s['stage'] = 'input';
        $this->drafts->store($actor->userId, $s);
        $example = match ($s['operation']) {
            'create' => 'vpn-extra | اشتراک ویژه | 1 | 100',
            'edit' => $this->editExample($actor->userId, $s['id']),
            'rate' => '50000 | 2000',
            default => '',
        };
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(TelegramUi::copy($example))
            ->addRow(InlineKeyboardButton::make('◀️ راهنمای مرحله‌ای', callback_data: 'product:back'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'product:cancel'));
        $this->render($bot, "⚡ ورود سریع\n\nیک پیام با همین قالب بفرستید:\n"
            .TelegramUi::code($example)."\n\nمقادیر را با علامت | جدا کنید.",
            $keyboard, true);
    }

    private function editExample(int $actorId, int $id): string
    {
        $p = $this->catalog->find($actorId, $id);

        return "{$p['name']} | {$p['min_gb']} | {$p['max_gb']}";
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

        if (str_starts_with($s['stage'], 'step:') && ! str_contains($text, '|')) {
            $index = (int) substr($s['stage'], 5);
            $labels = $this->fields($s['operation']);
            if (! isset($labels[$index])) {
                $this->notice($bot, 'فرم معتبر نیست. دوباره شروع کنید.');

                return true;
            }

            $value = trim($text);
            if ($value === '' || mb_strlen($value) > 120
                || preg_match('/[\r\n\x00-\x1f\x7f|]/u', $value) === 1
                || ($labels[$index][2] && preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $value) !== 1)) {
                $this->notice($bot, 'این مقدار درست نیست. لطفاً دوباره وارد کنید.');

                return true;
            }

            $parts = $s['input'] === '' ? [] : explode(' | ', $s['input']);
            $parts[] = $value;
            $text = implode(' | ', $parts);
            if ($index < count($labels) - 1) {
                $this->drafts->store($actor->userId, $this->state(
                    $s['operation'], $s['id'], $s['days'], $text, 'step:'.($index + 1), '', $s['snapshot'],
                ));
                $this->showStep($bot, $actor->userId);

                return true;
            }
        } elseif ($s['stage'] !== 'input' && ! str_starts_with($s['stage'], 'step:')) {
            $this->notice($bot, 'اطلاعات آماده تأیید است. برای ادامه، تأیید یا انصراف را بزنید.');

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
            $this->notice($bot, 'مقادیر درست نیست یا اطلاعات تغییر کرده است. اصلاح کنید یا فرم را دوباره باز کنید.');

            return true;
        }

        $token = bin2hex(random_bytes(12));
        $this->drafts->store($actor->userId, $this->state(
            $s['operation'], $s['id'], $s['days'], $text, 'preview', $token, $s['snapshot'],
        ), true);

        $name = $s['operation'] === 'rate' ? 'تعرفه '.$s['days'].' روزه' : 'محصول پایه';
        $this->render($bot, "🔎 پیش‌نمایش {$name}\n\n".$this->safe($text)
            ."\n\nاگر اطلاعات درست است، ثبت را تأیید کنید."
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

        $this->planDrafts->forget($actor->userId);
        $this->pendingBuilder->forget($actor->userId);
        $this->pendingShop->forget($actor->userId);
        $token = bin2hex(random_bytes(12));
        $this->drafts->store($actor->userId,
            $this->state($operation, $pid, $duration, '', 'preview', $token, $s), true);
        $label = match ($operation) {
            'enable' => 'فعال‌سازی محصول',
            'disable' => 'غیرفعال‌سازی محصول (همه پلن‌ها و خریدهای جدید مرتبط مخفی می‌شوند)',
            'rate-enable' => 'فعال‌سازی قیمت فروش مستقیم',
            'rate-disable' => 'توقف قیمت فروش مستقیم',
        };
        $this->render($bot, "⚠️ تأیید نهایی\n\n{$label}\n"
            .'محصول: '.$this->safe($item['name'])."\n"
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
            $this->notice($bot, 'اطلاعات در این فاصله تغییر کرده است. از فهرست دوباره شروع کنید.');

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

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, bool $html = false): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, $html ? 'HTML' : null);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: $html ? 'HTML' : null);
    }
}
