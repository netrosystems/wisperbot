<?php

return [
    'enabled' => (bool) env('SOCIAL_COMMENTS_ENABLED', false),
    'graph_version' => 'v25.0',
    'sync_days' => 30,
    'sync_interval_minutes' => 15,
];
