<?php

declare(strict_types=1);

namespace App\Modules\AI\Application;

use App\Modules\Identity\Application\ResolveTelegramActor;
use App\Support\PersianCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * AI-EXEC-03: bounded executive intent selection and follow-ups.
 * Only authorized aggregate read Actions provide evidence; no finance is cached.
 */
final readonly class PrepareDenizExecutiveAnalysis
{
    public function __construct(
        private ReadDenizExecutiveSnapshot $snapshots,
        private ReadDenizDecisionBrief $decisions,
        private ResolveTelegramActor $actors,
    ) {}

    /**
     * @param  array{dataset:string,period:string}|null  $context
     * @return array{prompt:string,headline:string,dataset:string,period:string}|null
     */
    public function handle(int $telegramUserId, string $question, ?array $context = null): ?array
    {
        $selected = self::select($question, $context);
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

        if ($selected['dataset'] === 'decision') {
            return $this->prepareDecision($actor->userId, $question);
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
            'generatedAtFa' => PersianCalendar::localDateTime($snapshot['generatedAt']),
            'windowFa' => self::persianWindows($snapshot),
            'signals' => self::insightSignals($snapshot),
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
                'users' => ['metrics' => $snapshot['metrics'], 'windows' => $snapshot['windows']],
                default => throw new \LogicException('Unsupported executive dataset.'),
            },
        ];

        $prompt = 'پرسش مدیر: '.$question."\n"
            .'داده تجمیعی تأییدشده (مبالغ IRR، زمان تهران): '
            .json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            // Do not truncate financial evidence or present partial data as complete.
            throw new InvalidArgumentException('حجم گزارش برای تحلیل فعلی بیش از حد مجاز است؛ بازه کوتاه‌تری انتخاب کنید.');
        }

        return [
            'prompt' => $prompt,
            'headline' => self::headline($snapshot),
            'dataset' => $selected['dataset'],
            'period' => $selected['period'],
        ];
    }

    /** @return array{prompt:string,headline:string,dataset:string,period:string} */
    private function prepareDecision(int $actorId, string $question): array
    {
        $brief = $this->decisions->handle($actorId);
        $focus = self::decisionFocus($question) ?? 'general';
        $prompt = 'سؤال راهبردی مدیر: '.$question."\n"
            .'شواهد معتبر و نامزدهای بررسی (مبالغ ریال؛ نسبت‌های بین بازه‌های متفاوت ممنوع): '
            .json_encode(
                ['focus' => $focus, 'brief' => $brief],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

        if (mb_strlen($prompt) > (int) config('denagate_ai.max_input_chars', 2000)) {
            // Never silently truncate evidence and draw confident conclusions.
            throw new InvalidArgumentException('حجم داده‌های تصمیم‌سازی بیش از حد مجاز است؛ لطفاً سؤال مشخص‌تری بپرسید.');
        }

        return [
            'prompt' => $prompt,
            'headline' => '🧭 بررسی راهبردی دناگیت'
                ."\nمبنای تحلیل: فروش دو دوره کامل ۷روزه، حسابداری ماه جاری و موجودی لحظه‌ای کیف پول"
                ."\n🕒 ".$brief['timeFa'].' (تهران)',
            'dataset' => 'decision',
            'period' => 'current',
        ];
    }

    /** Financial strategy intent only; simple numeric questions keep concise reports. */
    private static function decisionFocus(string $question): ?string
    {
        $strategic = preg_match('/(?:ریسک|خطر|نگران|اولویت|استراتژ|پیشنهاد|چه\s*کار|چیکار|چه\s*اقدام|چه\s*کنیم|تصمیم|فرصت|سرمایه[‌ ]?گذاری|بهتره|بهتر\s*است|رشد)/u', $question) === 1;
        $business = preg_match('/(?:فروش|سفارش|نمایند|مستقیم|مالی|حسابداری|هزینه|سود|درآمد|بازاریابی|کسب[‌ -]?و[‌ -]?کار|مشتری|اعتبار)/u', $question) === 1;
        if (! $strategic || ! $business) {
            return null;
        }

        if (preg_match('/(?:ریسک|خطر|نگران|مشکل)/u', $question) === 1) {
            return 'risk';
        }

        if (preg_match('/(?:نمایند|مستقیم|کانال)/u', $question) === 1) {
            return 'channel';
        }

        if (preg_match('/(?:رشد|افزایش|فروش|مشتری)/u', $question) === 1) {
            return 'growth';
        }

        return 'general';
    }

    /** @return array{dataset:string,period:string}|null */
    private static function select(string $question, ?array $context = null): ?array
    {
        if (self::decisionFocus($question) !== null) {
            return ['dataset' => 'decision', 'period' => 'current'];
        }

        if (preg_match('/\A\s*(?:دنیز[،,:]?\s*)?(?:تحلیل|گزارش)\s+(شارژ کیف پول|کیف پول|حسابداری|نقدینگی|دریافتی‌ها|دریافتی ها|نمایندگی|فروش|مالی|کاربران|کاربرها|کاربر)(?=\s|\z)/u', trim($question), $matches) !== 1) {
            return self::naturalSelection($question, $context);
        }

        $subject = $matches[1];
        $dataset = match ($subject) {
            'فروش', 'نمایندگی' => 'sales',
            'شارژ کیف پول', 'نقدینگی', 'دریافتی‌ها', 'دریافتی ها' => 'cash',
            'حسابداری', 'مالی' => 'accounting',
            'کیف پول' => 'wallet',
            'کاربران', 'کاربرها', 'کاربر' => 'users',
        };

        // Never silently replace an explicitly requested unsupported duration
        // with a seven-day default. Approved windows only.
        if (preg_match_all('/([0-9۰-۹]+)\\s*(ساعت|روز)/u', $question, $durations, PREG_SET_ORDER) > 0) {
            foreach ($durations as $duration) {
                $number = strtr($duration[1], ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                    '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
                $allowed = match ($dataset) {
                    'sales' => $duration[2] === 'ساعت' && $number === '24',
                    'cash', 'accounting' => $duration[2] === 'روز' && in_array($number, ['7', '30'], true),
                    'wallet' => false,
                    'users' => $duration[2] === 'روز' && in_array($number, ['7', '30'], true),
                };

                if (! $allowed) {
                    throw new InvalidArgumentException('این بازه برای گزارش انتخابی پشتیبانی نمی‌شود. لطفاً یکی از بازه‌های مجاز همان گزارش را انتخاب کنید.');
                }
            }
        }

        // Report semantics are *not* interchangeable. Refuse a requested
        // calendar-current period if the canonical Action supplies another.
        if ($dataset === 'sales' && preg_match('/(?:این ماه|ماه جاری|این هفته|دیروز|(?:۳۰|30)\s*روز|از ابتدا)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش فروش ماهانه فعلاً آخرین ماه کامل شمسی را نشان می‌دهد؛ برای همان بازه «تحلیل فروش ماهانه» را بپرسید.');
        }
        if ($dataset === 'cash' && preg_match('/(?:این ماه|ماه جاری|ماهانه|ماه قبل|امروز|روزانه)/u', $question) === 1) {
            throw new InvalidArgumentException('برای گزارش دریافت‌ها فعلاً بازه‌های ۷ روز، ۳۰ روز و از ابتدا در دسترس است.');
        }
        if ($dataset === 'accounting' && preg_match('/(?:از ابتدا|از آغاز|کل تاریخ|دیروز|این هفته)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش حسابداری فعلاً برای ۷ روز، ۳۰ روز یا ماه جاری در دسترس است.');
        }
        if ($dataset === 'users' && preg_match('/(?:دیروز|ماه قبل|ماه جاری|این ماه|هفته قبل|این هفته|آنلاین|اشتراک فعال|کاربران پنل|(?:۲۴|24)\\s*ساعت)/u', $question) === 1) {
            throw new InvalidArgumentException('در این گزارش فقط کاربران محلی و ثبت‌نام یا خریدهای ۷ و ۳۰ روز اخیر قابل بررسی هستند؛ آمار آنلاین یا اشتراک‌های پنل از این مسیر دریافت نمی‌شود.');
        }
        if ($dataset === 'wallet' && preg_match('/(?:دیروز|گذشته|قبلی|قبل|هفته|ماه|(?:۳۰|30|۷|7)\s*روز)/u', $question) === 1) {
            throw new InvalidArgumentException('گزارش کیف پول فعلاً فقط مانده لحظه‌ای را نشان می‌دهد، نه مانده تاریخی.');
        }

        $period = match ($dataset) {
            'sales' => preg_match('/(?:۲۴|24)\s*ساعت/u', $question) === 1 ? '24h'
                : (preg_match('/(?:امروز|روزانه)/u', $question) === 1 ? 'day'
                    : (preg_match('/(?:ماه|ماهانه)/u', $question) === 1 ? 'month' : 'week')),
            'cash' => preg_match('/(?:از ابتدا|از آغاز|کل تاریخ)/u', $question) === 1 ? 'all'
                : (preg_match('/(?:۳۰|30)\s*روز/u', $question) === 1 ? '30' : '7'),
            'accounting' => preg_match('/(?:۳۰|30)\s*روز/u', $question) === 1 ? '30'
                : (preg_match('/(?:۷|7)\s*روز/u', $question) === 1 ? '7' : 'month'),
            'wallet', 'users' => 'current',
        };

        return ['dataset' => $dataset, 'period' => $period];
    }

    /**
     * Recognize only a small financial vocabulary; never ask the model to
     * choose tables, actions, access scope or raw SQL. Multiple datasets need
     * an explicit narrower question because their periods may not align.
     *
     * @param  array{dataset:string,period:string}|null  $context
     * @return array{dataset:string,period:string}|null
     */
    private static function naturalSelection(string $question, ?array $context): ?array
    {
        $subjectPatterns = [
            'sales' => '/فروش|سفارش|خرید|نمایند/u',
            'cash' => '/نقدینگی|دریافت|شارژ|واریز|ورود\\s*پول|ورودی\\s*پول|پول\\s*وارد/u',
            'accounting' => '/حسابداری|مالی|هزینه|سود|برداشت مالک/u',
            'wallet' => '/کیف پول|مانده اعتبار/u',
            'users' => '/کاربر|ثبت[‌ ]?نام|مشتری|اعضا/u',
        ];

        $datasets = [];
        foreach ($subjectPatterns as $dataset => $pattern) {
            if (preg_match($pattern, $question) === 1) {
                $datasets[] = $dataset;
            }
        }

        // A top-up request is cash, not a wallet-liability balance request.
        if (in_array('cash', $datasets, true) && preg_match('/شارژ\s+کیف پول/u', $question) === 1) {
            $datasets = array_values(array_diff($datasets, ['wallet']));
        }

        if (count($datasets) > 1) {
            throw new InvalidArgumentException('برای تحلیل دقیق، فعلاً فروش، نقدینگی، حسابداری یا کیف پول را جداگانه بپرسید؛ بازه گزارش‌های مختلف همیشه یکسان نیست.');
        }

        $hasPeriod = preg_match('/(?:۲۴|24)\s*ساعت|امروز|دیروز|روزانه|هفته|هفتگی|ماه|ماهانه|(?:۳۰|30|۷|7)\s*روز|از ابتدا|از آغاز|کل تاریخ/u', $question) === 1;

        if ($datasets === []) {
            if ($context === null
                || preg_match('/چرا|چطور|چه |چی |چقدر|بیشتر توضیح|مقایسه|نسبت به|پیشنهاد|بهتر|بدتر|رشد|افت|کاهش|افزایش|حالا|وضعیت/u', $question) !== 1) {
                return null;
            }

            if (! $hasPeriod) {
                return $context;
            }

            $dataset = $context['dataset'];
        } else {
            $dataset = $datasets[0];

            if (! $hasPeriod && $context !== null && $context['dataset'] === $dataset) {
                return $context;
            }
        }

        // Delegate all period validation and defaulting to the existing
        // canonical explicit selector: no second financial period policy.
        $subject = match ($dataset) {
            'sales' => 'فروش',
            'cash' => 'نقدینگی',
            'accounting' => 'حسابداری',
            'wallet' => 'کیف پول',
            'users' => 'کاربران',
            default => throw new InvalidArgumentException('نوع گزارش پشتیبانی نمی‌شود.'),
        };

        $allCash = $dataset === 'cash' && ! $hasPeriod
            && preg_match('/(?:کل|مجموع|جمع)\\s*(?:ورود|ورودی|دریافت|واریز|نقدینگی)/u', $question) === 1;

        return self::select('تحلیل '.$subject.' '.$question.($allCash ? ' از ابتدا' : ''));
    }

    /**
     * Display-only Persian dates supplied by Laravel, not generated by AI.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private static function persianWindows(array $snapshot): array
    {
        if ($snapshot['dataset'] === 'sales') {
            return [
                'currentFrom' => PersianCalendar::localDateTime($snapshot['windows']['current']['from']),
                'currentUntilExclusive' => PersianCalendar::localDateTime($snapshot['windows']['current']['until']),
                'previousFrom' => PersianCalendar::localDateTime($snapshot['windows']['previous']['from']),
                'previousUntilExclusive' => PersianCalendar::localDateTime($snapshot['windows']['previous']['until']),
            ];
        }

        if (in_array($snapshot['dataset'], ['cash', 'accounting'], true)) {
            $start = $snapshot['window']['start'];

            return [
                'start' => $start === null ? null : PersianCalendar::date($start),
                'endInclusive' => PersianCalendar::date($snapshot['window']['endInclusive']),
            ];
        }

        if ($snapshot['dataset'] === 'users') {
            return [
                'last7DaysFrom' => PersianCalendar::date($snapshot['windows']['last7Days']['start']),
                'last30DaysFrom' => PersianCalendar::date($snapshot['windows']['last30Days']['start']),
                'endInclusive' => PersianCalendar::date($snapshot['windows']['last7Days']['endInclusive']),
            ];
        }

        return [];
    }

    /**
     * Only arithmetic signals from the same verified source window.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, int|float|null>
     */
    private static function insightSignals(array $snapshot): array
    {
        $ratio = static fn (int $part, int $whole): ?float => $whole > 0
            ? round($part * 100 / $whole, 1) : null;

        return match ($snapshot['dataset']) {
            'sales' => [
                'averageOrderRials' => $snapshot['windows']['current']['transactions'] > 0
                    ? intdiv($snapshot['windows']['current']['revenueRials'], $snapshot['windows']['current']['transactions']) : null,
                'previousAverageOrderRials' => $snapshot['windows']['previous']['transactions'] > 0
                    ? intdiv($snapshot['windows']['previous']['revenueRials'], $snapshot['windows']['previous']['transactions']) : null,
                'renewalSharePercent' => $ratio($snapshot['windows']['current']['renewals'], $snapshot['windows']['current']['transactions']),
            ],
            'cash' => [
                'topupShareOfCashPercent' => $ratio($snapshot['metrics']['topupsRials'], $snapshot['metrics']['cashInRials']),
            ],
            'accounting' => [
                'recordedExpenseSharePercent' => $ratio($snapshot['metrics']['expensesRials'], $snapshot['metrics']['salesRials']),
            ],
            'wallet' => [
                'resellerShareOfCreditPercent' => $ratio($snapshot['metrics']['resellerBalanceRials'], $snapshot['metrics']['balanceRials']),
            ],
            'users' => [
                'activeAccountSharePercent' => $ratio($snapshot['metrics']['activeAccounts'], $snapshot['metrics']['registeredUsers']),
            ],
            default => [],
        };
    }

    /** @param array<string, mixed> $snapshot */
    private static function headline(array $snapshot): string
    {
        $generated = PersianCalendar::localDateTime($snapshot['generatedAt']).' (تهران)';
        $money = static fn (int $rials): string => number_format(intdiv($rials, 10)).' تومان';

        return match ($snapshot['dataset']) {
            'sales' => '📊 فروش · '.match ($snapshot['period']) {
                'day' => 'امروز تا این ساعت در برابر دیروز تا همین ساعت',
                'week' => '۷ روز کامل گذشته در برابر ۷ روز قبل',
                'month' => 'آخرین ماه کامل شمسی در برابر ماه قبل',
                '24h' => '۲۴ ساعت اخیر در برابر ۲۴ ساعت قبل از آن',
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
            ."\nبازه شمسی: ".PersianCalendar::date($snapshot['window']['start']).' تا '.PersianCalendar::date($snapshot['window']['endInclusive'])
            ."\nسود برآوردی (نه قطعی): ".$money($snapshot['metrics']['profitRials'])
            ."\n🕒 ".$generated,
            'wallet' => '💳 موجودی لحظه‌ای کیف پول‌ها'
                ."\nمانده کل: ".$money($snapshot['metrics']['balanceRials'])
                .' | نمایندگان: '.$money($snapshot['metrics']['resellerBalanceRials'])
                .' | کاربران عادی: '.$money($snapshot['metrics']['memberBalanceRials'])
                .' | سایر: '.$money($snapshot['metrics']['otherBalanceRials'])
                ."\n🕒 ".$generated,
            'users' => '👥 کاربران محلی دناگیت'
                ."\nثبت‌شده: ".$snapshot['metrics']['registeredUsers']
                .' | حساب‌های فعال: '.$snapshot['metrics']['activeAccounts']
                .' | ثبت‌نام ۷ روز: '.$snapshot['metrics']['newUsers7Days']
                .' | ثبت‌نام ۳۰ روز: '.$snapshot['metrics']['newUsers30Days']
                .' | خریداران یکتای ۳۰ روز: '.$snapshot['metrics']['uniqueBuyers30Days']
                ."\n🕒 ".$generated,
            default => throw new \LogicException('Unsupported executive dataset.'),
        };
    }
}
