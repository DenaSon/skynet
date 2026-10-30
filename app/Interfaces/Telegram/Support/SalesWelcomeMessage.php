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
        $reseller = in_array('reseller', $actor->roles, true);

        try {
            $settings = app(SalesWelcomeSettings::class);
            $template = $reseller ? $settings->reseller : $settings->member;
        } catch (Throwable $exception) {
            report($exception);
            $template = self::DEFAULT;
        }

        if (trim($template) === '') {
            $template = self::DEFAULT;
        }

        $name = trim((string) preg_replace('/[\\x00-\\x1F\\x7F\\x{202A}-\\x{202E}]+/u', ' ', $actor->name));
        $name = TelegramUi::escape(mb_substr($name, 0, 100));
        $role = $reseller ? '🧩 پنل فروش' : '👤 کاربر عادی';

        // Preserve the exact existing Telegram formatting until an operator
        // explicitly replaces the default with custom plain-text content.
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
