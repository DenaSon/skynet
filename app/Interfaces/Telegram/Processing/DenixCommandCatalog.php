<?php

declare(strict_types=1);

namespace App\Interfaces\Telegram\Processing;

/**
 * One source for Telegram's group-scoped slash-command menu and actual
 * executive shortcuts. Only the pre-existing trusted group intake can use it.
 */
final class DenixCommandCatalog
{
    private const ANALYSES = [
        'sales24h' => [
            'description' => 'تحلیل فروش ۲۴ ساعت اخیر',
            'question' => 'تحلیل فروش ۲۴ ساعت اخیر',
        ],
        'salesweek' => [
            'description' => 'تحلیل فروش هفتگی',
            'question' => 'تحلیل فروش هفتگی',
        ],
        'resellers' => [
            'description' => 'تحلیل فروش نمایندگان',
            'question' => 'گزارش نمایندگی هفتگی',
        ],
        'cash30' => [
            'description' => 'گزارش نقدینگی ۳۰ روز اخیر',
            'question' => 'گزارش نقدینگی ۳۰ روز',
        ],
        'accounting' => [
            'description' => 'تحلیل مالی ماه جاری',
            'question' => 'تحلیل مالی ماه جاری',
        ],
        'wallet' => [
            'description' => 'گزارش مانده کیف پول‌ها',
            'question' => 'گزارش کیف پول',
        ],
    ];

    /** @return list<array{command:string,description:string}> */
    public static function telegramCommands(): array
    {
        $commands = [['command' => 'ai', 'description' => 'گفتگو با دنیز: /ai و متن سؤال']];

        foreach (self::ANALYSES as $name => $entry) {
            $commands[] = ['command' => $name, 'description' => $entry['description']];
        }

        return [
            ...$commands,
            ['command' => 'aimodel', 'description' => 'نمایش یا تغییر مدل دنیز'],
            ['command' => 'ainew', 'description' => 'شروع گفتگوی تازه'],
            ['command' => 'aistatus', 'description' => 'نمایش وضعیت و سهمیه گفتگو'],
        ];
    }

    public static function analysisQuestion(string $text, string $botUsername): ?string
    {
        if (preg_match('/\A\/([a-z][a-z0-9_]{0,31})(?:@([a-zA-Z][a-zA-Z0-9_]{4,31}))?\s*\z/D', $text, $matches) !== 1
            || (isset($matches[2]) && strcasecmp($matches[2], $botUsername) !== 0)) {
            return null;
        }

        return self::ANALYSES[$matches[1]]['question'] ?? null;
    }
}
