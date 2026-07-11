<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The website live-chat widget is embedded on the client's OWN domain and
    | calls back to this app cross-origin (fetch/XHR), so the `widget/v1/*` and
    | `widgets/chat/*` paths must send CORS headers. Origins are open here
    | because each request is additionally checked against the widget's own
    | per-widget domain whitelist server-side, and no cookies are used (the
    | visitor session token travels in a header), so credentials stay disabled.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'widget/v1/*', 'widgets/chat/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
