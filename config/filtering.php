<?php

declare(strict_types=1);

return [
    // FLT-02 deliberate rollout gates: read-only sampling may be enabled
    // after validating real RW fields. Alerts need separate live acceptance.
    'node_monitor_enabled' => (bool) env('FILTERING_NODE_MONITOR_ENABLED', false),
    'node_alerts_enabled' => (bool) env('FILTERING_NODE_ALERTS_ENABLED', false),
    'regional_monitor_enabled' => (bool) env('FILTERING_REGIONAL_MONITOR_ENABLED', false),
    'regional_alerts_enabled' => (bool) env('FILTERING_REGIONAL_ALERTS_ENABLED', false),
];
