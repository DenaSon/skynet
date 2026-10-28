<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Identity\Application\SubscriptionViewer;
use App\Modules\Provisioning\Application\ReadResellerServiceHealth;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** On-demand small pages: no mass RW scans, no persisted provider response. */
final readonly class ResellerServiceHealthUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private ReadResellerServiceHealth $health,
    ) {}

    public function show(Nutgram $bot, string $cursor = '0', bool $rich = true): void
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return;
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        $actor = $this->actors->handle($id);
        if ($actor === null || ! in_array('reseller', $actor->roles, true)) {
            $this->render($bot, 'این گزارش فقط برای نمایندگان فعال در دسترس است.', $this->back());

            return;
        }
        if (preg_match('/\A(?:0|[1-9][0-9]{0,14})\z/D', $cursor) !== 1) {
            $this->render($bot, 'صفحه انتخاب‌شده معتبر نیست.', $this->back());

            return;
        }

        try {
            $page = $this->health->handle(
                SubscriptionViewer::telegram($id),
                $cursor === '0' ? null : (int) $cursor,
            );
        } catch (Throwable) {
            $this->render($bot, 'گزارش وضعیت سرویس‌ها فعلاً در دسترس نیست. دوباره تلاش کنید.', $this->back());

            return;
        }

        $lines = [
            '<b>📡 وضعیت سرویس‌های من</b>',
            '',
            '📦 کل سرویس‌های ثبت‌شده: '.$page['total'],
            '🗂 فعال در سوابق داخلی: '.$page['localActive'],
            '',
            '<b>🔎 بررسی لحظه‌ای این صفحه ('.$page['checked'].' سرویس)</b>',
            '🟢 فعال: '.$page['active'].' · 🔴 منقضی: '.$page['expired'],
            '🟠 نیازمند پیگیری: '.$page['attention'].' · ⚪ نامشخص: '.$page['unknown'],
            '',
        ];

        $html = '<h4>📡 وضعیت سرویس‌های من</h4>'
            .'<p>📦 ثبت‌شده در سوابق: <b>'.$page['total'].'</b>'
            .'<br>🗂 فعال در سوابق داخلی: '.$page['localActive'].'</p>'
            .'<p>🔎 نتیجه استعلام لحظه‌ای فقط برای <b>'.$page['checked'].' سرویس این صفحه</b>:'
            .'<br>🟢 فعال: '.$page['active'].' · 🔴 منقضی: '.$page['expired']
            .'<br>🟠 نیازمند پیگیری: <b>'.$page['attention'].'</b> · ⚪ نامشخص: <b>'.$page['unknown'].'</b></p>';
        $buttons = InlineKeyboardMarkup::make();
        if ($page['rows'] !== []) {
            $html .= '<h4>📋 وضعیت سرویس‌های این صفحه</h4><table bordered striped compact>';
        }
        if ($page['rows'] === []) {
            $html .= '<p>سرویسی در این صفحه پیدا نشد.</p>';
            $lines[] = 'سرویسی در این صفحه پیدا نشد.';
        }
        foreach ($page['rows'] as $index => $item) {
            $position = $index + 1;
            $lines[] = '🔹 <b>'.$position.'. '.TelegramUi::escape($item['name']).'</b> · '.$item['status'];
            if ($item['action'] !== '') {
                $lines[] = '↳ '.TelegramUi::escape($item['action']);
            }
            $lines[] = '';
            $html .= '<tr><td><b>'.$position.'. '.TelegramUi::escape($item['name']).'</b></td>'
                .'<td>'.TelegramUi::escape($item['status'])
                .($item['action'] === '' ? '' : '<br>↳ '.TelegramUi::escape($item['action']))
                .'</td></tr>';
            $buttons->addRow(InlineKeyboardButton::make('📋 جزئیات '.$position,
                callback_data: 'sales:subs:view:'.$item['orderId'].':0'));
        }

        if ($page['rows'] !== []) {
            $html .= '</table><p>برای مشاهده وضعیت کامل یا بررسی تمدید، «جزئیات» هر سرویس را انتخاب کنید.</p>';
        }

        if ($page['nextBeforeOrderId'] !== null) {
            $buttons->addRow(InlineKeyboardButton::make('موارد بیشتر ◀️',
                callback_data: 'sales:health:'.$page['nextBeforeOrderId']));
        }
        $lines[] = '<blockquote>آمار لحظه‌ای فقط برای همین صفحه است (حداکثر ۶ سرویس). '
            .'اگر استعلامی ممکن نباشد، «نامشخص» نمایش داده می‌شود؛ وضعیت داخلی به‌معنای تأیید لحظه‌ای نیست. '
            .'«نیازمند پیگیری» می‌تواند شامل سرویس فعال با حجم یا اعتبار رو به پایان باشد.</blockquote>';
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی صفحه',
            callback_data: 'sales:health:'.$cursor))
            ->addRow(InlineKeyboardButton::make('📊 گزارش فروش', callback_data: 'sales:report:30'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));

        $text = trim(implode("\n", $lines));
        if ($rich) {
            $html .= '<details><summary>ℹ️ درباره وضعیت‌ها</summary><p>'
                .'استعلام زنده فقط همین صفحه را پوشش می‌دهد (حداکثر ۶ سرویس). '
                .'اگر دریافت وضعیت ممکن نباشد، «نامشخص» نشان داده می‌شود. '
                .'فعال‌بودن در سوابق داخلی، تأیید لحظه‌ای Provider نیست. '
                .'نیازمند پیگیری ممکن است شامل سرویس فعالی باشد که به پایان حجم یا زمان نزدیک است.'
                .'</p></details>';
            $plainButtons = clone $buttons;
            $buttons->addRow(InlineKeyboardButton::make('↩️ نمایش ساده', callback_data: 'sales:health:simple:'.$cursor));
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $html, is_rtl: true), $buttons);
            } catch (TelegramException $exception) {
                report($exception);
                $this->render($bot, $text, $plainButtons);
            }

            return;
        }

        $this->render($bot, $text, $buttons);
    }

    private function back(): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('📊 گزارش‌های من', callback_data: 'sales:report:30'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));
    }

    private function render(Nutgram $bot, string $text, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $text, $keyboard, 'HTML');

            return;
        }
        $bot->sendMessage(text: $text, reply_markup: $keyboard, parse_mode: 'HTML');
    }
}
