<?php

return [
    'enabled' => env('CHANNEL_HEALTH_ENABLED', false),
    // Empty enables every workspace; use IDs for an initial operator-only rollout.
    'workspace_ids' => array_values(array_filter(explode(',', (string) env('CHANNEL_HEALTH_WORKSPACE_IDS', '')))),
    'interval_seconds' => 900,
    'stale_seconds' => 1800,
    'retention_days' => 90,
    // Explicit deployment configuration, never accepted from a client request.
    'operator_business_id' => env('META_OPERATOR_BUSINESS_ID'),
    'operator_waba_ids' => array_values(array_filter(explode(',', (string) env('META_OPERATOR_WABA_IDS', '')))),
];
