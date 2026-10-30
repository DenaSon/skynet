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

        // Operator-supplied content is plain text, never trusted Telegram HTML.
        $escaped = TelegramUi::escape($template);

        return strtr($escaped, [
            '{name}' => TelegramUi::escape($actor->name),
            '{account_type}' => $reseller ? '🧩 پنل فروش' : '👤 کاربر عادی',
        ]);
    }
}
