<?php

return [
    'omnichannel_ai_answering' => env('OMNICHANNEL_AI_ANSWERING_ENABLED', true),
    'email_ai_answering' => env('EMAIL_AI_ANSWERING_ENABLED', true),
    'ai_reply_debounce_seconds' => (int) env('INBOX_AI_REPLY_DEBOUNCE_SECONDS', 2),
    'media' => [
        'magick_binary' => env('IMAGEMAGICK_BINARY', 'magick'),
        'convert_binary' => env('IMAGEMAGICK_CONVERT_BINARY', 'convert'),
        'heif_convert_binary' => env('HEIF_CONVERT_BINARY', 'heif-convert'),
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    ],
];
