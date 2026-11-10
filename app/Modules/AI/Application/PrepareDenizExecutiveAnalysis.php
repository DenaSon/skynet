<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use App\Modules\Identity\Application\ResolveTelegramActor;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * AI-EXEC-02: explicit, bounded executive requests only.
 *
 * Open-ended dataset selection and conversational follow-ups belong to phase 3.
 * Financial records never enter a generic chat or conversation cache.
 */
final readonly class PrepareDenizExecutiveAnalysis
{
    public function __construct(
        private ReadDenizExecutiveSnapshot $snapshots,
        private ResolveTelegramActor $actors,
    ) {}

    /**
     * @return array{prompt:string,headline:string}|null
     */
    public function handle(int $telegramUserId, string $question): ?array
    {
        $selected = self::select($question);
        if ($selected === null) {
            return null;
        }

        if (mb_strlen($question) > 300) {
            throw new InvalidArgumentException('لطفاً درخواست تحلیل مالی را کوتاه‌تر بنویسید.');
        }

        $actor = $this->actors->handle($telegramUserId);
        if ($actor?->canManageRoles() !== true) {
            throw new AuthorizationException;
        }

        $snapshot = $this->snapshots->handle($actor->userId, $selected['dataset'], $selected['period']);

        // Serialize an explicit, small aggregate whitelist. Never pass source
        // rows, buyer IDs, bank references, user details or ledger entries.
        $evidence = [
            'dataset' => $snapshot['dataset'],
            'period' => $snapshot['period'],
            'timezone' => $snapshot['timezone'],
            'currency' => $snapshot['currency'],
            'source' => $snapshot['source'],
            'generatedAt' => $snapshot['generatedAt'],
            'data' => match ($selected['dataset']) {
                'sales' => [
                    'windows' => $snapshot['windows'],
                    'growth' => $snapshot['growth'],
                    'channels' => $snapshot['channels'],
                    'concentration' => $snapshot['concentration'],
                ],
                'cash' => [
                    'window' => $snapshot['window'],
                    'metrics' => $snapshot['metrics'],
                    'pointInTime' => $snapshot['pointInTime'],
                ],
                'accounting' => [
                    'window' => $snapshot['window'],
                    'metrics' => $snapshot['metrics'],
                ],
                'wallet' => ['metrics' => $snapshot['metrics']],
                default => throw new \LogicException('Unsupported executive dataset.'),
            },
        ];

        $prompt = 'پرسش مدیر: '.$question."\n"
            .'داده تجمیعی تأییدشده (مبالغ IRR، زمان تهران): '
            .json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            // Do not truncate financial evidence or present partial data as complete.
            throw new InvalidArgumentException('حجم گزارش برای تحلیل فعلی بیش از حد مجاز است؛ بازه کوتاه‌تری انتخاب کنید.');
        }

        return [
            'prompt' => $prompt,
            'headline' => self::headline($snapshot),
        ];
    }

    /** @return array{dataset:string,period:string}|null */
    private static function select(string $question): ?array
    {
        if (preg_match('/\A\s*(?:دنیز[،,:]?\s*)?(?:تحلیل|گزارش)\s+(شارژ کیف پول|کیف پول|حسابداری|نقدینگی|دریافتی‌ها|دریافتی ها|نمایندگی|فروش|مالی)(?=\s|\z)/u', trim($question), $matches) !== 1) {
            return null;
        }

        $subject = $matches[1];
        $dataset = match ($subject) {
            'فروش', 'نمایندگی' => 'sales',
            'شارژ کیف پول', 'نقدینگی', 'دریافتی‌ها', 'دریافتی ها' => 'cash',
            'حسابداری', 'مالی' => 'accounting',
            'کیف پول' => 'wallet',
            default => throw new \LogicException('Unsupported executive subject.'),
        };

        // Report semantics are *not* interchangeable. Refuse a requested
        // calendar-current period if the canonical Action supplies another.
        if ($dataset === 'sales' && preg_match('/(?:این ماه|ماه جاری|(?:۳۰|30)\s*روز|از ابتدا)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش فروش ماهانه فعلاً آخرین ماه کامل شمسی را نشان می‌دهد؛ برای همان بازه «تحلیل فروش ماهانه» را بپرسید.');
        }
        if ($dataset === 'cash' && preg_match('/(?:این ماه|ماه جاری|ماهانه|ماه قبل|امروز|روزانه)/u', $question) === 1) {
            throw new InvalidArgumentException('برای گزارش دریافت‌ها فعلاً بازه‌های ۷ روز، ۳۰ روز و از ابتدا در دسترس است.');
        }
        if ($dataset === 'accounting' && preg_match('/(?:از ابتدا|از آغاز|کل تاریخ)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش حسابداری فعلاً برای ۷ روز، ۳۰ روز یا ماه جاری در دسترس است.');
        }
        if ($dataset === 'wallet' && preg_match('/(?:دیروز|گذشته|قبلی|قبل|هفته|ماه|(?:۳۰|30|۷|7)\s*روز)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش کیف پول فعلاً فقط مانده لحظه‌ای را نشان می‌دهد، نه مانده تاریخی.');
        }

        $period = match ($dataset) {
            'sales' => preg_match('/(?:امروز|روزانه)/u', $question) === 1 ? 'day'
                : (preg_match('/(?:ماه|ماهانه)/u', $question) === 1 ? 'month' : 'week'),
            'cash' => preg_match('/(?:از ابتدا|از آغاز|کل تاریخ)/u', $question) === 1 ? 'all'
                : (preg_match('/(?:۳۰|30)\s*روز/u', $question) === 1 ? '30' : '7'),
            'accounting' => preg_match('/(?:۳۰|30)\s*روز/u', $question) === 1 ? '30'
                : (preg_match('/(?:۷|7)\s*روز/u', $question) === 1 ? '7' : 'month'),
            'wallet' => 'current',
            default => throw new \LogicException('Unsupported executive period.'),
        };

        return ['dataset' => $dataset, 'period' => $period];
    }

    /** @param array<string, mixed> $snapshot */
    private static function headline(array $snapshot): string
    {
        $generated = $snapshot['generatedAt'].' (تهران)';
        $money = static fn (int $rials): string => number_format(intdiv($rials, 10)).' تومان';

        return match ($snapshot['dataset']) {
            'sales' => '📊 فروش · '.match ($snapshot['period']) {
                'day' => 'امروز تا این ساعت در برابر دیروز تا همین ساعت',
                'week' => '۷ روز کامل گذشته در برابر ۷ روز قبل',
                'month' => 'آخرین ماه کامل شمسی در برابر ماه قبل',
                default => throw new \LogicException('Unsupported sales period.'),
            }
                ."\nفروش تسویه‌شده: ".$money($snapshot['windows']['current']['revenueRials'])
                .' (قبل: '.$money($snapshot['windows']['previous']['revenueRials']).')'
                ."\nسفارش‌ها: ".$snapshot['windows']['current']['transactions']
                .' | نمایندگی: '.$money($snapshot['channels']['current']['reseller']['revenueRials'])
                .' | مستقیم: '.$money($snapshot['channels']['current']['direct']['revenueRials'])
                ."\n🕒 ".$generated,
            'cash' => '💰 دریافتی‌های ثبت‌شده · '.match ($snapshot['period']) {
                '7' => '۷ روز شامل امروز',
                '30' => '۳۰ روز شامل امروز',
                'all' => 'از ابتدای فعالیت',
                default => throw new \LogicException('Unsupported cash period.'),
            }
                ."\nورودی نقدی: ".$money($snapshot['metrics']['cashInRials'])
                .' | شارژ کیف پول: '.$money($snapshot['metrics']['topupsRials'])
                ."\nفروش با کیف پول (بدون واریز جدید): ".$money($snapshot['metrics']['walletSalesRials'])
                ."\n🕒 ".$generated,
            'accounting' => '📒 حسابداری · '.match ($snapshot['period']) {
                '7' => '۷ روز شامل امروز',
                '30' => '۳۰ روز شامل امروز',
                'month' => 'ماه جاری شمسی تا امروز',
                default => throw new \LogicException('Unsupported accounting period.'),
            }
                ."\nفروش: ".$money($snapshot['metrics']['salesRials'])
                .' | هزینه ثبت‌شده: '.$money($snapshot['metrics']['expensesRials'])
                ."\nسود برآوردی (نه قطعی): ".$money($snapshot['metrics']['profitRials'])
                ."\n🕒 ".$generated,
            'wallet' => '💳 موجودی لحظه‌ای کیف پول‌ها'
                ."\nمانده کل: ".$money($snapshot['metrics']['balanceRials'])
                .' | نمایندگان: '.$money($snapshot['metrics']['resellerBalanceRials'])
                .' | کاربران عادی: '.$money($snapshot['metrics']['memberBalanceRials'])
                ."\n🕒 ".$generated,
            default => throw new \LogicException('Unsupported executive dataset.'),
        };
    }
}
