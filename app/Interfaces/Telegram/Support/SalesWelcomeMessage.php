<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Support;

use App\Modules\Identity\Application\TelegramActor;
use App\Settings\SalesWelcomeSettings;
use Throwable;

final class SalesWelcomeMessage
{
    public const DEFAULT = "🏠 DenaGate\n\nخوش آمدید، {name}.\nنوع حساب: {account_type}";

    public function forActor(TelegramActor $actor): string
    {
        return $this->render($actor, $this->template($actor));
    }

    /** Custom copy belongs in the interactive Home card, not its keyboard initializer. */
    public function customizedForActor(TelegramActor $actor): ?string
    {
        $template = $this->template($actor);

        return $template === self::DEFAULT ? null : $this->render($actor, $template);
    }

    private function template(TelegramActor $actor): string
    {
        try {
            $settings = app(SalesWelcomeSettings::class);
            $template = in_array('reseller', $actor->roles, true) ? $settings->reseller : $settings->member;
        } catch (Throwable $exception) {
            report($exception);

            return self::DEFAULT;
        }

        return trim($template) === '' ? self::DEFAULT : $template;
    }

    private function render(TelegramActor $actor, string $template): string
    {
        $reseller = in_array('reseller', $actor->roles, true);
        $name = trim((string) preg_replace('/[\\x00-\\x1F\\x7F\\x{202A}-\\x{202E}]+/u', ' ', $actor->name));
        $name = TelegramUi::escape(mb_substr($name, 0, 100));
        $role = $reseller ? '🧩 پنل فروش' : '👤 کاربر عادی';

        // Preserve the original Telegram formatting when using the default.
        if ($template === self::DEFAULT) {
            return '<b>🏠 DenaGate</b>'."\n\nخوش آمدید، ".$name
                .".\nنوع حساب: <b>".$role.'</b>';
        }

        return strtr(TelegramUi::escape($template), [
            '{name}' => $name,
            '{account_type}' => $role,
        ]);
    }
}
