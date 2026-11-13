<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\CalculatePlanQuote;
use App\Modules\Sales\Application\ListResellerBuilders;
use App\Modules\Sales\Application\PlanPriceQuote;
use App\Modules\Sales\Application\PlanSummary;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/**
 * F11-B1.6: reseller custom GB/day preview, without ANY order/settlement or
 * provider API integration. All callback inputs are reauthorized and repriced.
 */
final readonly class ResellerBuilderUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private ListResellerBuilders $builders,
        private CalculatePlanQuote $quotes,
        private PendingBuilderSelection $pending,
        private PendingShopVolume $pendingShop,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);
        $this->pendingShop->forget($actor->userId);

        try {
            $builders = $this->builders->handle($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        }

        $pages = max(1, (int) ceil(count($builders) / 5));
        $page = min(max(1, $page), $pages);
        $lines = ['🧩 پلن سفارشی', "صفحه {$page} از {$pages}", '',
            TelegramBotContext::current()->key === 'sales'
                ? 'حجم و مدت موردنیاز مشتری را انتخاب کنید؛ قیمت پیش از خرید نمایش داده می‌شود.'
                : 'پلن‌های زیر دارای تعرفه فعال هستند. فقط پیش‌نمایش قیمت است؛ سفارش و پرداخت در دسترس نیست.', ''];
        $keyboard = InlineKeyboardMarkup::make();

        foreach (array_slice($builders, ($page - 1) * 5, 5) as $builder) {
            $lines[] = '📦 '.$this->safe($builder->name).' — '.$this->bounds($builder);
            $keyboard->addRow(InlineKeyboardButton::make(
                mb_substr($this->safe($builder->name), 0, 32),
                callback_data: "builder:detail:{$builder->id}:{$page}",
            ));
        }

        if ($builders === []) {
            $lines[] = 'در حال حاضر پلن سفارشی فعالی وجود ندارد.';
        }

        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'builder:list:'.($page - 1)));
        }
        if ($page < $pages) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'builder:list:'.($page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('📋 پلن‌های ثابت', callback_data: 'menu:plan-catalog'));
        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function detail(Nutgram $bot, string $id, string $page): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);
        $plan = $this->plan($bot, $actor->userId, $id, $this->page($page));
        if ($plan === null) {
            return;
        }

        $pg = $this->page($page);
        $keyboard = InlineKeyboardMarkup::make();

        if ($plan->kind === 'time') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '⏳ انتخاب مدت (حجم نامحدود)', callback_data: "builder:days:{$plan->id}:0:{$pg}",
            ));
        } else {
            $sizes = $this->choices([$plan->minGb, 10, 20, 30, 50, 75, 100, 150, 250, $plan->maxGb], (int) $plan->minGb, (int) $plan->maxGb);
            foreach (array_chunk($sizes, 2) as $row) {
                $buttons = array_map(
                    static fn (int $gb): InlineKeyboardButton => InlineKeyboardButton::make(
                        "{$gb} گیگ", callback_data: "builder:days:{$plan->id}:{$gb}:{$pg}",
                    ),
                    $row,
                );
                $keyboard->addRow(...$buttons);
            }
        }

        $keyboard->addRow(InlineKeyboardButton::make(
            $plan->kind === 'time' ? '✏️ مدت دلخواه' : '✏️ حجم دلخواه',
            callback_data: "builder:custom:{$plan->id}:{$pg}",
        ));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ پلن‌های سفارشی', callback_data: 'builder:list:'.$pg));

        $intro = $plan->kind === 'time'
            ? 'حجم نامحدود است. فقط مدت سرویس را مشخص کنید.'
            : "مرحله ۱ از ۲ · انتخاب حجم\n\n"
                ."حجم مجاز: {$plan->minGb} تا {$plan->maxGb} گیگابایت"
                ."\n\nاز گزینه‌ها انتخاب کنید یا «حجم دلخواه» را بزنید."
                ."\n💡 مدت سرویس را در مرحله بعد تعیین می‌کنید.";

        $this->render($bot, '🧩 پلن‌ساز · '.$this->safe($plan->name)."\n\n".$intro, $keyboard);
    }

    public function days(Nutgram $bot, string $id, string $gb, string $page, bool $manual = false, bool $awaitText = false): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $pg = $this->page($page);
        $plan = $this->plan($bot, $actor->userId, $id, $pg);
        if ($plan === null) {
            return;
        }

        $volume = $this->volume($plan, $gb);
        if ($volume === false) {
            $this->notice($bot, 'حجم انتخابی مجاز نیست. دوباره از مشخصات پلن انتخاب کنید.', $pg);

            return;
        }

        // Preset browsing must not capture unrelated chat text. Only explicit
        // manual entry or a just-entered GB amount awaits a duration message.
        if ($manual || $awaitText) {
            $this->pending->remember($actor->userId, $plan->id, $pg, 'days', $volume);
        } else {
            $this->pending->forget($actor->userId);
        }
        $keyboard = InlineKeyboardMarkup::make();
        if (! $manual) {
            $durations = $this->choices([$plan->minDays, 7, 15, 30, 60, 90, 180, $plan->maxDays], $plan->minDays, $plan->maxDays);
            foreach (array_chunk($durations, 2) as $row) {
                $buttons = array_map(
                    static fn (int $days): InlineKeyboardButton => InlineKeyboardButton::make(
                        "{$days} روز", callback_data: "builder:quote:{$plan->id}:{$gb}:{$days}:{$pg}",
                    ),
                    $row,
                );
                $keyboard->addRow(...$buttons);
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                '✏️ مدت دلخواه', callback_data: "builder:customdays:{$plan->id}:{$gb}:{$pg}",
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            $plan->kind === 'time' ? '◀️ بازگشت به پلن' : '◀️ تغییر حجم',
            callback_data: "builder:detail:{$plan->id}:{$pg}",
        ));
        $keyboard->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'builder:cancel'));

        $tip = $manual
            ? "✍️ فقط تعداد روز را به صورت عدد ارسال کنید؛ مثلاً {$plan->minDays}."
            : ($awaitText
                ? 'از گزینه‌ها انتخاب کنید یا تعداد روز دلخواه را به صورت عدد بفرستید.'
                : 'یکی از مدت‌های آماده را انتخاب کنید یا «مدت دلخواه» را بزنید.');
        $this->render($bot, "🧩 پلن‌ساز · مرحله ۲ از ۲\n\nپلن: ".$this->safe($plan->name)
            ."\nحجم: ".($volume === null ? 'نامحدود' : $volume.' گیگابایت')
            ."\nمدت مجاز: {$plan->minDays} تا {$plan->maxDays} روز\n\n".$tip, $keyboard);
    }

    public function quote(Nutgram $bot, string $id, string $gb, string $days, string $page, bool $rich = true): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $pg = $this->page($page);
        $plan = $this->plan($bot, $actor->userId, $id, $pg);
        if ($plan === null) {
            return;
        }

        $volume = $this->volume($plan, $gb);
        $duration = $this->positive($days);
        if ($volume === false || $duration === null) {
            $this->notice($bot, 'حجم یا مدت معتبر نیست. انتخاب را دوباره انجام دهید.', $pg);

            return;
        }

        try {
            $price = $this->quotes->handle($actor->userId, $plan->id, $duration, $volume);
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'قیمت این ترکیب قابل ارائه نیست؛ شاید حداقل قیمت رعایت نشده یا تعرفه تغییر کرده است.', $pg);

            return;
        }

        if (($this->pending->peek($actor->userId)['plan_id'] ?? null) === $plan->id) {
            $this->pending->forget($actor->userId);
        }
        $this->showQuote($bot, $plan, $price, $pg, $rich);
    }

    public function custom(Nutgram $bot, string $id, string $page): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $pg = $this->page($page);
        $plan = $this->plan($bot, $actor->userId, $id, $pg);
        if ($plan === null) {
            return;
        }

        $this->pendingShop->forget($actor->userId);
        if ($plan->kind === 'time') {
            // Unlimited-volume builders have no volume decision.
            $this->days($bot, (string) $plan->id, '0', (string) $pg, true);

            return;
        }

        $this->pending->remember($actor->userId, $plan->id, $pg, 'volume');
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ انتخاب حجم', callback_data: "builder:detail:{$plan->id}:{$pg}"))
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'builder:cancel'));

        $this->render($bot, "🧩 پلن‌ساز · مرحله ۱ از ۲\n\nپلن: ".$this->safe($plan->name)
            ."\nحجم مجاز: {$plan->minGb} تا {$plan->maxGb} گیگابایت"
            ."\n\n✍️ حجم موردنیاز را فقط به صورت عدد بفرستید؛ مثلاً {$plan->minGb}."
            ."\n💡 مدت سرویس را در مرحله بعد می‌پرسیم."
            ."\n⏱ اعتبار فرم: ۱۰ دقیقه", $keyboard);
    }

    /**
     * Runs after known Reply labels and pending Super Admin forms, before F09
     * custom-volume input. A current builder selection consumes only its own
     * buyer's text; other text paths and commands remain intact.
     */
    public function customText(Nutgram $bot, string $input): bool
    {
        $telegramId = $this->privateUser->id($bot);
        if ($telegramId === null) {
            return false;
        }

        $actor = $this->resolve->handle($telegramId);
        if ($actor === null) {
            return false;
        }

        $selection = $this->pending->peek($actor->userId);
        if ($selection === null || str_starts_with(trim($input), '/')) {
            return false;
        }

        if (! in_array('reseller', $actor->roles, true)) {
            $this->pending->forget($actor->userId);
            $this->menus->denyAccess($bot);

            return true;
        }

        $normalized = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $legacy = strlen($normalized) <= 40
            && preg_match('/\\A(-|[1-9][0-9]{0,3})\\s*\\|\\s*([1-9][0-9]{0,2})\\z/D', $normalized, $matches) === 1;

        try {
            $plan = $this->builders->find($actor->userId, $selection['plan_id']);

            if ($legacy) {
                // Keep already-sent "GB | days" messages and pre-deploy drafts working.
                $volume = $this->volume($plan, $matches[1]);
                $duration = $this->positive($matches[2]);
                if ($volume === false || $duration === null) {
                    throw ValidationException::withMessages(['builder' => 'Invalid legacy selection.']);
                }
            } elseif ($selection['step'] === 'volume') {
                $volume = $this->volume($plan, $normalized);
                if ($volume === false || $volume === null) {
                    throw ValidationException::withMessages(['traffic' => 'Invalid builder volume.']);
                }

                // No quote or order until duration is explicitly selected.
                $this->days($bot, (string) $plan->id, (string) $volume, (string) $selection['page'], false, true);

                return true;
            } elseif ($selection['step'] === 'days') {
                $volume = $this->volume($plan, $selection['volume_gb'] === null ? '0' : (string) $selection['volume_gb']);
                $duration = $this->positive($normalized);
                if ($volume === false || $duration === null) {
                    throw ValidationException::withMessages(['duration' => 'Invalid builder duration.']);
                }
            } else {
                throw ValidationException::withMessages(['builder' => 'Invalid pending builder step.']);
            }

            $price = $this->quotes->handle($actor->userId, $plan->id, $duration, $volume);
        } catch (ValidationException|AuthorizationException) {
            $hint = match ($selection['step']) {
                'volume' => 'حجم معتبر نیست؛ فقط یک عدد در محدوده مجاز پلن بفرستید.',
                'days' => 'مدت معتبر نیست؛ فقط تعداد روز مجاز را بفرستید.',
                default => 'حجم و مدت معتبر نیست؛ مقادیر را دوباره بررسی کنید.',
            };
            $this->notice($bot, $hint.' اگر تعرفه تغییر کرده، از پلن‌ساز دوباره شروع کنید.', $selection['page']);

            return true;
        }

        $this->pending->forget($actor->userId);
        $this->showQuote($bot, $plan, $price, $selection['page']);

        return true;
    }

    public function cancel(Nutgram $bot): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);
        $this->browse($bot);
    }

    private function showQuote(Nutgram $bot, PlanSummary $plan, PlanPriceQuote $quote, int $page, bool $rich = true): void
    {
        $lines = [
            '🧩 سرویس سفارشی شما',
            '',
            '📦 '.$this->safe($plan->name),
            '📊 حجم: '.($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت'),
            "⏳ مدت: {$quote->durationDays} روز",
            '',
            '💵 قیمت عمومی: '.number_format(intdiv($quote->publicPriceRials + 9, 10)).' تومان',
            "🎁 تخفیف پنل فروش: {$quote->discountPercent}٪",
            '💰 مبلغ قابل پرداخت: '.number_format(intdiv($quote->totalPriceRials + 9, 10)).' تومان',
            '',
            TelegramBotContext::current()->key === 'sales'
                ? 'مبلغ و شرایط قبل از ثبت سفارش دوباره بررسی می‌شوند.'
                : 'ℹ️ این قیمت برای اطلاع شماست. خرید پلن سفارشی فعلاً فعال نیست.',
        ];

        $keyboard = InlineKeyboardMarkup::make();
        if (TelegramBotContext::current()->key === 'sales') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🛒 ادامه خرید',
                callback_data: "sales:checkout:preview:{$quote->planId}:{$quote->durationDays}:".($quote->trafficGb ?? 0),
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make(
            $plan->kind === 'time' ? '✏️ تغییر مدت' : '✏️ تغییر حجم یا مدت',
            callback_data: "builder:custom:{$plan->id}:{$page}",
        ))
            ->addRow(InlineKeyboardButton::make('◀️ پلن‌سازها', callback_data: 'builder:list:'.$page))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        // Sales-only Rich quote: never mutate a draft, place an order, or
        // bypass the existing checkout validation. Admin stays plain text.
        if ($rich && TelegramBotContext::current()->key === 'sales') {
            $checkout = "sales:checkout:preview:{$quote->planId}:{$quote->durationDays}:".($quote->trafficGb ?? 0);
            $plainKeyboard = clone $keyboard;
            $html = '<h4>🧩 سرویس سفارشی شما</h4>'
                .'<p>📦 <b>'.TelegramUi::escape($this->safe($plan->name)).'</b>'
                .'<br>📊 حجم: '.($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت')
                .'<br>⏳ مدت: '.$quote->durationDays.' روز</p>'
                .'<p>💵 قیمت عمومی: '.TelegramUi::toman($quote->publicPriceRials).' تومان'
                .'<br>🎁 تخفیف نماینده: '.$quote->discountPercent.'٪</p>'
                .'<p><b>💰 مبلغ قابل پرداخت: '.TelegramUi::toman($quote->totalPriceRials).' تومان</b></p>'
                .'<tg-button-row><tg-button type="callback_data" style="success" data="'.$checkout.'">'
                .'🛒 ادامه خرید</tg-button></tg-button-row>'
                .'<details><summary>📋 نکته پیش از خرید</summary><p>'
                .'این مبلغ پیش‌نمایش است؛ قیمت، شرایط پلن و امکان خرید قبل از ثبت سفارش دوباره بررسی می‌شوند.'
                .'</p></details>';
            try {
                TelegramUi::answerCallback($bot);
                TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);
            } catch (TelegramException $exception) {
                report($exception);
                if ($bot->isCallbackQuery()) {
                    TelegramUi::editText($bot, implode("\n", $lines), $plainKeyboard);
                } else {
                    $bot->sendMessage(text: implode("\n", $lines), reply_markup: $plainKeyboard);
                }
            }

            return;
        }

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    private function plan(Nutgram $bot, int $buyerId, string $value, int $page): ?PlanSummary
    {
        $id = $this->positive($value);
        if ($id === null) {
            $this->notice($bot, 'شناسه پلن معتبر نیست.', $page);

            return null;
        }

        try {
            return $this->builders->find($buyerId, $id);
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'پلن‌ساز یا تعرفه دیگر در دسترس شما نیست.', $page);

            return null;
        }
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);
        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);
        if ($actor === null || ! in_array('reseller', $actor->roles, true)) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor;
    }

    /** @return int|null|false Null means unlimited (time), false means invalid. */
    private function volume(PlanSummary $plan, string $input): int|null|false
    {
        if ($plan->kind === 'time') {
            return $input === '0' || $input === '-' ? null : false;
        }

        $value = $this->positive($input);

        return $value !== null && $value >= (int) $plan->minGb && $value <= (int) $plan->maxGb
            ? $value : false;
    }

    private function positive(string $value): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $value) === 1 ? (int) $value : null;
    }

    private function page(string $value): int
    {
        return preg_match('/\A[1-9][0-9]{0,4}\z/D', $value) === 1 ? min(10000, (int) $value) : 1;
    }

    /** @param array<int|null> $candidates
     * @return list<int>
     */
    private function choices(array $candidates, int $minimum, int $maximum): array
    {
        $choices = array_values(array_unique(array_filter(
            $candidates,
            static fn (?int $v): bool => $v !== null && $v >= $minimum && $v <= $maximum,
        )));
        sort($choices);

        return $choices;
    }

    private function safe(string $name): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
    }

    private function bounds(PlanSummary $plan): string
    {
        $size = $plan->kind === 'time'
            ? 'حجم نامحدود'
            : "{$plan->minGb} تا {$plan->maxGb} گیگ";

        return "{$size} | {$plan->minDays} تا {$plan->maxDays} روز";
    }

    private function notice(Nutgram $bot, string $message, int $page): void
    {
        $this->render($bot, "🧩 پلن سفارشی\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ پلن‌های سفارشی', callback_data: 'builder:list:'.$page)));
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
