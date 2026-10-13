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
            $lines[] = $this->statusIcon($entry->status)." #{$entry->id} — ".$this->safe($entry->name)." ({$entry->mode})";
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
        $lines[] = 'اگر slug تکراری باشد، نام یکتای دیگری انتخاب کنید. قیمت‌ها جداگانه تعریف می‌شوند.';

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
            'form' => '', 'stage' => 'input', 'token' => '',
        ]);

        $hint = match ($type) {
            'preset' => "پلن ثابت (۷ فیلد):\nproduct | slug | name | kind | audience | GB/- | days\nمثال:\nvpn-access | standard-30 | استاندارد ۳۰ | hybrid | both | 30 | 30",
            'builder' => "پلن‌ساز نماینده (۸ فیلد):\nproduct | slug | name | kind | minGB/- | maxGB/- | minDays | maxDays\nمثال:\nvpn-access | custom-30 | سفارشی | hybrid | 1 | 100 | 15 | 90",
            'edit' => "ویرایش (۱۴ فیلد):\nproduct | slug | name | kind | mode | audience | GB/- | days/- | minGB/- | maxGB/- | minDays | maxDays | order | featured(0/1)\n\nمتن فعلی را کپی، اصلاح و ارسال کنید:\n".$this->forms->editLine($plan instanceof PlanSummary ? $plan : throw new \LogicException('Plan missing')),
            'fixed' => "تعرفه ثابت (۳ فیلد؛ همگی تومان به‌جز درصد):\npriceToman | minimumToman | resellerDiscountPercent\nمثال:\n120000 | 60000 | 5",
            'formula' => "تعرفه فرمولی (۵ فیلد؛ تومان به‌جز درصد):\nbaseToman | perGBToman | perDayToman | minimumToman | resellerDiscountPercent\nمثال:\n50000 | 10000 | 2000 | 20000 | 5",
        };

        if ($price !== null && in_array($type, ['fixed', 'formula'], true) && $type === $price->method) {
            $hint .= "\n\nمقادیر فعلی (برای اطلاع، درصورت غیرمضرب ۱۰ ریال اعداد را جداگانه بررسی کنید):\n".$this->forms->tariffLine($price);
        }

        $example = match ($type) {
            'preset' => 'vpn-access | standard-30 | استاندارد ۳۰ | hybrid | both | 30 | 30',
            'builder' => 'vpn-access | custom-30 | سفارشی | hybrid | 1 | 100 | 15 | 90',
            'edit' => $this->forms->editLine($plan instanceof PlanSummary ? $plan : throw new \LogicException('Plan missing')),
            'fixed' => '120000 | 60000 | 5',
            'formula' => '50000 | 10000 | 2000 | 20000 | 5',
        };
        if (in_array($type, ['preset', 'builder'], true)) {
            $active = collect($this->plans->products($actor->userId))
                ->first(static fn (array $entry): bool => $entry['active']);
            if ($active !== null) {
                $example = (string) $active['slug'].mb_substr($example, mb_strlen('vpn-access'));
            }
        }

        $keyboard = InlineKeyboardMarkup::make();
        if (mb_strlen($example) <= 256) {
            $keyboard->addRow(TelegramUi::copy($example));
        }
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

        if ($state['stage'] !== 'input') {
            $this->notice($bot, 'فرم منتظر تأیید است. با دکمه ثبت یا انصراف ادامه دهید.');

            return true;
        }

        try {
            if (in_array($state['type'], ['preset', 'builder', 'edit'], true)) {
                $definition = $this->forms->plan($state['type'], $text);
                $summary = "پلن {$definition->name}\n{$definition->slug} / {$definition->kind} / {$definition->mode}\nحجم: ".($definition->trafficGb ?? '-').' | مدت: '.($definition->durationDays ?? '-');
            } else {
                $plan = $this->plans->find($actor->userId, $state['plan_id']);
                $definition = $this->forms->price($state['type'], $text, $plan);
                $summary = "تعرفه {$definition->method} برای #{$plan->id}\n{$text}";
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

        $this->render($bot, "🔎 پیش‌نمایش تغییر\n\n{$summary}\n\nتأیید ثبت، عملیات را روی نسخه فعلی اجرا می‌کند. ویرایش/تعرفه در صورت تغییر نسخه رد می‌شود.\nبرای فعال‌سازی پلن یا انتشار قیمت پس از ثبت باید جداگانه تأیید بدهید.",
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
            $this->notice($bot, 'پیش‌نویس منقضی، لغو یا قبلاً مصرف شده است.');

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
            $this->notice($bot, 'ثبت رد شد. وضعیت محصول/پلن یا نسخه تغییر کرده است. دوباره از فهرست اقدام کنید.');

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

        $this->render($bot, "⚠️ تأیید عملیات {$operation}\n\nپلن #{$planId} — ".$this->safe($plan->name)."\n\nاین تأیید یک‌بارمصرف و ۳ دقیقه معتبر است. انتشار پلن/تعرفه به معنی ساخت اشتراک واقعی نیست.",
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
            $this->notice($bot, 'تأیید منقضی یا مصرف‌شده است؛ یا وضعیت و مجوز شما تغییر کرده است.');

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
            ? 'ثابت: '.number_format((int) $tariff->fixedPriceRials).' ریال'
            : 'پایه: '.number_format($tariff->basePriceRials).' + هر GB: '.number_format($tariff->perGbRials)
                .' + هر روز: '.number_format($tariff->perDayRials).' ریال';

        return $value."\nکف: ".number_format($tariff->minimumTotalRials)." ریال | تخفیف نماینده: {$tariff->resellerDiscountPercent}%";
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
