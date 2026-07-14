<?php

return [
    'developer_tools' => [
        'key' => 'developer_tools',
        'name' => 'Developer Tools',
        'description' => 'API tokens, outbound webhooks, and API documentation for external integrations.',
        'price_cents' => 5000,
        'currency' => 'USD',
        'interval' => 'month',
        'stripe_price_id' => env('STRIPE_DEVELOPER_TOOLS_PRICE_ID'),
        'paddle_price_id' => env('PADDLE_DEVELOPER_TOOLS_PRICE_ID'),
    ],
];
