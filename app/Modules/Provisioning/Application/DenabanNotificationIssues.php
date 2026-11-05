<?php

declare(strict_types=1);

namespace App\Modules\Provisioning\Application;

use Illuminate\Database\Query\Builder;

/**
 * Only operationally actionable terminal notifications belong to Denaban's
 * attention count. Delivery records are never deleted or changed here.
 */
final class DenabanNotificationIssues
{
    private const LOW_PRIORITY_EVENTS = [
        'TrialLowTraffic',
        'TrialExpiring5m',
        'TrialEnded',
        'SubscriptionExpiry24h',
        'SubscriptionExpiry1h',
        'SubscriptionLowTraffic',
    ];

    public static function apply(Builder $query): Builder
    {
        return $query
            ->whereIn('state', ['dead', 'unavailable'])
            ->whereNotIn('event_type', self::LOW_PRIORITY_EVENTS)
            ->where(function (Builder $query): void {
                $query->whereNull('last_failure')
                    ->orWhere('last_failure', '!=', 'superseded_by_activation');
            });
    }
}
