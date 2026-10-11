<?php

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\CalculateQuote;
use App\Modules\Sales\Application\CatalogProduct;
use App\Modules\Sales\Application\ListProducts;
use App\Modules\Sales\Application\PriceQuote;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

final class ShopUi
{
    public function __construct(
        private readonly PrivateTelegramUser $privateUser,
        private readonly ResolveTelegramActor $resolve,
        private readonly ListProducts $catalog,
        private readonly CalculateQuote $pricing,
        private readonly PendingShopVolume $pending,
        private readonly MenuRenderer $menus,
    ) {}

    public function open(Nutgram $bot, int $page = 1): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);

        try {
            $products = $this->catalog->handle($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        }

        if ($products === []) {
            $this->notice($bot, 'در حال حاضر تعرفه فعالی تعریف نشده است. خرید هنوز در دسترس نیست.');

            return;
        }

        if (count($products) === 1) {
            $this->durationsFor($bot, $actor, $products[0]);

            return;
        }

        $perPage = 6;
        $lastPage = max(1, (int) ceil(count($products) / $perPage));
        $page = max(1, min($page, $lastPage));
        $subset = array_slice($products, ($page - 1) * $perPage, $perPage);
        $keyboard = InlineKeyboardMarkup::make();
        $lines = ["🛒 انتخاب سرویس", '', "صفحه {$page} از {$lastPage}", ''];

        foreach ($subset as $product) {
            $starting = $this->startingPrice($actor, $product);
            $lines[] = "{$this->safeName($product->name)} — از {$this->formatToman($starting)} تومان";
            $keyboard->addRow(InlineKeyboardButton::make(
                mb_substr($this->safeName($product->name), 0, 32),
                callback_data: 'shop:product:'.$product->id,
            ));
        }

        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'shop:catalog:'.($page - 1)));
        }

        if ($page < $lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'shop:catalog:'.($page + 1)));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function duration(Nutgram $bot, string $productId): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);
        $product = $this->product($actor, $productId);

        if ($product === null) {
            $this->notice($bot, 'این سرویس در حال حاضر قابل انتخاب نیست.');

            return;
        }

        $this->durationsFor($bot, $actor, $product);
    }

    public function volume(Nutgram $bot, string $productId, string $days): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $this->pending->forget($actor->userId);
        $product = $this->product($actor, $productId);

        if ($product === null || ! ctype_digit($days)) {
            $this->notice($bot, 'مدت یا سرویس انتخابی در دسترس نیست.');

            return;
        }

        $duration = (int) $days;
        $available = collect($product->rates)->contains(
            static fn ($rate): bool => $rate->durationDays === $duration
        );

        if (! $available) {
            $this->notice($bot, 'تعرفه این مدت دیگر فعال نیست.');

            return;
        }

        $volumes = array_values(array_unique(array_filter(
            array_merge([$product->minGb], [1, 10, 30, 50, 100], [$product->maxGb]),
            static fn (int $gb): bool => $gb >= $product->minGb && $gb <= $product->maxGb,
        )));
        sort($volumes);

        $keyboard = InlineKeyboardMarkup::make();
        $lines = [
            "📦 {$this->safeName($product->name)} — {$duration} روز",
            '',
            'حجم موردنظر را انتخاب کنید:',
            "حد مجاز: {$product->minGb} تا {$product->maxGb} گیگابایت",
            '',
        ];

        foreach ($volumes as $gb) {
            try {
                $quote = $this->pricing->handle($actor->userId, $product->slug, $duration, $gb);
            } catch (ValidationException|AuthorizationException) {
                $this->notice($bot, 'تعرفه تغییر کرده است. دوباره وارد بخش خرید شوید.');

                return;
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                "{$gb} گیگ — {$this->formatToman($quote->totalPriceRials)} تومان",
                callback_data: "shop:quote:{$product->id}:{$duration}:{$gb}",
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make('✏️ حجم دلخواه', callback_data: "shop:custom:{$product->id}:{$duration}"));
        $keyboard->addRow(InlineKeyboardButton::make('◀️ تغییر مدت', callback_data: 'shop:product:'.$product->id));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function custom(Nutgram $bot, string $productId, string $days): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $product = $this->product($actor, $productId);
        $duration = ctype_digit($days) ? (int) $days : 0;

        if ($product === null || ! collect($product->rates)->contains(static fn ($rate): bool => $rate->durationDays === $duration)) {
            $this->notice($bot, 'انتخاب شما منقضی شده است. دوباره مدت را انتخاب کنید.');

            return;
        }

        $this->pending->remember($actor->userId, $product->slug, $duration);
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به حجم‌ها', callback_data: "shop:volume:{$product->id}:{$duration}"))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'menu:home'));

        $this->render($bot, "✏️ حجم دلخواه\n\nسرویس: {$this->safeName($product->name)}\nمدت: {$duration} روز\n\nفقط عدد صحیح بین {$product->minGb} و {$product->maxGb} گیگابایت را در همین گفت‌وگو ارسال کنید.\nمثال: 75\n\nاین انتخاب ۱۰ دقیقه اعتبار دارد.", $keyboard);
    }

    public function customText(Nutgram $bot, string $input): void
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return;
        }

        $actor = $this->resolve->handle($id);

        if ($actor === null) {
            return;
        }

        $selection = $this->pending->peek($actor->userId);

        if ($selection === null || str_starts_with(trim($input), '/')) {
            return;
        }

        $digits = strtr(trim($input), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (preg_match('/^(?:[1-9]|[1-9][0-9]|100)$/D', $digits) !== 1) {
            $this->notice($bot, 'حجم باید عدد صحیح بین ۱ تا ۱۰۰ گیگابایت باشد؛ مثلاً 75.');

            return;
        }

        $product = collect($this->catalog->handle($actor->userId))
            ->first(static fn (CatalogProduct $item): bool => $item->slug === $selection['product']);

        if ($product === null) {
            $this->pending->forget($actor->userId);
            $this->notice($bot, 'این سرویس دیگر در دسترس نیست.');

            return;
        }

        $this->quoteFor($bot, $actor, $product, $selection['days'], (int) $digits);
    }

    public function quote(Nutgram $bot, string $productId, string $days, string $gb): void
    {
        $actor = $this->actor($bot);

        if ($actor === null) {
            return;
        }

        $product = $this->product($actor, $productId);

        if ($product === null || ! ctype_digit($days) || ! ctype_digit($gb)) {
            $this->notice($bot, 'انتخاب شما دیگر معتبر نیست.');

            return;
        }

        $this->quoteFor($bot, $actor, $product, (int) $days, (int) $gb);
    }

    private function quoteFor(Nutgram $bot, TelegramActor $actor, CatalogProduct $product, int $days, int $gb): void
    {
        try {
            $quote = $this->pricing->handle($actor->userId, $product->slug, $days, $gb);
        } catch (AuthorizationException|ValidationException) {
            $this->notice($bot, 'این ترکیب دیگر قابل ارائه نیست. حجم یا مدت را دوباره انتخاب کنید.');

            return;
        }

        $this->pending->forget($actor->userId);
        $this->preview($bot, $product, $quote);
    }

    private function preview(Nutgram $bot, CatalogProduct $product, PriceQuote $quote): void
    {
        $lines = [
            '🧾 پیش‌نمایش قیمت اشتراک',
            '',
            'سرویس: '.$this->safeName($quote->productName),
            "⏳ مدت: {$quote->durationDays} روز",
            "📦 حجم: {$quote->trafficGb} گیگابایت",
            '',
            'قیمت عمومی: '.$this->formatToman($quote->publicPriceRials).' تومان',
        ];

        if ($quote->discountPercent > 0) {
            $lines[] = "تخفیف نماینده ({$quote->discountPercent}٪): {$this->formatToman($quote->discountRials)} تومان";
        }

        $lines[] = '💰 قیمت نهایی: '.$this->formatToman($quote->totalPriceRials).' تومان';
        $lines[] = '';
        $lines[] = 'ℹ️ این فقط نمایش قیمت لحظه‌ای است. ثبت سفارش و پرداخت در مرحله بعد فعال می‌شود.';

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📦 تغییر حجم', callback_data: "shop:volume:{$product->id}:{$quote->durationDays}"))
            ->addRow(InlineKeyboardButton::make('⏳ تغییر مدت', callback_data: "shop:product:{$product->id}"))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    private function durationsFor(Nutgram $bot, TelegramActor $actor, CatalogProduct $product): void
    {
        $keyboard = InlineKeyboardMarkup::make();
        $lines = [
            '🛒 خرید اشتراک',
            '',
            'سرویس: '.$this->safeName($product->name),
            '⏳ مدت را انتخاب کنید:',
            "قیمت‌ها از حجم {$product->minGb} گیگابایت محاسبه شده‌اند.",
            '',
        ];

        foreach ($product->rates as $rate) {
            try {
                $quote = $this->pricing->handle($actor->userId, $product->slug, $rate->durationDays, $product->minGb);
            } catch (AuthorizationException|ValidationException) {
                $this->notice($bot, 'تعرفه تغییر کرده است. دوباره وارد بخش خرید شوید.');

                return;
            }

            $keyboard->addRow(InlineKeyboardButton::make(
                "{$rate->durationDays} روز — از {$this->formatToman($quote->totalPriceRials)} تومان",
                callback_data: "shop:volume:{$product->id}:{$rate->durationDays}",
            ));
        }

        $keyboard->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    private function startingPrice(TelegramActor $actor, CatalogProduct $product): int
    {
        $min = null;

        foreach ($product->rates as $rate) {
            $quote = $this->pricing->handle($actor->userId, $product->slug, $rate->durationDays, $product->minGb);
            $min = $min === null ? $quote->totalPriceRials : min($min, $quote->totalPriceRials);
        }

        return $min ?? 0;
    }

    private function product(TelegramActor $actor, string $id): ?CatalogProduct
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/D', $id) !== 1) {
            return null;
        }

        return collect($this->catalog->handle($actor->userId))
            ->first(static fn (CatalogProduct $entry): bool => $entry->id === (int) $id);
    }

    private function actor(Nutgram $bot): ?TelegramActor
    {
        $id = $this->privateUser->id($bot);

        if ($id === null) {
            return null;
        }

        $actor = $this->resolve->handle($id);

        if ($actor === null) {
            $this->menus->denyAccess($bot);
        }

        return $actor;
    }

    private function safeName(string $name): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $name));
    }

    private function formatToman(int $rials): string
    {
        // Round display upwards so UI never shows less than the real, exact rial quote.
        return number_format(intdiv($rials + 9, 10));
    }

    private function notice(Nutgram $bot, string $message): void
    {
        $this->render($bot, "🛒 خرید اشتراک\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بخش خرید', callback_data: 'menu:shop'))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home')));
    }

    private function render(Nutgram $bot, string $message, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            $bot->answerCallbackQuery();
            $bot->editMessageText(text: $message, reply_markup: $keyboard);

            return;
        }

        $bot->sendMessage(text: $message, reply_markup: $keyboard);
    }
}
