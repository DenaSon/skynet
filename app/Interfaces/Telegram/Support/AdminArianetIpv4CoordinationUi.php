<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Modules\Provisioning\Application\ChangeAdminArianetIpv4;
use App\Modules\Provisioning\Application\CoordinateAdminArianetIpv4;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Exceptions\TelegramException;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;
use SergiX44\Nutgram\Telegram\Types\RichMessage\InputRichMessage;
use Throwable;

/** Root-only DNS and Node IP coordination after explicit confirmation. */
final readonly class AdminArianetIpv4CoordinationUi
{
    public function __construct(
        private PrivateTelegramUser $private,
        private ResolveTelegramActor $actors,
        private CoordinateAdminArianetIpv4 $coordination,
        private ChangeAdminArianetIpv4 $changes,
        private MenuRenderer $menus,
    ) {}

    public function show(Nutgram $bot, string $rawId, string $rawPage): void
    {
        $resolved = $this->actorAndIds($bot, $rawId, $rawPage);
        if ($resolved === null) {
            return;
        }
        [$actor, $id, $page] = $resolved;
        $buttons = self::back($id, $page);
        try {
            $state = $this->coordination->status($actor, $id);
            $operation = $this->changes->status($actor, $id);
        } catch (Throwable) {
            $this->render($bot, 'دریافت وضعیت هماهنگی ممکن نیست.', $buttons);

            return;
        }

        if ($state === null) {
            $message = 'برنامه هماهنگی هنوز آماده نشده است.';
            if (($operation['status'] ?? null) === 'awaiting_coordination') {
                $message .= "\nIPv4 قبلی: ".TelegramUi::escape((string) $operation['oldIpv4'])
                    ."\nIPv4 جدید: ".TelegramUi::escape((string) $operation['newIpv4'])
                    ."\nابتدا وابستگی‌ها را بررسی کنید؛ سپس برنامه را بسازید. این مرحله هیچ تغییری در سرویس‌دهنده‌ها ایجاد نمی‌کند.";
                if ($this->coordination->enabled()) {
                    $buttons = InlineKeyboardMarkup::make()
                        ->addRow(InlineKeyboardButton::make('◀️ بررسی اتصال‌ها',
                            callback_data: 'ari6:impact:'.$id.':'.$page))
                        ->addRow(InlineKeyboardButton::make('📋 ساخت برنامه بدون اعمال تغییر',
                            callback_data: 'ari7:plan:'.$id.':'.$page));
                }
            } else {
                $message .= "\nابتدا نتیجه تغییر IP باید در آریاسرویس تأیید شود.";
            }
            $this->render($bot, $message, $buttons);

            return;
        }

        $stages = [
            'ready' => 'آماده هماهنگی اتصال‌ها',
            'applying' => 'در حال هماهنگی اتصال‌ها',
            'awaiting_client_test' => 'در انتظار تست اتصال واقعی کاربران',
            'completed' => '✅ تأییدشده توسط مدیر پس از تست اتصال',
        ];
        $stage = $stages[$state['stage']] ?? '⚠️ نیازمند بررسی';
        $rows = self::row('وضعیت', $stage)
            .self::row('IPv4 قبلی', $state['oldIp'])
            .self::row('IPv4 جدید', $state['newIp'])
            .self::row('نود', $state['node']);
        $plain = "<b>⚙️ هماهنگی اتصال‌ها</b>\n\n"
            .'وضعیت: '.TelegramUi::escape($stage)
            ."\nIP قبلی: ".TelegramUi::escape($state['oldIp'])
            ."\nIP جدید: ".TelegramUi::escape($state['newIp']);
        foreach ($state['steps'] as $index => $step) {
            $kind = $step['kind'] === 'node' ? '🖥 IP مدیریتی' : '🌐 DNS';
            $status = match ($step['state']) {
                'verified' => '✅ تأییدشده',
                'uncertain' => '⚠️ نیازمند بررسی نتیجه',
                default => 'در انتظار تأیید',
            };
            $rows .= self::row(($index + 1).'. '.$kind.' · '.$step['name'], $status);
            $plain .= "\n".TelegramUi::escape($kind.' · '.$step['name']).': '.TelegramUi::escape($status);
        }

        $buttons = InlineKeyboardMarkup::make();
        $index = $state['next'];
        $readOnlyAction = $state['version'] === 1
            || ($index !== null && $state['steps'][$index]['state'] === 'uncertain')
            || $state['stage'] === 'awaiting_client_test';
        if ($this->coordination->enabled(legacy: $readOnlyAction) && $state['owner']) {
            if ($index !== null && $state['steps'][$index]['state'] === 'ready') {
                $buttons->addRow(InlineKeyboardButton::make(
                    $state['version'] === 1 ? '🔄 ادامه هماهنگی DNS' : '🔴 تأیید و اجرای هماهنگی دامنه‌ها و نود',
                    callback_data: 'ari7:continue:'.$id.':'.$page));
            } elseif ($index !== null && $state['steps'][$index]['state'] === 'uncertain') {
                $buttons->addRow(InlineKeyboardButton::make('🔎 بررسی نتیجه با GET (بدون تکرار)',
                    callback_data: 'ari7:reconcile:'.$id.':'.$page));
            } elseif ($state['stage'] === 'awaiting_client_test') {
                $buttons->addRow(InlineKeyboardButton::make('✅ تست واقعی اتصال کاربران انجام شد',
                    callback_data: 'ari7:accept:'.$id.':'.$page));
            }
        }
        $buttons->addRow(InlineKeyboardButton::make('🔄 تازه‌سازی وضعیت',
            callback_data: 'ari7:status:'.$id.':'.$page))
            ->addRow(InlineKeyboardButton::make('◀️ بررسی اتصال‌ها',
                callback_data: 'ari6:impact:'.$id.':'.$page));

        $notice = ($state['version'] === 1
            ? 'طبق تأیید قبلی، فقط دامنه‌های مرتبط هماهنگ می‌شوند؛ '
            : 'دامنه‌های مرتبط و IP مدیریتی نود طبق مراحل ثبت‌شده هماهنگ می‌شوند؛ ')
            .'در نتیجه نامشخص، تغییر تکرار نمی‌شود. '
            .'تأیید نهایی فقط پس از تست واقعی اتصال کاربران است.';
        $rich = '<h2>⚙️ هماهنگی اتصال‌ها</h2>'
            .'<table bordered striped compact>'.$rows.'</table>'
            .'<footer>🔒 '.TelegramUi::escape($notice).'</footer>';
        $this->render($bot, $plain."\n\n🔒 ".TelegramUi::escape($notice), $buttons, $rich);
    }

    public function act(Nutgram $bot, string $action, string $rawId, string $rawPage, ?string $rawStep = null): void
    {
        $resolved = $this->actorAndIds($bot, $rawId, $rawPage);
        if ($resolved === null) {
            return;
        }
        [$actor, $id, $page] = $resolved;
        try {
            match ($action) {
                // Old DNS-only buttons may not authorize a new Node write.
                'prepare' => null,
                'plan' => $this->coordination->prepare($actor, $id),
                'continue' => $this->coordination->continueDns($actor, $id),
                'step' => ($this->coordination->status($actor, $id)['version'] ?? null) === 1
                    ? $this->coordination->executeNext($actor, $id,
                        self::step($rawStep) ?? throw new \RuntimeException('Invalid step index.'))
                    : null,
                'reconcile' => $this->coordination->reconcile($actor, $id),
                'accept' => $this->coordination->acceptClientTest($actor, $id),
                default => throw new \RuntimeException('Invalid action.'),
            };
        } catch (Throwable) {
            $buttons = InlineKeyboardMarkup::make()
                ->addRow(InlineKeyboardButton::make('📋 مشاهده وضعیت و راهنمای بررسی',
                    callback_data: 'ari7:status:'.$id.':'.$page))
                ->addRow(InlineKeyboardButton::make('◀️ بررسی اتصال‌ها',
                    callback_data: 'ari6:impact:'.$id.':'.$page));
            $this->render($bot, '⚠️ عملیات انجام یا تأیید نشد. وضعیت ثبت‌شده را بررسی کنید؛ '
                .'در صورت نتیجه نامشخص، درخواست را تکرار نکنید.', $buttons);

            return;
        }

        $this->show($bot, $rawId, $rawPage);
    }

    /** @return array{0:int,1:int,2:int}|null */
    private function actorAndIds(Nutgram $bot, string $rawId, string $rawPage): ?array
    {
        $telegramId = $this->private->id($bot);
        if ($telegramId === null) {
            return null;
        }
        $actor = $this->actors->handle($telegramId);
        if ($actor?->canManageRoles() !== true) {
            $this->menus->denyAccess($bot);

            return null;
        }

        $id = self::positive($rawId, PHP_INT_MAX);
        $page = self::positive($rawPage, 100);
        if ($id === null || $page === null) {
            $this->render($bot, 'شناسه سرور معتبر نیست.',
                InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
                    '◀️ سرورهای ابری', callback_data: 'ari2:list:1')));

            return null;
        }

        return [$actor->userId, $id, $page];
    }

    private static function positive(string $raw, int $max): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $raw) !== 1 || strlen($raw) > strlen((string) $max)) {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT);

        return is_int($value) && $value <= $max ? $value : null;
    }

    private static function step(?string $raw): ?int
    {
        if (! is_string($raw) || preg_match('/\A(?:0|[1-9][0-9]?)\z/D', $raw) !== 1) {
            return null;
        }
        $step = (int) $raw;

        return $step < 21 ? $step : null;
    }

    private static function back(int $id, int $page): InlineKeyboardMarkup
    {
        return InlineKeyboardMarkup::make()->addRow(InlineKeyboardButton::make(
            '◀️ بررسی اتصال‌ها', callback_data: 'ari6:impact:'.$id.':'.$page));
    }

    private static function row(string $label, string $value): string
    {
        return '<tr><td>'.TelegramUi::escape($label).'</td><td><b>'
            .TelegramUi::escape($value).'</b></td></tr>';
    }

    private function render(Nutgram $bot, string $fallback, InlineKeyboardMarkup $buttons, ?string $rich = null): void
    {
        if ($bot->isCallbackQuery()) {
            TelegramUi::answerCallback($bot);
        }
        if ($rich !== null) {
            try {
                TelegramUi::editRichText($bot, new InputRichMessage(html: $rich, is_rtl: true), $buttons);

                return;
            } catch (TelegramException) {
                // Preserve authorized HTML fallback and the same controls.
            }
        }
        if ($bot->isCallbackQuery()) {
            TelegramUi::editText($bot, $fallback, $buttons, 'HTML');

            return;
        }
        $bot->sendMessage(text: $fallback, reply_markup: $buttons, parse_mode: 'HTML');
    }
}
