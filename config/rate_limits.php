<?php

return [
    // Authenticated mobile traffic has its own budget, separate from developer APIs.
    'mobile_api_per_minute' => max(1, (int) env('MOBILE_API_RATE_LIMIT_PER_MINUTE', 300)),
];
