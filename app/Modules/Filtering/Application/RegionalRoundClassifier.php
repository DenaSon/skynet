<?php

declare(strict_types=1);

namespace App\Modules\Filtering\Application;

final class RegionalRoundClassifier
{
    /**
     * @param  array<string,int>  $counts
     * @return 'unknown'|'clear'|'possible'|'likely'
     */
    public static function classify(array $counts): string
    {
        $completed = $counts['completed_probes'] ?? 0;
        $failed = $counts['failed_probes'] ?? 0;
        if ($completed < 4 || ($counts['asn_count'] ?? 0) < 4 || ($counts['city_count'] ?? 0) < 2
            || $failed > $completed) {
            return 'unknown';
        }

        if ($failed >= 4 && $failed * 100 >= 60 * $completed
            && ($counts['failed_asn_count'] ?? 0) >= 4
            && ($counts['failed_city_count'] ?? 0) >= 2) {
            return 'likely';
        }
        if ($failed >= 2 && ($counts['failed_asn_count'] ?? 0) >= 2
            && $failed * 100 >= 25 * $completed) {
            return 'possible';
        }

        return 'clear';
    }

    /**
     * An independent known-good Internet TCP target must be usable from
     * diverse Iranian vantage points before high-confidence escalation.
     *
     * @param  array<string,int>  $counts
     */
    public static function externalControlHealthy(array $counts): bool
    {
        $completed = $counts['completed_probes'] ?? 0;

        return $completed >= 4
            && ($counts['asn_count'] ?? 0) >= 4
            && ($counts['city_count'] ?? 0) >= 2
            && ($completed - ($counts['failed_probes'] ?? 0)) * 4 >= $completed * 3;
    }
}
