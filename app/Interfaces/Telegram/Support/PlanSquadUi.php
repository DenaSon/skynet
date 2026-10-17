<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ManagePlanSquadBinding;
use App\Modules\Sales\Application\ManagePlans;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use Throwable;

/** OP-02: private, root-only, two-step binding to a remotely read existing Squad. */
final readonly class PlanSquadUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $resolve,
        private ManagePlans $plans,
        private ManagePlanSquadBinding $binding,
        private MenuRenderer $menus,
    ) {}

    public function browse(Nutgram $bot, string $id): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $planId = $this->id($id);
        if ($planId === null) {
            $this->render($bot, 'پلن انتخاب‌شده معتبر نیست.', $this->back(0));

            return;
        }
        try {
            $plan = $this->plans->find($actor, $planId);
            $choices = $this->binding->choices($actor, $planId);
        } catch (Throwable) {
            $this->render($bot, 'دریافت سرویس‌های پنل ممکن نیست. بعداً دوباره تلاش کنید.', $this->back($planId));

            return;
        }

        $keyboard = InlineKeyboardMarkup::make();
        $lines = ['🔗 اتصال پلن به سرویس', 'پلن: '.$this->safe($plan->name), '',
            'یک سرویس موجود را انتخاب کنید. اتصال تنها پس از تأیید شما ثبت می‌شود.'];
        foreach (array_slice($choices, 0, 20) as $index => $squad) {
            $lines[] = ($index + 1).'. '.$this->safe($squad['name']);
            $keyboard->addRow(InlineKeyboardButton::make(
                'انتخاب '.$this->safe($squad['name']),
                callback_data: 'plan:squad:select:'.$planId.':'.$index,
            ));
        }
        if ($choices === []) {
            $lines[] = 'هنوز سرویسی در پنل وجود ندارد.';
        } elseif (count($choices) > 20) {
            $lines[] = 'فقط ۲۰ مورد اول نمایش داده می‌شود.';
        }
        Cache::put($this->key($actor, $planId), [
            'revision' => $plan->revision,
            'squads' => $choices,
        ], now()->addMinutes(5));

        $keyboard->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'plan:view:'.$planId));
        $this->render($bot, implode("\n", $lines), $keyboard);
    }

    public function select(Nutgram $bot, string $id, string $choice): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        $planId = $this->id($id);
        $index = ctype_digit($choice) && strlen($choice) < 3 ? (int) $choice : -1;
        $state = $planId === null ? null : Cache::get($this->key($actor, $planId));
        if (! is_array($state) || ! isset($state['squads'][$index], $state['revision'])
            || ! is_array($state['squads'][$index])) {
            $this->render($bot, 'انتخاب منقضی شده است. دوباره فهرست را باز کنید.', $this->back($planId ?? 0));

            return;
        }
        $token = bin2hex(random_bytes(12));
        Cache::put($this->confirmKey($actor, $token), [
            'plan' => $planId,
            'revision' => $state['revision'],
            'uuid' => $state['squads'][$index]['uuid'],
        ], now()->addMinutes(3));

        $this->render($bot,
            '🔗 تأیید اتصال'."\n\nسرویس: ".$this->safe($state['squads'][$index]['name'])
            ."\n\nآیا این سرویس به پلن انتخاب‌شده متصل شود؟",
            InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('✅ تأیید اتصال', callback_data: 'plan:squad:apply:'.$token))
                ->addRow(InlineKeyboardButton::make('◀️ بازگشت', callback_data: 'plan:squad:list:'.$planId)),
        );
    }

    public function apply(Nutgram $bot, string $token): void
    {
        $actor = $this->root($bot);
        if ($actor === null) {
            return;
        }
        if (preg_match('/\A[a-f0-9]{24}\z/D', $token) !== 1) {
            $this->render($bot, 'درخواست نامعتبر است.', $this->back(0));

            return;
        }
        $state = Cache::pull($this->confirmKey($actor, $token));
        if (! is_array($state)) {
            $this->render($bot, 'این تأیید قبلاً استفاده شده یا منقضی است.', $this->back(0));

            return;
        }
        try {
            $this->binding->bind($actor, (int) $state['plan'], (int) $state['revision'], (string) $state['uuid']);
            $text = '✅ اتصال پلن ثبت شد.'."\n\nبرای قابل‌فروش‌شدن پلن، تأیید نهایی آمادگی سرویس همچنان لازم است.";
        } catch (ValidationException) {
            $text = 'اطلاعات پلن تغییر کرده است یا اتصال مجاز نیست. دوباره بررسی کنید.';
        } catch (Throwable) {
            $text = 'تأیید سرویس انجام نشد. اتصال جدیدی را قطعی فرض نکنید.';
        }
        $this->render($bot, $text, $this->back((int) $state['plan']));
    }

    private function root(Nutgram $bot): ?int
    {
        $id = $this->private->id($bot);
        if ($id === null) {
            return null;
        }
        $actor = $this->resolve->handle($id);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        return $actor->userId;
    }

    private function id(string $id): ?int
    {
        return preg_match('/\A[1-9][0-9]{0,14}\z/D', $id) === 1 ? (int) $id : null;
    }

    private function key(int $actor, int $plan): string
    {
        return 'op02:plan-squad:'.$actor.':'.$plan;
    }

    private function confirmKey(int $actor, string $token): string
    {
        return 'op02:plan-squad:confirm:'.$actor.':'.$token;
    }

    private function safe(string $name): string
    {
        return mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)), 0, 40);
    }

    private function back(int $plan): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ بازگشت',
            callback_data: $plan > 0 ? 'plan:view:'.$plan : 'plan:list:1',
        ));
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
