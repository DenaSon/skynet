<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\ManagePlanPricing;
use App\Modules\Sales\Application\ManagePlans;
use App\Modules\Sales\Application\PlanSummary;
use App\Modules\Sales\Application\PlanTariffSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final readonly class PlanAdminUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private ManagePlans $plans,
        private ManagePlanPricing $pricing,
        private PlanAdminDrafts $drafts,
        private ProductAdminDrafts $productDrafts,
        private PlanAdminForms $forms,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->productDrafts->forget($actor->userId);
        $page = max(1, min(10000, $page));
        $all = $this->plans->all($actor->userId);
        $pages = max(1, (int) ceil(count($all) / 5));
        $page = min($page, $pages);
        $entries = array_slice($all, ($page - 1) * 5, 5);

        $lines = ['📦 مدیریت پلن‌های SkyNet', 'تعداد: '.count($all)." | صفحه {$page} از {$pages}", '',
            'پلن‌های دارای قیمت فعال نمایش داده می‌شوند. خرید پلن‌های جدید فعلاً فعال نیست.'];
        $keyboard = InlineKeyboardMarkup::make();

        foreach ($entries as $entry) {
            $lines[] = $this->statusIcon($entry->status)." #{$entry->id} — ".$this->safe($entry->name).' ('.TelegramUi::planMode($entry->mode).')';
            $keyboard->addRow(InlineKeyboardButton::make(
                '#'.$entry->id.' · '.mb_substr($this->safe($entry->name), 0, 32),
                callback_data: 'plan:view:'.$entry->id,
            ));
        }

        if ($entries === []) {
            $lines[] = 'هنوز پلنی تعریف نشده است.';
        }

        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'plan:list:'.($page - 1)));
        }
        if ($page < $pages) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'plan:list:'.($page + 1)));
        }

        $keyboard->addRow(
            InlineKeyboardButton::make('➕ پلن ثابت', callback_data: 'plan:begin:preset:0'),
            InlineKeyboardButton::make('➕ پلن‌ساز نماینده', callback_data: 'plan:begin:builder:0'),
        );
        $keyboard->addRow(InlineKeyboardButton::make('📚 محصولات پایه', callback_data: 'plan:products'));
        $keyboard->addRow(InlineKeyboardButton::make('📋 نمونه پلن‌های قابل کپی', callback_data: 'plan:examples'));

        $keyboard->addRow(InlineKeyboardButton::make('◀️ مدیریت', callback_data: 'menu:admin'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    /**
     * Copyable Telegram HTML code snippets. The active base-product slug is
     * taken from authorized Sales catalog data, never guessed.
     */
    public function examples(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $products = $this->plans->products($actor->userId);
        $active = collect($products)->first(static fn (array $entry): bool => $entry['active']);
        if ($active === null) {
            $this->notice($bot, 'ابتدا یک محصول پایه فعال ایجاد کنید؛ بدون آن نمی‌توان پلن را ثبت کرد.');

            return;
        }

        $slug = (string) $active['slug'];
        $examples = [
            ['اقتصادی ۱۰ گیگ / ۳۰ روز', "{$slug} | economy-10 | اقتصادی ۱۰ گیگ | hybrid | both | 10 | 30"],
            ['استاندارد ۳۰ گیگ / ۳۰ روز', "{$slug} | standard-30 | استاندارد ۳۰ گیگ | hybrid | both | 30 | 30"],
            ['حرفه‌ای ۶۰ گیگ / ۳۰ روز', "{$slug} | premium-60 | حرفه‌ای ۶۰ گیگ | hybrid | both | 60 | 30"],
            ['نامحدود زمانی ۳۰ روز', "{$slug} | unlimited-30 | نامحدود ۳۰ روزه | time | both | - | 30"],
            ['پلن‌ساز نماینده', "{$slug} | reseller-builder | سفارشی نماینده | hybrid | 1 | 100 | 15 | 90"],
        ];

        $lines = ['📋 <b>نمونه‌های آماده پلن</b>', 'روی دکمه هر نمونه بزنید تا کپی شود.', ''];
        $copies = InlineKeyboardMarkup::make();
        foreach ($examples as [$title, $form]) {
            $lines[] = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = TelegramUi::code($form);
            $copies->addRow(TelegramUi::copy($form, '📋 '.$title));
            $lines[] = '';
        }
        $lines[] = 'ابتدا دکمه ساخت پلن مربوطه را بزنید، سپس فقط متن داخل کد را ارسال کنید.';
        $lines[] = 'اگر شناسه پلن قبلاً استفاده شده، شناسه جدیدی انتخاب کنید. قیمت‌ها جداگانه تنظیم می‌شوند.';

        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
        }

        $bot->sendMessage(
            text: implode("\n", $lines),
            parse_mode: 'HTML',
            reply_markup: $copies->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'plan:list:1')),
        );
    }

    public function products(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $entries = $this->plans->products($actor->userId);
        $lines = ['📚 محصولات پایه', 'برای ساخت پلن، شناسه یکی از محصولات فعال را انتخاب کنید.', ''];
        foreach (array_slice($entries, 0, 25) as $product) {
            $lines[] = ($product['active'] ? '🟢 ' : '⚪ ').$product['slug'].' — '.$this->safe($product['name']);
        }

        if ($entries === []) {
            $lines[] = 'هنوز محصولی ندارید. از بخش مدیریت محصولات، محصول جدید بسازید.';
        }

        if (count($entries) > 25) {
            $lines[] = 'فقط ۲۵ مورد اول نمایش داده شده است.';
        }

        $this->render($bot, implode("\n", $lines), InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('⚙️ مدیریت محصولات پایه', callback_data: 'menu:admin:products'))
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت پلن‌ها', callback_data: 'plan:list:1')));
    }

    public function detail(Nutgram $bot, string $id): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $planId = $this->id($id);
        if ($planId === null) {
            $this->notice($bot, 'شناسه پلن نامعتبر است.');

            return;
        }

        try {
            $plan = $this->plans->find($actor->userId, $planId);
            $price = $this->pricing->find($actor->userId, $planId);
        } catch (ValidationException) {
            $this->notice($bot, 'پلن پیدا نشد یا حذف شده است.');

            return;
        }

        $gb = $plan->trafficGb === null ? 'نامحدود' : $plan->trafficGb.' GB';
        $days = $plan->durationDays === null ? 'انتخابی' : $plan->durationDays.' روز';
        $description = [
            $this->statusIcon($plan->status)." #{$plan->id} — ".$this->safe($plan->name),
            "شناسه: {$plan->slug} | محصول: {$plan->productSlug}",
            'نوع: '.TelegramUi::planKind($plan->kind).' | حالت: '.TelegramUi::planMode($plan->mode).' | مخاطب: '.TelegramUi::audience($plan->audience),
            "حجم: {$gb} | مدت: {$days}",
            'محدوده حجم: '.($plan->minGb ?? '-').' تا '.($plan->maxGb ?? '-'),
            "محدوده روز: {$plan->minDays} تا {$plan->maxDays}",
            "اولویت: {$plan->sortOrder} | ویژه: ".($plan->featured ? 'بله' : 'خیر'),
            '',
            '💰 تعرفه: '.($price === null
                ? 'تعریف نشده'
                : ($price->method === 'fixed' ? 'ثابت' : 'محاسباتی').' / '.TelegramUi::status($price->status).(
                    $price->configuredPlanRevision === $plan->revision ? '' : ' ⚠️ قیمت نیاز به تنظیم دوباره دارد'
                )),
            $price === null ? '' : $this->tariffText($price),
            '',
            'ℹ️ خرید این پلن فعلاً فعال نیست.',
        ];

        $keyboard = InlineKeyboardMarkup::make();

        if ($plan->status !== 'archived') {
            $keyboard->addRow(InlineKeyboardButton::make('✏️ ویرایش پلن', callback_data: "plan:begin:edit:{$planId}"));
            if ($plan->mode === 'preset') {
                $keyboard->addRow(InlineKeyboardButton::make('💵 تعرفه ثابت', callback_data: "plan:begin:fixed:{$planId}"));
            }
            $keyboard->addRow(InlineKeyboardButton::make('🧮 تعرفه فرمولی', callback_data: "plan:begin:formula:{$planId}"));
        }

        if (in_array($plan->status, ['draft', 'inactive'], true)) {
            $keyboard->addRow(InlineKeyboardButton::make('✅ فعال‌سازی پلن', callback_data: "plan:status:{$planId}:activate"));
            $keyboard->addRow(
                InlineKeyboardButton::make('📦 آرشیو', callback_data: "plan:status:{$planId}:archive"),
                InlineKeyboardButton::make('🗑️ حذف', callback_data: "plan:status:{$planId}:delete"),
            );
        } elseif ($plan->status === 'active') {
            $keyboard->addRow(InlineKeyboardButton::make('⏸️ غیرفعال‌سازی پلن', callback_data: "plan:status:{$planId}:deactivate"));
        } elseif ($plan->status === 'archived') {
            $keyboard->addRow(InlineKeyboardButton::make('🗑️ حذف آرشیوشده', callback_data: "plan:status:{$planId}:delete"));
        }

        if ($price !== null && $plan->status !== 'archived'
            && $price->configuredPlanRevision === $plan->revision) {
            if (in_array($price->status, ['draft', 'inactive'], true)) {
                $keyboard->addRow(InlineKeyboardButton::make('✅ انتشار تعرفه', callback_data: "plan:status:{$planId}:pa"));
            } elseif ($price->status === 'active') {
                $keyboard->addRow(InlineKeyboardButton::make('⏸️ غیرفعال‌سازی تعرفه', callback_data: "plan:status:{$planId}:pd"));
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make('◀️ فهرست پلن‌ها', callback_data: 'plan:list:1'));
        $this->render($bot, implode("\n", $description), $keyboard);
    }

    public function begin(Nutgram $bot, string $type, string $id): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->productDrafts->forget($actor->userId);
        if (! in_array($type, ['preset', 'builder', 'edit', 'fixed', 'formula'], true)) {
            $this->notice($bot, 'فرم پشتیبانی نمی‌شود.');

            return;
        }

        $planId = $type === 'preset' || $type === 'builder' ? 0 : $this->id($id);
        if ($planId === null) {
            $this->notice($bot, 'شناسه پلن نامعتبر است.');

            return;
        }

        $plan = null;
        $price = null;
        if ($planId !== 0) {
            try {
                $plan = $this->plans->find($actor->userId, $planId);
                $price = $this->pricing->find($actor->userId, $planId);
            } catch (ValidationException) {
                $this->notice($bot, 'پلن موجود نیست.');

                return;
            }

            if ($plan->status === 'archived') {
                $this->notice($bot, 'پلن آرشیوشده قابل ویرایش نیست.');

                return;
            }
        }

        $this->drafts->store($actor->userId, [
            'type' => $type, 'plan_id' => $planId,
            'plan_revision' => $plan === null ? 0 : $plan->revision,
            'price_revision' => $price === null ? 0 : $price->revision,
            'form' => '', 'stage' => $type === 'edit' ? 'input' : 'step:0', 'token' => '',
        ]);

        if ($type !== 'edit') {
            $this->showStep($bot, $actor->userId);

            return;
        }

        $current = $plan instanceof PlanSummary ? $plan : throw new \LogicException('Plan missing');
        $example = $this->forms->editLine($current);
        $hint = "ویرایش پلن\n\nمتن فعلی را اصلاح کنید و بفرستید.";

        $keyboard = InlineKeyboardMarkup::make();
        if (mb_strlen($example) <= 256) {
            $keyboard->addRow(TelegramUi::copy($example));
        }
        $keyboard->addRow(InlineKeyboardButton::make('✏️ تغییر یک مورد', callback_data: 'plan:editfields'));
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'plan:cancel'));
        $this->render($bot, "📝 فرم پلن\n\n".htmlspecialchars($hint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ."\n\n📋 نمونه قابل کپی:\n".TelegramUi::code($example)
            ."\n\nمقادیر را در یک پیام بفرستید. برای نامحدود «-» بگذارید. تا ۱۰ دقیقه فرصت دارید.",
            $keyboard, true);
    }

    /**
     * Called by the existing last-resort onText handler, before shop custom GB.
     * True means this text belongs to a pending, owner-bound admin form.
     */
    /** @return list<string> */
    private function editLabels(): array
    {
        return [
            'محصول', 'شناسه پلن', 'نام پلن', 'نوع سرویس', 'حالت پلن',
            'نمایش برای', 'حجم ثابت', 'مدت ثابت', 'حداقل حجم',
            'حداکثر حجم', 'حداقل مدت', 'حداکثر مدت', 'ترتیب نمایش', 'نمایش ویژه',
        ];
    }

    public function editFields(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $state = $this->drafts->peek($actor->userId);
        if ($state === null || $state['type'] !== 'edit' || $state['stage'] === 'preview') {
            $this->notice($bot, 'ابتدا از صفحه پلن، ویرایش را انتخاب کنید.');

            return;
        }

        $plan = $this->plans->find($actor->userId, $state['plan_id']);
        if ($plan->revision !== $state['plan_revision']) {
            $this->notice($bot, 'اطلاعات پلن تغییر کرده است. دوباره وارد ویرایش شوید.');

            return;
        }

        $state['form'] = '';
        $state['stage'] = 'input';
        $this->drafts->store($actor->userId, $state);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_chunk($this->editLabels(), 2, true) as $row) {
            $buttons = [];
            foreach ($row as $index => $label) {
                $buttons[] = InlineKeyboardButton::make($label, callback_data: 'plan:editfield:'.$index);
            }
            $keyboard->addRow(...$buttons);
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت به ویرایش', callback_data: 'plan:begin:edit:'.$state['plan_id']));
        $this->render($bot, "✏️ ویرایش پلن\n\nکدام مورد را می‌خواهید تغییر دهید؟", $keyboard);
    }

    /** @return list<string> */
    private function editChoices(int $index): array
    {
        return match ($index) {
            3 => ['volume', 'time', 'hybrid'],
            4 => ['preset', 'builder'],
            5 => ['public', 'reseller', 'both'],
            13 => ['0', '1'],
            default => [],
        };
    }

    public function editField(Nutgram $bot, string $field): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $state = $this->drafts->peek($actor->userId);
        $labels = $this->editLabels();
        if ($state === null || $state['type'] !== 'edit' || $state['stage'] === 'preview'
            || preg_match('/\A(?:0|[1-9][0-9]{0,2})\z/D', $field) !== 1
            || ! isset($labels[(int) $field])) {
            $this->notice($bot, 'این انتخاب معتبر نیست. دوباره وارد ویرایش شوید.');

            return;
        }

        $plan = $this->plans->find($actor->userId, $state['plan_id']);
        if ($plan->revision !== $state['plan_revision']) {
            $this->notice($bot, 'پلن تغییر کرده است. ویرایش را دوباره شروع کنید.');

            return;
        }

        $parts = explode(' | ', $this->forms->editLine($plan));
        $index = (int) $field;
        if (! isset($parts[$index])) {
            $this->notice($bot, 'ویرایش این مورد ممکن نیست.');

            return;
        }

        $state['form'] = implode(' | ', $parts);
        $state['stage'] = 'editfield:'.$index;
        $this->drafts->store($actor->userId, $state);
        $keyboard = InlineKeyboardMarkup::make();
        foreach (array_chunk($this->editChoices($index), 2) as $row) {
            $buttons = [];
            foreach ($row as $value) {
                $display = match ($index) {
                    3 => TelegramUi::planKind($value),
                    4 => TelegramUi::planMode($value),
                    5 => TelegramUi::audience($value),
                    default => $value === '1' ? 'بله' : 'خیر',
                };
                $buttons[] = InlineKeyboardButton::make($display, callback_data: 'plan:editchoice:'.$value);
            }
            $keyboard->addRow(...$buttons);
        }
        if (mb_strlen($parts[$index]) <= 256) {
            $keyboard->addRow(TelegramUi::copy($parts[$index], '📋 کپی مقدار فعلی'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ سایر مشخصات', callback_data: 'plan:editfields'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'plan:cancel'));

        $this->render($bot, '✏️ ویرایش '.$labels[$index]."\n\nمقدار فعلی: "
            .TelegramUi::code($parts[$index])."\n\nمقدار جدید را ارسال کنید یا از دکمه‌ها انتخاب کنید.",
            $keyboard, true);
    }

    public function editChoice(Nutgram $bot, string $value): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $state = $this->drafts->peek($actor->userId);
        if ($state === null || $state['type'] !== 'edit' || ! str_starts_with($state['stage'], 'editfield:')
            || ! in_array($value, $this->editChoices((int) substr($state['stage'], 10)), true)) {
            $this->notice($bot, 'این گزینه دیگر معتبر نیست.');

            return;
        }

        $this->text($bot, $value);
    }

    /** @return list<array{0:string,1:string,2:bool}> */
    private function wizardFields(string $type): array
    {
        return match ($type) {
            'preset' => [
                ['محصول', 'vpn-access', false],
                ['شناسه کوتاه انگلیسی پلن', 'standard-30', false],
                ['نام پلن', 'استاندارد ۳۰', false],
                ['نوع سرویس', 'hybrid', false],
                ['نمایش برای', 'both', false],
                ['حجم (گیگابایت، برای نامحدود -)', '30', true],
                ['مدت (روز)', '30', true],
            ],
            'builder' => [
                ['محصول', 'vpn-access', false],
                ['شناسه کوتاه انگلیسی پلن', 'reseller-builder', false],
                ['نام پلن', 'پلن سفارشی', false],
                ['نوع سرویس', 'hybrid', false],
                ['حداقل حجم (گیگابایت، برای نامحدود -)', '1', true],
                ['حداکثر حجم (گیگابایت، برای نامحدود -)', '100', true],
                ['حداقل مدت (روز)', '15', true],
                ['حداکثر مدت (روز)', '90', true],
            ],
            'fixed' => [
                ['قیمت ثابت (تومان)', '120000', true],
                ['حداقل قیمت (تومان)', '60000', true],
                ['تخفیف نماینده (درصد)', '5', true],
            ],
            'formula' => [
                ['قیمت پایه (تومان)', '50000', true],
                ['قیمت هر گیگ (تومان)', '10000', true],
                ['قیمت هر روز (تومان)', '2000', true],
                ['حداقل قیمت (تومان)', '20000', true],
                ['تخفیف نماینده (درصد)', '5', true],
            ],
            default => [],
        };
    }

    /** @return list<string> */
    private function wizardChoices(string $type, int $index, int $rootId): array
    {
        if (in_array($type, ['preset', 'builder'], true) && $index === 0) {
            return array_values(array_map(
                static fn (array $product): string => (string) $product['slug'],
                array_filter($this->plans->products($rootId), static fn (array $entry): bool => $entry['active']),
            ));
        }

        if (in_array($type, ['preset', 'builder'], true) && $index === 3) {
            return ['volume', 'time', 'hybrid'];
        }

        return $type === 'preset' && $index === 4 ? ['public', 'reseller', 'both'] : [];
    }

    private function showStep(Nutgram $bot, int $actorId): void
    {
        $s = $this->drafts->peek($actorId);
        if ($s === null || ! str_starts_with($s['stage'], 'step:')) {
            return;
        }

        $index = (int) substr($s['stage'], 5);
        $fields = $this->wizardFields($s['type']);
        if (! isset($fields[$index])) {
            $this->notice($bot, 'فرم معتبر نیست. دوباره شروع کنید.');

            return;
        }

        [$label, $example] = $fields[$index];
        $choices = $this->wizardChoices($s['type'], $index, $actorId);
        if ($index === 0 && $choices !== []) {
            $example = $choices[0];
        }

        $keyboard = InlineKeyboardMarkup::make();
        if ($choices !== []) {
            foreach (array_chunk($choices, 2) as $items) {
                $buttons = [];
                foreach ($items as $value) {
                    $display = $index === 3 ? TelegramUi::planKind($value)
                        : ($index === 4 ? TelegramUi::audience($value) : $value);
                    $buttons[] = InlineKeyboardButton::make($display, callback_data: 'plan:choose:'.$value);
                }
                $keyboard->addRow(...$buttons);
            }
        }

        if (mb_strlen($example) <= 256) {
            $keyboard->addRow(TelegramUi::copy($example, '📋 کپی مثال'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('⚡ ورود سریع همه اطلاعات', callback_data: 'plan:quick'));
        if ($index > 0) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ مرحله قبل', callback_data: 'plan:back'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'plan:cancel'));

        $count = count($fields);
        $this->render($bot, '📝 تنظیمات پلن — مرحله '.($index + 1)." از {$count}\n\n"
            .htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            ."\n\nمثال: ".TelegramUi::code($example)
            ."\n\nیک گزینه انتخاب کنید یا مقدار موردنظر را بفرستید.",
            $keyboard, true);
    }

    public function choose(Nutgram $bot, string $value): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $s = $this->drafts->peek($actor->userId);
        if ($s === null || ! str_starts_with($s['stage'], 'step:')) {
            $this->notice($bot, 'این انتخاب دیگر معتبر نیست. دوباره شروع کنید.');

            return;
        }

        $index = (int) substr($s['stage'], 5);
        if (! in_array($value, $this->wizardChoices($s['type'], $index, $actor->userId), true)) {
            $this->notice($bot, 'گزینه معتبر نیست. دوباره انتخاب کنید.');

            return;
        }

        $this->text($bot, $value);
    }

    public function previous(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $s = $this->drafts->peek($actor->userId);
        if ($s === null || $s['stage'] === 'preview') {
            $this->browse($bot);

            return;
        }

        $index = str_starts_with($s['stage'], 'step:') ? (int) substr($s['stage'], 5) : 0;
        $parts = $s['form'] === '' ? [] : explode(' | ', $s['form']);
        if ($index > 0) {
            array_pop($parts);
        }
        $s['form'] = implode(' | ', $parts);
        $s['stage'] = 'step:'.max(0, $index - 1);
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

        $fields = $this->wizardFields($s['type']);
        $examples = array_column($fields, 1);
        if (in_array($s['type'], ['preset', 'builder'], true)) {
            $choices = $this->wizardChoices($s['type'], 0, $actor->userId);
            if ($choices !== []) {
                $examples[0] = $choices[0];
            }
        }
        if ($examples === []) {
            $this->notice($bot, 'برای این فرم، ورود سریع در دسترس نیست.');

            return;
        }

        $example = implode(' | ', $examples);
        $s['form'] = '';
        $s['stage'] = 'input';
        $this->drafts->store($actor->userId, $s);
        $keyboard = InlineKeyboardMarkup::make();
        if (mb_strlen($example) <= 256) {
            $keyboard->addRow(TelegramUi::copy($example));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ راهنمای مرحله‌ای', callback_data: 'plan:back'))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'plan:cancel'));
        $this->render($bot, "⚡ ورود سریع\n\nیک پیام با مقادیر زیر بفرستید:\n".TelegramUi::code($example)
            ."\n\nمقادیر را با علامت | جدا کنید.",
            $keyboard, true);
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

        $state = $this->drafts->peek($actor->userId);
        if ($state === null) {
            return false;
        }

        if (! $actor->canManageRoles()) {
            $this->drafts->forget($actor->userId);
            $this->menus->denyAccess($bot);

            return true;
        }

        if (str_starts_with($state['stage'], 'editfield:')) {
            $index = (int) substr($state['stage'], 10);
            $parts = explode(' | ', $state['form']);
            $value = trim($text);
            if (! isset($parts[$index]) || $value === '' || mb_strlen($value) > 120
                || str_contains($value, '|') || preg_match('/[\r\n\x00-\x1f\x7f]/u', $value) === 1
                || ($this->editChoices($index) !== []
                    && ! in_array($value, $this->editChoices($index), true))) {
                $this->notice($bot, 'این مقدار معتبر نیست. دوباره تلاش کنید.');

                return true;
            }

            $parts[$index] = $value;
            $text = implode(' | ', $parts);
        }

        if (str_starts_with($state['stage'], 'step:') && ! str_contains($text, '|')) {
            $index = (int) substr($state['stage'], 5);
            $fields = $this->wizardFields($state['type']);
            if (! isset($fields[$index])) {
                $this->notice($bot, 'فرم معتبر نیست. دوباره شروع کنید.');

                return true;
            }

            $value = trim($text);
            $choices = $this->wizardChoices($state['type'], $index, $actor->userId);
            if ($value === '' || mb_strlen($value) > 120
                || preg_match('/[\r\n\x00-\x1f\x7f|]/u', $value) === 1
                || ($choices !== [] && ! in_array($value, $choices, true))
                || ($fields[$index][2] && $value !== '-'
                    && preg_match('/\A(?:0|[1-9][0-9]{0,11})\z/D', $value) !== 1)) {
                $this->notice($bot, 'این مقدار درست نیست. از گزینه‌ها انتخاب کنید یا مقدار معتبر بفرستید.');

                return true;
            }

            $parts = $state['form'] === '' ? [] : explode(' | ', $state['form']);
            $parts[] = $value;
            $text = implode(' | ', $parts);
            if ($index < count($fields) - 1) {
                $state['form'] = $text;
                $state['stage'] = 'step:'.($index + 1);
                $this->drafts->store($actor->userId, $state);
                $this->showStep($bot, $actor->userId);

                return true;
            }
        } elseif ($state['stage'] !== 'input' && ! str_starts_with($state['stage'], 'step:')
            && ! str_starts_with($state['stage'], 'editfield:')) {
            $this->notice($bot, 'اطلاعات آماده بررسی است. تأیید یا انصراف را بزنید.');

            return true;
        }

        try {
            if (in_array($state['type'], ['preset', 'builder', 'edit'], true)) {
                $definition = $this->forms->plan($state['type'], $text);
                $summary = "پلن {$definition->name}\n{$definition->slug}\nنوع: ".TelegramUi::planKind($definition->kind).' | حجم: '.($definition->trafficGb ?? 'نامحدود').' | مدت: '.($definition->durationDays ?? 'انتخابی');
            } else {
                $plan = $this->plans->find($actor->userId, $state['plan_id']);
                $definition = $this->forms->price($state['type'], $text, $plan);
                $summary = 'قیمت پلن '.$this->safe($plan->name)."\n{$text}";
            }
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'ورودی معتبر نیست. ترتیب فیلدها، حدود حجم/مدت و اعداد را بررسی کنید؛ یا فرم را لغو و دوباره شروع کنید.');

            return true;
        }

        $token = bin2hex(random_bytes(12));
        $state['form'] = $text;
        $state['token'] = $token;
        $state['stage'] = 'preview';
        $this->drafts->store($actor->userId, $state);

        $this->render($bot, "🔎 بررسی اطلاعات\n\n{$summary}\n\nاگر اطلاعات درست است، ثبت را تأیید کنید.\nفعال‌سازی پلن و قیمت پس از ثبت جداگانه انجام می‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ثبت پیش‌نویس', callback_data: 'plan:save:'.$token))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'plan:cancel')));

        return true;
    }

    public function save(Nutgram $bot, string $token): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            $this->notice($bot, 'درخواست معتبر نیست.');

            return;
        }

        $state = $this->drafts->consume($actor->userId, $token);
        if ($state === null) {
            $this->notice($bot, 'مهلت تأیید تمام شده یا این درخواست قبلاً انجام شده است. دوباره شروع کنید.');

            return;
        }

        try {
            if (in_array($state['type'], ['preset', 'builder', 'edit'], true)) {
                $definition = $this->forms->plan($state['type'], $state['form']);
                $plan = $state['type'] === 'edit'
                    ? $this->plans->update($actor->userId, $state['plan_id'], $state['plan_revision'], $definition)
                    : $this->plans->create($actor->userId, $definition);
                $planId = $plan->id;
            } else {
                $plan = $this->plans->find($actor->userId, $state['plan_id']);
                $definition = $this->forms->price($state['type'], $state['form'], $plan);
                $this->pricing->configure(
                    $actor->userId, $plan->id, $state['plan_revision'],
                    $state['price_revision'], $definition,
                );
                $planId = $plan->id;
            }
        } catch (ValidationException|AuthorizationException|QueryException) {
            $this->notice($bot, 'اطلاعات پلن یا قیمت تغییر کرده است. از فهرست دوباره شروع کنید.');

            return;
        }

        $this->detail($bot, (string) $planId);
    }

    public function requestStatus(Nutgram $bot, string $id, string $operation): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $planId = $this->id($id);
        if ($planId === null) {
            $this->notice($bot, 'شناسه پلن نامعتبر است.');

            return;
        }

        try {
            $plan = $this->plans->find($actor->userId, $planId);
            if (in_array($operation, ['pa', 'pd'], true)) {
                $price = $this->pricing->find($actor->userId, $planId);
                if ($price === null) {
                    throw ValidationException::withMessages(['price' => 'Price not found']);
                }

                $token = $this->pricing->requestChange(
                    $actor->userId, $planId, $plan->revision, $price->revision,
                    $operation === 'pa' ? 'activate' : 'deactivate',
                );
                $target = 't';
            } else {
                $token = $this->plans->requestChange($actor->userId, $planId, $plan->revision, $operation);
                $target = 'p';
            }
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'وضعیت پلن یا تعرفه تغییر کرده و این عملیات مجاز نیست.');

            return;
        }

        $label = match ($operation) {
            'activate' => 'فعال‌سازی پلن',
            'deactivate' => 'غیرفعال‌سازی پلن',
            'archive' => 'بایگانی پلن',
            'delete' => 'حذف پلن',
            'pa' => 'انتشار قیمت',
            'pd' => 'توقف نمایش قیمت',
            default => 'تغییر وضعیت',
        };
        $this->render($bot, "⚠️ تأیید {$label}\n\nپلن: ".$this->safe($plan->name)."\n\nاگر مطمئن هستید، دکمه تأیید نهایی را بزنید.\nتا ۳ دقیقه فرصت دارید.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید نهایی', callback_data: "plan:apply:{$target}:{$token}"))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: "plan:view:{$planId}")));
    }

    public function applyStatus(Nutgram $bot, string $type, string $token): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        try {
            if ($type === 'p') {
                $result = $this->plans->confirmChange($actor->userId, $token);
                if ($result === null) {
                    $this->browse($bot);

                    return;
                }

                $this->detail($bot, (string) $result->id);

                return;
            }

            if ($type === 't') {
                $this->pricing->confirmChange($actor->userId, $token);
                $this->browse($bot);

                return;
            }
        } catch (ValidationException|AuthorizationException|QueryException) {
            $this->notice($bot, 'این درخواست دیگر معتبر نیست. وضعیت پلن را بررسی و دوباره اقدام کنید.');

            return;
        }

        $this->notice($bot, 'درخواست نامعتبر است.');
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
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return null;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    private function tariffText(PlanTariffSummary $tariff): string
    {
        $value = $tariff->method === 'fixed'
            ? 'قیمت ثابت: '.TelegramUi::toman((int) $tariff->fixedPriceRials).' تومان'
            : 'قیمت پایه: '.TelegramUi::toman($tariff->basePriceRials).' تومان'
                ."\nهر گیگ: ".TelegramUi::toman($tariff->perGbRials).' تومان'
                ."\nهر روز: ".TelegramUi::toman($tariff->perDayRials).' تومان';

        return $value."\nحداقل قیمت: ".TelegramUi::toman($tariff->minimumTotalRials)
            ." تومان | تخفیف نماینده: {$tariff->resellerDiscountPercent}٪";
    }

    private function id(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function safe(string $name): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
    }

    private function statusIcon(string $status): string
    {
        return match ($status) {
            'active' => '🟢',
            'inactive' => '⏸️',
            'archived' => '📦',
            default => '📝',
        };
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ مدیریت پلن‌ها', callback_data: 'plan:list:1'));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "📦 مدیریت پلن‌ها\n\n{$message}", $this->back());
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard, bool $html = false): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $bot->editMessageText(text: $message, reply_markup: $keyboard, parse_mode: $html ? 'HTML' : null);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard, parse_mode: $html ? 'HTML' : null);
    }
}
