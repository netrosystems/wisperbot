<?php

return [
    'omnichannel_ai_answering' => env('OMNICHANNEL_AI_ANSWERING_ENABLED', true),
    'email_ai_answering' => env('EMAIL_AI_ANSWERING_ENABLED', true),
    'ai_reply_debounce_seconds' => (int) env('INBOX_AI_REPLY_DEBOUNCE_SECONDS', 2),
];
