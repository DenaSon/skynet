<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Support\PersianCalendar;
use App\Modules\Billing\Application\ReadAdminAccountingReport;
use App\Modules\Billing\Application\RecordBusinessEntry;
use App\Modules\Identity\Application\AssertBillingAccess;
use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ButtonStyle;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

/** ACC-01: small, private, staged personal bookkeeping; no money movement. */
final readonly class AdminAccountingUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private AssertBillingAccess $access,
        private RecordBusinessEntry $entries,
        private ReadAdminAccountingReport $reports,
        private MenuRenderer $menus,
    ) {}

    public function home(Nutgram $bot): void
    {
        if ($this->actor($bot) === null) {
            return;
        }

        $this->render($bot, "<b>📒 دفتر مالی DenaGate</b>\n\nثبت هزینه و برداشت شخصی مالک، بدون انتقال وجه یا تغییر کیف پول کاربران.\n\nمبلغ‌ها به تومان وارد می‌شوند.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('➖ ثبت هزینه', callback_data: 'acc:begin:expense'))
                ->addRow(InlineKeyboardButton::make('💸 برداشت مالک', callback_data: 'acc:begin:owner_draw'))
                ->addRow(InlineKeyboardButton::make('📋 سوابق ثبت‌ها', callback_data: 'acc:history:1'))
                ->addRow(InlineKeyboardButton::make('📊 گزارش مالی', callback_data: 'acc:report:month'))
                ->addRow(InlineKeyboardButton::make('◀️ امور مالی', callback_data: 'menu:admin:finance')));
    }

    public function report(Nutgram $bot, string $period): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        if (! in_array($period, ['7', '30', 'month'], true)) {
            $this->expired($bot);

            return;
        }

        try {
            $data = $this->reports->handle($id, $period);
            $heading = match ($period) {
                '7' => '۷ روز اخیر',
                '30' => '۳۰ روز اخیر',
                'month' => 'ماه جاری',
            };
            $text = '<b>📊 گزارش مالی · '.$heading.'</b>'
                ."\n📅 ".PersianCalendar::date($data['start']).' تا '.PersianCalendar::date($data['end']).' (شمسی، تهران)'
                ."\n\n💳 فروش تأییدشده: <b>".self::toman($data['salesRials']).' تومان</b>'
                ."\n➖ هزینه‌های خالص ثبت‌شده: ".self::toman($data['expensesRials']).' تومان'
                ."\n📈 سود برآوردی: <b>".self::toman($data['profitRials']).' تومان</b>'
                ."\n\n💸 برداشت خالص مالک: ".self::toman($data['drawsRials']).' تومان'
                ."\n📒 مانده محاسباتی پس از برداشت: <b>".self::toman($data['afterDrawsRials']).' تومان</b>'
                ."\n\nثبت‌های هزینه: ".number_format($data['expenseEntries'])
                .' | ثبت‌های برداشت: '.number_format($data['drawEntries'])
                ."\n\n<blockquote>سود، برآورد حاصل از فروش ناخالص تسویه‌شده و هزینه‌های ثبت‌شده است؛ سود قطعی یا موجودی نقدی نیست. برداشت مالک هزینه محسوب نمی‌شود. اصلاحیه‌ها در تاریخ ثبت خود اثر می‌گذارند.</blockquote>"
                ."\n🕒 ".PersianCalendar::localDateTime($data['generatedAt']);
        } catch (\Throwable) {
            $text = "📊 گزارش مالی\n\nدریافت گزارش فعلاً ممکن نیست. دوباره تلاش کنید.";
        }

        $this->render($bot, $text, InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('۷ روز', callback_data: 'acc:report:7'),
                InlineKeyboardButton::make('۳۰ روز', callback_data: 'acc:report:30'),
                InlineKeyboardButton::make('ماه جاری', callback_data: 'acc:report:month'),
            )
            ->addRow(InlineKeyboardButton::make('📋 سوابق', callback_data: 'acc:history:1'))
            ->addRow(InlineKeyboardButton::make('◀️ دفتر مالی', callback_data: 'acc:home')));
    }

    private static function toman(int $rials): string
    {
        return number_format(intdiv($rials, 10));
    }

    public function begin(Nutgram $bot, string $kind): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        if (! in_array($kind, ['expense', 'owner_draw'], true)) {
            $this->home($bot);

            return;
        }

        $data = ['kind' => $kind, 'category' => $kind === 'owner_draw' ? 'owner' : null,
            'step' => $kind === 'expense' ? 'category' : 'amount',
            'token' => bin2hex(random_bytes(16))];
        $this->clearCompeting($id);
        Cache::put($this->key($id), $data, now()->addMinutes(15));

        if ($kind === 'owner_draw') {
            $this->amountPrompt($bot);

            return;
        }

        $this->render($bot, "➖ <b>ثبت هزینه</b>\n\nدسته هزینه را انتخاب کنید.",
            InlineKeyboardMarkup::make()
                ->addRow(
                    InlineKeyboardButton::make('🖥️ سرور', callback_data: 'acc:category:server'),
                    InlineKeyboardButton::make('🌐 دامنه', callback_data: 'acc:category:domain'),
                )
                ->addRow(
                    InlineKeyboardButton::make('💳 کارمزد', callback_data: 'acc:category:fee'),
                    InlineKeyboardButton::make('📦 سایر', callback_data: 'acc:category:other'),
                )
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'acc:cancel')));
    }

    public function category(Nutgram $bot, string $category): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        $data = Cache::get($this->key($id));
        if (! is_array($data) || $data['step'] !== 'category'
            || $data['kind'] !== 'expense'
            || ! in_array($category, ['server', 'domain', 'fee', 'other'], true)) {
            $this->expired($bot);

            return;
        }

        $data['category'] = $category;
        $data['step'] = 'amount';
        Cache::put($this->key($id), $data, now()->addMinutes(15));
        $this->amountPrompt($bot);
    }

    /** Return true only for the current root's private, pending form text. */
    public function text(Nutgram $bot, string $value): bool
    {
        $id = $this->actor($bot, false);
        if ($id === null) {
            return false;
        }

        $key = $this->key($id);
        $data = Cache::get($key);
        if (! is_array($data) || ! in_array($data['step'] ?? '', ['amount', 'memo'], true)) {
            return false;
        }

        // If a different Admin form was opened afterward, yield its text;
        // do not consume host/node/finance input under an older ACC draft.
        if ($this->hasCompeting($id)) {
            Cache::forget($key);

            return false;
        }

        // Existing command/ReplyKeyboard navigation keeps priority. Clear a
        // stale draft rather than interpreting navigation as a memo.
        if (str_starts_with($value, '/') || in_array($value, ReplyMenuLabels::all(), true)) {
            Cache::forget($key);

            return false;
        }

        if ($data['step'] === 'amount') {
            $number = strtr(trim($value), [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]);
            if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $number) !== 1
                || (int) $number < 10_000 || (int) $number > 100_000_000_000) {
                $this->render($bot, 'مبلغ را به تومان، بین ۱۰ هزار تا ۱۰۰ میلیارد وارد کنید.',
                    $this->cancelKeyboard());

                return true;
            }

            $data['amount_rials'] = (int) $number * 10;
            $data['step'] = 'memo';
            Cache::put($key, $data, now()->addMinutes(15));
            $this->render($bot, "📝 توضیح کوتاه را وارد کنید.\n\nاگر توضیحی ندارید، یک خط تیره <code>-</code> بفرستید.",
                $this->cancelKeyboard());

            return true;
        }

        $memo = trim($value);
        if (mb_strlen($memo) > 200 || $memo === '') {
            $this->render($bot, 'توضیح حداکثر ۲۰۰ نویسه باشد. برای بدون توضیح «-» بفرستید.',
                $this->cancelKeyboard());

            return true;
        }

        $data['memo'] = $memo === '-' ? null : $memo;
        $data['step'] = 'confirm';
        Cache::put($key, $data, now()->addMinutes(15));

        $label = $data['kind'] === 'expense' ? 'ثبت هزینه' : 'برداشت مالک';
        $this->render($bot, '<b>✅ تأیید '.$label.'</b>'
            ."\n\nمبلغ: <b>".TelegramUi::toman($data['amount_rials']).' تومان</b>'
            ."\nدسته: ".self::categoryLabel($data['category'])
            ."\nتوضیح: ".TelegramUi::escape($data['memo'] ?? 'ندارد')
            ."\n\n<blockquote>این ثبت فقط در دفتر شخصی ذخیره می‌شود و هیچ وجهی جابه‌جا نمی‌شود.</blockquote>",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ثبت نهایی', callback_data: 'acc:apply:'.$data['token'], style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'acc:cancel')));

        return true;
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        $key = $this->key($id);
        $data = Cache::get($key);
        if (! is_array($data) || ($data['step'] ?? null) !== 'confirm'
            || ! hash_equals((string) $data['token'], $token)) {
            $this->expired($bot);

            return;
        }

        try {
            $entryId = $this->entries->record(
                $id, $data['kind'], $data['category'], $data['amount_rials'],
                $data['memo'], $token,
            );
        } catch (ValidationException) {
            $this->expired($bot);

            return;
        }

        Cache::forget($key);
        $this->render($bot, "✅ ثبت مالی <b>#{$entryId}</b> انجام شد.\n\nهیچ تراکنش بانکی یا برداشت از کیف پول صورت نگرفت.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📋 سوابق', callback_data: 'acc:history:1'))
                ->addRow(InlineKeyboardButton::make('◀️ دفتر مالی', callback_data: 'acc:home')));
    }

    public function history(Nutgram $bot, string $page): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        $number = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($number === false) {
            $this->expired($bot);

            return;
        }

        $data = $this->entries->history($id, $number);
        $lines = ['<b>📋 سوابق دفتر مالی</b>', ''];
        foreach ($data['entries'] as $entry) {
            $sign = $entry->reversal_of_id !== null ? '↩️ اصلاح' :
                ($entry->kind === 'expense' ? '➖ هزینه' : '💸 برداشت مالک');
            $lines[] = $sign.' #'.$entry->id.' · '.TelegramUi::toman((int) $entry->amount_rials).' تومان';
            $lines[] = '  📅 '.PersianCalendar::utcDateTime((string) $entry->created_at).' (ایران)';
            $lines[] = '  '.self::categoryLabel($entry->category)
                .' · '.TelegramUi::escape(mb_strimwidth($entry->memo ?? 'بدون توضیح', 0, 85));
        }
        if ($data['entries']->isEmpty()) {
            $lines[] = 'هنوز ثبت مالی ندارید.';
        }
        $lines[] = "\nتعداد ثبت‌ها: ".number_format($data['total']).' | صفحه '.$number;

        $keyboard = InlineKeyboardMarkup::make();
        foreach ($data['entries'] as $entry) {
            if ($entry->reversal_of_id === null && (int) $entry->is_reversed === 0) {
                $keyboard->addRow(InlineKeyboardButton::make('↩️ اصلاح ثبت #'.$entry->id,
                    callback_data: 'acc:reverse:prepare:'.$entry->id));
            }
        }
        if ($number > 1) {
            $keyboard->addRow(InlineKeyboardButton::make('◀️ قبلی', callback_data: 'acc:history:'.($number - 1)));
        }
        if ($number * 8 < $data['total']) {
            $keyboard->addRow(InlineKeyboardButton::make('بعدی ▶️', callback_data: 'acc:history:'.($number + 1)));
        }
        $keyboard->addRow(InlineKeyboardButton::make('◀️ دفتر مالی', callback_data: 'acc:home'));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function prepareReverse(Nutgram $bot, string $entryId): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        $number = filter_var($entryId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            $entry = $this->entries->previewReversal($id, $number === false ? 0 : $number);
        } catch (ValidationException) {
            $this->expired($bot);

            return;
        }

        $token = bin2hex(random_bytes(16));
        Cache::put($this->key($id), ['step' => 'reverse', 'entry_id' => $number, 'token' => $token],
            now()->addMinutes(15));
        $this->render($bot, '⚠️ <b>اصلاح ثبت #'.$number.'</b>'
            ."\n\nبرای حفظ سوابق، یک ثبت معکوس با همان مبلغ ساخته می‌شود."
            ."\nمبلغ: ".TelegramUi::toman((int) $entry->amount_rials).' تومان'
            ."\n\nهیچ پولی جابه‌جا نمی‌شود.",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ ثبت اصلاحیه',
                    callback_data: 'acc:reverse:apply:'.$number.':'.$token, style: ButtonStyle::SUCCESS))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'acc:cancel')));
    }

    public function applyReverse(Nutgram $bot, string $entryId, string $token): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }

        $data = Cache::get($this->key($id));
        if (! is_array($data) || ($data['step'] ?? null) !== 'reverse'
            || ! hash_equals((string) $data['token'], $token)
            || (string) $data['entry_id'] !== $entryId) {
            $this->expired($bot);

            return;
        }

        try {
            $newId = $this->entries->reverse($id, (int) $entryId, $token);
        } catch (ValidationException) {
            $this->expired($bot);

            return;
        }

        Cache::forget($this->key($id));
        $this->render($bot, '✅ اصلاحیه #'.$newId.' ثبت شد. ثبت اصلی برای حسابرسی باقی ماند.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📋 سوابق', callback_data: 'acc:history:1')));
    }

    public function cancel(Nutgram $bot): void
    {
        $id = $this->actor($bot);
        if ($id === null) {
            return;
        }
        Cache::forget($this->key($id));
        $this->home($bot);
    }

    private function actor(Nutgram $bot, bool $deny = true): ?int
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor === null) {
            if ($deny) {
                $this->menus->denyAccess($bot);
            }

            return null;
        }
        try {
            $this->access->reviewer($actor->userId);
        } catch (AuthorizationException) {
            if ($deny) {
                $this->menus->denyAccess($bot);
            }

            return null;
        }

        return $actor->userId;
    }

    private function amountPrompt(Nutgram $bot): void
    {
        $this->render($bot, "💰 مبلغ را به <b>تومان</b> وارد کنید.\nمثلاً: <code>250000</code>",
            InlineKeyboardMarkup::make()
                ->addRow(TelegramUi::copy('250000', '📋 کپی مبلغ نمونه'))
                ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'acc:cancel')));
    }

    private function expired(Nutgram $bot): void
    {
        $this->render($bot, 'این درخواست معتبر نیست یا زمان آن تمام شده است. از دفتر مالی دوباره شروع کنید.',
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📒 دفتر مالی', callback_data: 'acc:home')));
    }

    private function cancelKeyboard(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('❌ انصراف', callback_data: 'acc:cancel'));
    }

    /**
     * Existing Admin flows keep temporary free-text state in the same Cache;
     * retire them before accepting accounting inputs. No user data is changed.
     */
    private function clearCompeting(int $id): void
    {
        foreach (self::competingKeys($id) as $key) {
            Cache::forget($key);
        }
    }

    private function hasCompeting(int $id): bool
    {
        foreach (self::competingKeys($id) as $key) {
            if (Cache::has($key)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function competingKeys(int $id): array
    {
        $prefixes = [
            'nw01:draft:', 'node01:draft:', 'node02:draft:', 'node03:draft:',
            'host01:draft:', 'host02:draft:', 'host02:batch:', 'cfg02:draft:',
            'telegram:finance:wallet:',
        ];
        $keys = [];
        foreach ($prefixes as $prefix) {
            $keys[] = TelegramBotContext::current()->cacheKey($prefix.$id);
        }
        $keys[] = 'nw03:hostDraft:'.$id;
        $keys[] = 'nw03:confirm:'.$id;

        return $keys;
    }

    private function key(int $id): string
    {
        return 'admin:accounting:draft:'.$id;
    }

    private static function categoryLabel(string $code): string
    {
        return match ($code) {
            'server' => 'سرور', 'domain' => 'دامنه', 'fee' => 'کارمزد',
            'other' => 'سایر هزینه‌ها', 'owner' => 'برداشت مالک',
            default => 'نامشخص',
        };
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }

        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
