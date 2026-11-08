<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Interfaces\Telegram\Processing\TelegramBotContext;
use App\Modules\Sales\Application\ReadPublishedAnnouncements;
use Carbon\Carbon;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;

final readonly class SalesAnnouncementsUi
{
    public function __construct(
        private SalesHomeUi $home,
        private ReadPublishedAnnouncements $announcements,
    ) {}

    public function browse(Nutgram $bot): void
    {
        if (! $this->authorized($bot)) {
            return;
        }

        $items = $this->announcements->latest();
        $keyboard = InlineKeyboardMarkup::make();
        $rich = '<h3>📢 اطلاعیه‌ها</h3>';
        $plain = '<b>📢 اطلاعیه‌ها</b>';

        if ($items === []) {
            $rich .= '<p>هنوز اطلاعیه‌ای منتشر نشده است.</p>';
            $plain .= "\n\nهنوز اطلاعیه‌ای منتشر نشده است.";
        } else {
            $rich .= '<p>آخرین اخبار و اطلاع‌رسانی‌های دناگیت</p>';
            $plain .= "\n\nآخرین اخبار و اطلاع‌رسانی‌های دناگیت";
            foreach ($items as $index => $item) {
                $title = TelegramUi::escape((string) $item->title);
                $summary = TelegramUi::escape(self::summary((string) $item->body));
                $date = self::date((string) $item->published_at);

                $rich .= '<h4>'.($index + 1).'. '.$title.'</h4>'
                    .'<p>'.$summary.'</p>'
                    .'<p>📅 '.$date.'</p>';
                $plain .= "\n\n<b>".($index + 1).'. '.$title."</b>\n"
                    .$summary."\n📅 ".$date;
                $keyboard->addRow(InlineKeyboardButton::make(
                    ($index + 1).'. '.self::buttonTitle((string) $item->title),
                    callback_data: 'sales:ann:view:'.$item->id,
                ));
            }
        }

        $rich .= '<footer>اطلاعیه‌های منتشرشده دناگیت</footer>';
        $keyboard->addRow(InlineKeyboardButton::make('🏠 بازگشت به خانه', callback_data: 'sales:home'));
        $this->render($bot, $rich, $plain, $keyboard);
    }

    public function detail(Nutgram $bot, string $id): void
    {
        if (! $this->authorized($bot)) {
            return;
        }

        // Unpublished notices cannot be read via old, forwarded or forged callbacks.
        $number = preg_match('/\A[1-9][0-9]{0,8}\z/D', $id) === 1 ? (int) $id : 0;
        $item = $number > 0 ? $this->announcements->find($number) : null;
        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(InlineKeyboardButton::make('◀️ بازگشت به اطلاعیه‌ها', callback_data: 'sales:ann:list'))
            ->addRow(InlineKeyboardButton::make('🏠 خانه', callback_data: 'sales:home'));

        if ($item === null) {
            $this->render(
                $bot,
                '<h3>📢 اطلاعیه‌ها</h3><p>این اطلاعیه دیگر در دسترس نیست.</p>',
                '<b>📢 اطلاعیه‌ها</b>'."\n\nاین اطلاعیه دیگر در دسترس نیست.",
                $keyboard,
            );

            return;
        }

        $title = TelegramUi::escape((string) $item->title);
        $body = TelegramUi::escape((string) $item->body);
        $date = self::date((string) $item->published_at);
        $rich = '<h3>📢 '.$title.'</h3>'
            .'<p>📅 '.$date.'</p>'
            .'<p>'.str_replace("\n", '<br>', $body).'</p>'
            .'<footer>اطلاعیه دناگیت</footer>';
        $plain = '<b>📢 '.$title.'</b>'."\n📅 ".$date."\n\n".$body;

        $this->render($bot, $rich, $plain, $keyboard);
    }

    private function authorized(Nutgram $bot): bool
    {
        return TelegramBotContext::current()->key === TelegramBotContext::SALES
            && $this->home->navigationActor($bot) !== null;
    }

    private static function summary(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119).'…' : $text;
    }

    private static function buttonTitle(string $title): string
    {
        return mb_strlen($title) > 32 ? mb_substr($title, 0, 31).'…' : $title;
    }

    private static function date(string $date): string
    {
        return Carbon::parse($date)->timezone('Asia/Tehran')->format('Y/m/d');
    }

    private function render(Nutgram $bot, string $rich, string $plain, InlineKeyboardMarkup $keyboard): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }

        try {
            TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $keyboard);
        } catch (TelegramException $exception) {
            report($exception);
            // Use the same authorized snapshot and callbacks on Rich-incompatible clients.
            if ($bot->isCallbackQuery()) {
                TelegramUi::editText($bot, $plain, $keyboard, 'HTML');
            } else {
                $bot->sendMessage(text: $plain, reply_markup: $keyboard, parse_mode: 'HTML');
            }
        }
    }
}
