<?php

declare(strict_types=1);

namespace App\Support\Network;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/** Read-only, conservative Radar incident summary for the existing reseller report. */
final readonly class ReadIranNetworkSummary
{
    private const OPERATORS = [
        ['name' => 'همراه اول', 'asn' => 197207],
        ['name' => 'ایرانسل', 'asn' => 44244],
        ['name' => 'رایتل', 'asn' => 57218],
    ];

    public function __construct(private CloudflareRadarClient $radar) {}

    /**
     * No traffic normalization is interpreted as availability. All requests are
     * cached by CloudflareRadarClient; individual failures remain unknown.
     *
     * @return array{country:array{code:string,label:string,detail:string},operators:list<array{name:string,code:string,label:string,detail:string}>,checkedAt:string}
     */
    public function handle(): array
    {
        $now = CarbonImmutable::now('UTC');
        $outages = $this->attempt(fn (): array => $this->radar->outages(null, '1d'));
        $country = $this->assess(
            $this->attempt(fn (): array => $this->radar->anomalies(null, '1d')),
            $outages,
            null,
            $now,
        );

        $operators = [];
        foreach (self::OPERATORS as $operator) {
            $assessment = $this->assess(
                $this->attempt(fn (): array => $this->radar->anomalies($operator['asn'], '1d')),
                $outages,
                $operator['asn'],
                $now,
            );
            $operators[] = ['name' => $operator['name'], ...$assessment];
        }

        return [
            'country' => $country,
            'operators' => $operators,
            // The time of our read, NOT the source measurement timestamp.
            'checkedAt' => $now->setTimezone('Asia/Tehran')->format('H:i'),
        ];
    }

    /**
     * A failure to access Radar never becomes a clean/green status.
     *
     * @param Closure():array<string,mixed> $request
     * @return array<string,mixed>|null
     */
    private function attempt(Closure $request): ?array
    {
        try {
            return $request();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Only the presence of an explicitly relevant outage or anomaly is asserted.
     * No anomaly does not establish that the ISP, Xray or VLESS is healthy.
     *
     * @param array<string,mixed>|null $anomalies
     * @param array<string,mixed>|null $outages
     * @return array{code:string,label:string,detail:string}
     */
    private function assess(?array $anomalies, ?array $outages, ?int $asn, CarbonImmutable $now): array
    {
        $confirmed = false;
        $suspected = false;
        $unreadable = false;

        if ($outages !== null) {
            $rows = $outages['annotations'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                $unreadable = true;
            } else {
                foreach ($rows as $row) {
                    if (! is_array($row) || ($row['eventType'] ?? null) !== 'OUTAGE') {
                        continue;
                    }
                    if ($asn !== null) {
                        $asns = $row['asns'] ?? null;
                        if (! is_array($asns) || ! in_array($asn, array_map(
                            static fn (mixed $item): int => is_int($item) || (is_string($item) && ctype_digit($item))
                                ? (int) $item : -1,
                            $asns,
                        ), true)) {
                            continue;
                        }
                    }
                    $recent = self::overlapsLastDay($row['startDate'] ?? null, $row['endDate'] ?? null, $now);
                    if ($recent === true) {
                        $confirmed = true;
                    } elseif ($recent === null) {
                        $unreadable = true;
                    }
                }
            }
        }

        if ($anomalies !== null) {
            $rows = $anomalies['trafficAnomalies'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) {
                $unreadable = true;
            } else {
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        $unreadable = true;
                        continue;
                    }
                    $recent = self::overlapsLastDay($row['startDate'] ?? null, $row['endDate'] ?? null, $now);
                    if ($recent === null) {
                        $unreadable = true;
                        continue;
                    }
                    if ($recent === false) {
                        continue;
                    }
                    $status = $row['status'] ?? null;
                    if ($status === 'TP' || $status === 'VERIFIED') {
                        $confirmed = true;
                    } elseif ($status === 'UNVERIFIED') {
                        $suspected = true;
                    } else {
                        $unreadable = true;
                    }
                }
            }
        }

        if ($confirmed) {
            return [
                'code' => 'confirmed', 'label' => 'اختلال ثبت‌شده',
                'detail' => 'در ۲۴ ساعت اخیر، گزارش اختلال در داده‌های پایش ثبت شده است.',
            ];
        }
        if ($suspected) {
            return [
                'code' => 'suspected', 'label' => 'احتمال اختلال',
                'detail' => 'نشانه‌ای از اختلال احتمالی در ۲۴ ساعت اخیر دیده شده است.',
            ];
        }
        if ($unreadable || $anomalies === null || ($asn === null && $outages === null)) {
            return [
                'code' => 'unknown', 'label' => 'اطلاعات کافی نیست',
                'detail' => 'اطلاعات کافی برای ارزیابی وضعیت در دسترس نیست.',
            ];
        }

        return [
            'code' => 'no-report', 'label' => 'گزارش اختلالی یافت نشد',
            'detail' => 'در داده‌های بررسی‌شده، گزارشی از اختلال در ۲۴ ساعت اخیر یافت نشد.',
        ];
    }

    /** Null means malformed evidence; false means a valid event outside the window. */
    private static function overlapsLastDay(mixed $start, mixed $end, CarbonImmutable $now): ?bool
    {
        if (! is_string($start) || trim($start) === '' || ($end !== null && ! is_string($end))) {
            return null;
        }

        try {
            $from = CarbonImmutable::parse($start);
            $to = $end !== null ? CarbonImmutable::parse($end) : null;
        } catch (Throwable) {
            return null;
        }

        if ($to !== null && $to->lessThan($from)) {
            return null;
        }

        return $from->lessThanOrEqualTo($now->addMinutes(5))
            && ($to === null || $to->greaterThanOrEqualTo($now->subDay()));
    }
}
