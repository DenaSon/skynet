<?php

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\TelegramActor;
use App\Modules\Sales\Application\ListQuotedPresets;
use App\Modules\Sales\Application\QuotedPreset;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

/**
 * F11-B1.5: safe catalog/quote presentation. No pending order, payment,
 * wallet debit, checkout token, outbox or Remnawave calls.
 */
final readonly class PlanCatalogUi
{
    public function __construct(
        private PrivateTelegramUser $privateUser,
        private ResolveTelegramActor $resolve,
        private ListQuotedPresets $catalog,
        private PendingShopVolume $pendingVolume,
        private PendingBuilderSelection $pendingBuilder,
        private ProductAdminDrafts $productDrafts,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, int $page = 1, bool $rich = true): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        if (TelegramBotContext::current()->key === TelegramBotContext::SALES) {
            Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:'.$actor->userId));
            Cache::forget(TelegramBotContext::current()->cacheKey('telegram:payphoto:recent:'.$actor->userId));
        }

        $this->pendingVolume->forget($actor->userId);
        $this->pendingBuilder->forget($actor->userId);
        $this->productDrafts->forget($actor->userId);

        try {
            $entries = $this->catalog->handle($actor->userId);
        } catch (AuthorizationException) {
            $this->menus->denyAccess($bot);

            return;
        }

        $reseller = in_array('reseller', $actor->roles, true);
        $sales = TelegramBotContext::current()->key === TelegramBotContext::SALES;
        $lastPage = max(1, (int) ceil(count($entries) / 5));
        $page = min(max(1, $page), $lastPage);
        $keyboard = InlineKeyboardMarkup::make();

        $lines = [
            '📋 پلن‌ها و قیمت‌ها',
            "صفحه {$page} از {$lastPage}",
            '',
        ];
        if ($reseller) {
            $lines[] = '🏷️ قیمت‌های این فهرست مخصوص پنل فروش هستند.';
        }
        if (TelegramBotContext::current()->key !== TelegramBotContext::SALES) {
            $lines[] = 'ℹ️ خرید پلن‌های این بخش فعلاً فعال نیست.';
        }
        if ($reseller || TelegramBotContext::current()->key !== TelegramBotContext::SALES) {
            $lines[] = '';
        }

        $richHtml = '<h4>📋 پلن‌ها و قیمت‌ها</h4><p>صفحه '.$page.' از '.$lastPage.'</p>';
        if ($reseller) {
            $richHtml .= '<p>🏷️ قیمت اختصاصی پنل فروش برای شما محاسبه شده است.</p>';
        }
        foreach (array_slice($entries, ($page - 1) * 5, 5) as $entry) {
            $lines[] = ($entry->plan->featured ? '⭐ ' : '📦 ').TelegramUi::escape($this->safe($entry->plan->name));
            $lines[] = '📊 حجم و مدت: '.$this->spec($entry);
            $lines[] = '💰 مبلغ: <b>'.$this->toman($entry->quote->totalPriceRials).' تومان</b>';
            $lines[] = '';
            $callback = "catalog:detail:{$entry->plan->id}:{$page}";
            $richHtml .= '<p>'.($entry->plan->featured ? '⭐' : '📦')
                .' <b>'.TelegramUi::escape($this->safe($entry->plan->name)).'</b><br>'
                .'حجم و مدت: '.$this->spec($entry).'<br>'
                .($reseller
                    ? 'قیمت عمومی: '.$this->toman($entry->quote->publicPriceRials).' تومان<br>'
                        .'تخفیف شما: '.$entry->quote->discountPercent.'٪<br>قیمت نماینده: '
                    : 'مبلغ نهایی: ')
                .'<b>'.$this->toman($entry->quote->totalPriceRials).' تومان</b></p>';
            if ($sales) {
                $richHtml .= '<tg-button-row><tg-button type="callback_data" style="link" data="sales:catalog:preview:'.$entry->plan->id.':'.$page.'">🔎 جزئیات این پلن</tg-button></tg-button-row>';
            }
            $keyboard->addRow(
                InlineKeyboardButton::make(
                    TelegramUi::toman($entry->quote->totalPriceRials).' تومان',
                    callback_data: $callback,
                ),
                InlineKeyboardButton::make(
                    TelegramUi::planButtonLabel($entry->plan->name, $entry->quote->trafficGb, $entry->quote->durationDays),
                    callback_data: $callback,
                ),
            );
        }

        if ($entries === []) {
            $lines[] = 'در حال حاضر پلنی برای نمایش وجود ندارد.';
            $richHtml .= '<p>در حال حاضر پلنی برای نمایش وجود ندارد.</p>';
        }

        if ($page > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'catalog:list:'.($page - 1)));
        }

        if ($page < $lastPage) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'catalog:list:'.($page + 1)));
        }

        if (in_array('reseller', $actor->roles, true)) {
            $keyboard->addRow(InlineKeyboardButton::make('🧩 پلن‌ساز', callback_data: 'menu:builder'));
        }
        $keyboard->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));
        $plainText = implode("\n", $lines);
        if ($sales && $rich && $entries !== []) {
            $simpleKeyboard = clone $keyboard;
            $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:catalog:simple:'.$page));
            $this->renderRich($bot, $richHtml, $plainText, $keyboard, $simpleKeyboard, 'HTML');

            return;
        }
        $this->render($bot, $plainText, $keyboard, 'HTML');
    }

    public function detail(Nutgram $bot, string $planId, string $fromPage = '1', bool $rich = true): void
    {
        $actor = $this->actor($bot);
        if ($actor === null) {
            return;
        }

        $this->pendingVolume->forget($actor->userId);
        $id = $this->positiveId($planId);
        $page = $this->page($fromPage);
        if ($id === null) {
            $this->notice($bot, 'شناسه پلن معتبر نیست.', $page);

            return;
        }

        try {
            // Recalculate the actual buyer price on *every* tap; callback
            // data never contains a price, role, revision or hidden secret.
            $entry = $this->catalog->find($actor->userId, $id);
        } catch (ValidationException|AuthorizationException) {
            $this->notice($bot, 'این پلن یا تعرفه دیگر در دسترس نیست. فهرست را تازه کنید.', $page);

            return;
        }

        $quote = $entry->quote;
        $isReseller = in_array('reseller', $actor->roles, true);
        $lines = [
            '🔎 پیش‌نمایش پلن',
            '',
            '📦 '.$this->safe($entry->plan->name),
            '🔹 نوع: '.$this->kind($entry),
            '📊 حجم: '.($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت'),
            "⏳ مدت: {$quote->durationDays} روز",
            '',
            '💵 قیمت عمومی: '.$this->toman($quote->publicPriceRials).' تومان',
            $isReseller ? "🎁 تخفیف پنل فروش: {$quote->discountPercent}٪" : '👤 تعرفه عمومی',
            ($isReseller ? '✅ قیمت مخصوص پنل فروش: ' : '✅ مبلغ نهایی: ')
                .$this->toman($quote->totalPriceRials).' تومان',
            '',
            TelegramBotContext::current()->key === 'sales'
                ? 'ℹ️ ثبت سفارش تنها پس از تأیید آمادگی سرویس امکان‌پذیر است.'
                : 'ℹ️ این قیمت برای اطلاع شماست. خرید این پلن فعلاً فعال نیست.',
        ];

        $keyboard = InlineKeyboardMarkup::make();
        if (TelegramBotContext::current()->key === 'sales') {
            $callback = 'sales:checkout:preview:'.$quote->planId.':'.$quote->durationDays.':'.($quote->trafficGb ?? 0);
            $keyboard->addRow(InlineKeyboardButton::make('🧾 بررسی امکان ثبت سفارش', callback_data: $callback));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ پلن‌ها', callback_data: 'catalog:list:'.$page))
            ->addRow(InlineKeyboardButton::make('🏠 صفحه اصلی', callback_data: 'menu:home'));

        if (TelegramBotContext::current()->key === TelegramBotContext::SALES && $rich) {
            $safeName = TelegramUi::escape($this->safe($entry->plan->name));
            $callback = 'catalog:detail:'.$quote->planId.':'.$page;
            $html = '<h4>📦 '.$safeName.'</h4>'
                .'<p>حجم: '.($quote->trafficGb === null ? 'نامحدود' : $quote->trafficGb.' گیگابایت')
                .'<br>مدت: '.$quote->durationDays.' روز'
                .'<br>نوع: '.$this->kind($entry).'</p>'
                .($isReseller
                    ? '<p>قیمت عمومی: '.$this->toman($quote->publicPriceRials).' تومان'
                        .'<br>تخفیف پنل فروش: '.$quote->discountPercent.'٪</p>'
                    : '')
                .'<p>✅ '.($isReseller ? 'قیمت شما: ' : 'مبلغ نهایی: ')
                .'<b>'.$this->toman($quote->totalPriceRials).' تومان</b></p>'
                .'<tg-button-row><tg-button type="callback_data" style="success" data="'.$callback.'">🛒 ادامه خرید</tg-button></tg-button-row>'
                .'<details><summary>📋 شرایط پلن</summary><p>حجم و مدت اشتراک مطابق مشخصات بالا است. قیمت نهایی و آمادگی سرویس پیش از ثبت سفارش دوباره بررسی می‌شوند.</p></details>';
            $simpleKeyboard = clone $keyboard;
            $keyboard->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:catalog:preview:simple:'.$quote->planId.':'.$page));
            $this->renderRich($bot, $html, implode("\n", $lines), $keyboard, $simpleKeyboard);

            return;
        }

        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    /** Rich-only presentation. All purchase callbacks still enter validated checkout. */
    private function renderRich(
        Nutgram $bot,
        string $html,
        string $plain,
        InlineKeyboardMarkup $keyboard,
        InlineKeyboardMarkup $simpleKeyboard,
        ?string $parseMode = null,
    ): void {
        try {
            TelegramUi::answerCallback($bot);
            TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $keyboard);
        } catch (TelegramException $exception) {
            report($exception);
            if ($bot->isCallbackQuery()) {
                TelegramUi::editText($bot, $plain, $simpleKeyboard, $parseMode);
            } else {
                $bot->sendMessage(text: $plain, reply_markup: $simpleKeyboard, parse_mode: $parseMode);
            }
        }
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

    private function positiveId(string $id): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) === 1 ? (int) $id : null;
    }

    private function page(string $value): int
    {
        return preg_match('/\A[1-9][0-9]{0,4}\z/D', $value) === 1 ? min(10000, (int) $value) : 1;
    }

    private function safe(string $value): string
    {
        return trim((string) preg_replace('/[\r\n\x00-\x1F\x7F]+/u', ' ', $value));
    }

    private function spec(QuotedPreset $entry): string
    {
        return ($entry->quote->trafficGb === null ? 'نامحدود' : $entry->quote->trafficGb.' گیگ')
            ." / {$entry->quote->durationDays} روز";
    }

    private function kind(QuotedPreset $entry): string
    {
        return match ($entry->plan->kind) {
            'volume' => 'حجمی',
            'time' => 'زمانی نامحدود',
            default => 'حجم + زمان',
        };
    }

    private function toman(int $rials): string
    {
        return number_format(intdiv($rials + 9, 10));
    }

    private function notice(Nutgram $bot, string $message, int $page): void
    {
        $this->render($bot, "📋 پلن‌ها و قیمت‌ها\n\n{$message}", InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ فهرست پلن‌ها', callback_data: 'catalog:list:'.$page)));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard, ?string $parseMode = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, $parseMode);

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: $parseMode);
    }
}
