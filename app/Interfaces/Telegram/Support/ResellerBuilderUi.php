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
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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
        $lines = ['💼 پلن‌ساز نمایندگان', "صفحه {$page} از {$pages}", '',
            TelegramBotContext::current()->key === 'sales'
                ? 'حجم و مدت دلخواه را انتخاب کنید؛ امکان ثبت سفارش پس از بررسی آمادگی سرویس مشخص می‌شود.'
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
            $lines[] = 'در حال حاضر پلن سفارشی فعالی برای نمایندگان وجود ندارد.';
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
            $sizes = $this->choices([$plan->minGb, 10, 30, 50, 100, $plan->maxGb], (int) $plan->minGb, (int) $plan->maxGb);
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

        $keyboard->addRow(InlineKeyboardButton::make('✏️ حجم و مدت دلخواه', callback_data: "builder:custom:{$plan->id}:{$pg}"));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ پلن‌سازها', callback_data: 'builder:list:'.$pg));

        $this->render($bot, '💼 '.$this->safe($plan->name)."\n\n".$this->bounds($plan)
            ."\n\nحجم پیشنهادی را انتخاب کنید یا برای واردکردن حجم و مدت دلخواه دکمه ✏️ را بزنید."
            ."\n\n⚠️ این انتخاب فقط برای استعلام قیمت است.", $keyboard);
    }

    public function days(Nutgram $bot, string $id, string $gb, string $page): void
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

        $keyboard = InlineKeyboardMarkup::make();
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

        $keyboard->addRow(InlineKeyboardButton::make('✏️ ورود حجم و مدت دقیق', callback_data: "builder:custom:{$plan->id}:{$pg}"));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ تغییر حجم', callback_data: "builder:detail:{$plan->id}:{$pg}"));
        $this->render($bot, "⏳ انتخاب مدت\n\nپلن: ".$this->safe($plan->name)
            ."\nحجم: ".($volume === null ? 'نامحدود' : $volume.' گیگابایت')
            ."\nمدت مجاز: {$plan->minDays} تا {$plan->maxDays} روز\n\nمدت را انتخاب کنید:", $keyboard);
    }

    public function quote(Nutgram $bot, string $id, string $gb, string $days, string $page): void
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

        $this->showQuote($bot, $plan, $price, $pg);
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
        $this->pending->remember($actor->userId, $plan->id, $pg);
        $example = $plan->kind === 'time' ? '- | 30' : '35 | 45';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(TelegramUi::copy($example))
            ->addRow(InlineKeyboardButton::make('❌ لغو انتخاب', callback_data: 'builder:cancel'))
            ->addRow(InlineKeyboardButton::make('◀️ انتخاب‌های پلن', callback_data: "builder:detail:{$plan->id}:{$pg}"));

        $this->render($bot, "✏️ انتخاب حجم و مدت دلخواه\n\nپلن: ".$this->safe($plan->name)
            ."\n".$this->bounds($plan)
            ."\n\nیک پیام به شکل «حجم | مدت» ارسال کنید؛ مثل:\n{$example}"
            ."\nبرای پلن زمانی نامحدود به‌جای حجم از - استفاده کنید."
            ."\nفقط اعداد صحیح (فارسی یا انگلیسی)، بدون اعشار.\nاعتبار این فرم ۱۰ دقیقه است.", $keyboard);
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

        if (strlen($normalized) > 40 || preg_match('/\A(-|[1-9][0-9]{0,3})\s*\|\s*([1-9][0-9]{0,2})\z/D', $normalized, $matches) !== 1) {
            $this->notice($bot, 'فرمت درست: حجم | روز؛ مانند 35 | 45 یا برای نامحدود - | 30.', $selection['page']);

            return true;
        }

        try {
            $plan = $this->builders->find($actor->userId, $selection['plan_id']);
            $volume = $this->volume($plan, $matches[1]);
            if ($volume === false) {
                throw ValidationException::withMessages(['traffic' => 'Invalid volume.']);
            }

            $price = $this->quotes->handle($actor->userId, $plan->id, (int) $matches[2], $volume);
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'حجم، مدت، حداقل قیمت یا وضعیت پلن معتبر نیست؛ مقدار دیگری ارسال کنید یا فرم را لغو کنید.', $selection['page']);

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

    private function showQuote(Nutgram $bot, PlanSummary $plan, PlanPriceQuote $quote, int $page): void
    {
        $lines = [
            '🧮 پیش‌نمایش پلن سفارشی',
            '',
            '📦 '.$this->safe($plan->name),
            '📊 حجم: '.($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت'),
            "⏳ مدت: {$quote->durationDays} روز",
            '',
            '💵 قیمت عمومی: '.number_format(intdiv($quote->publicPriceRials + 9, 10)).' تومان',
            "🎁 تخفیف نماینده: {$quote->discountPercent}٪",
            '✅ قیمت شما: '.number_format(intdiv($quote->totalPriceRials + 9, 10)).' تومان',
            '',
            TelegramBotContext::current()->key === 'sales'
                ? 'برای ادامه، امکان ثبت سفارش را بررسی کنید.'
                : 'ℹ️ این قیمت برای اطلاع شماست. خرید پلن سفارشی فعلاً فعال نیست.',
        ];

        $keyboard = InlineKeyboardMarkup::make();
        if (TelegramBotContext::current()->key === 'sales') {
            $keyboard->addRow(InlineKeyboardButton::make(
                '🧾 بررسی امکان ثبت سفارش',
                callback_data: "sales:checkout:preview:{$quote->planId}:{$quote->durationDays}:".($quote->trafficGb ?? 0),
            ));
        }
        $keyboard->addRow(InlineKeyboardButton::make('✏️ تغییر حجم یا مدت', callback_data: "builder:custom:{$plan->id}:{$page}"))
            ->addRow(InlineKeyboardButton::make('◀️ پلن‌سازها', callback_data: 'builder:list:'.$page))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

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
        $this->render($bot, "💼 پلن‌ساز نمایندگان\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست پلن‌سازها', callback_data: 'builder:list:'.$page)));
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
