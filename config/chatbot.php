<?php

return [
    // Additive text choices. Disable to return to plain-text generation.
    'quick_replies_enabled' => env('CHATBOT_QUICK_REPLIES_ENABLED', true),

    // Business-aware routing is intentionally guarded for staged rollout.
    // On by default since 2026-10-04: the bot page's Strict / Balanced / Flexible depend on it.
    'business_aware_routing_enabled' => env('SMART_BOT_BUSINESS_AWARE_ROUTING', true),
    'business_domain_similarity_threshold' => (float) env('SMART_BOT_BUSINESS_DOMAIN_THRESHOLD', 0.42),
    'business_retrieval_hint_threshold' => (float) env('SMART_BOT_BUSINESS_RETRIEVAL_HINT_THRESHOLD', 0.34),
    'trusted_research_cache_minutes' => (int) env('SMART_BOT_RESEARCH_CACHE_MINUTES', 10),
    'trusted_research_max_pages' => (int) env('SMART_BOT_RESEARCH_MAX_PAGES', 2),

    // Output budget for one Smart Bot reply (JSON reply plus suggested choices).
    // 70 words of Bangla with choices can exceed 320 tokens; a reply cut off
    // here is retried once at double the budget. Kept between 500 and 2000.
    'reply_max_tokens' => (int) env('SMART_BOT_REPLY_MAX_TOKENS', 600),

    // When the model declines a business question the passages do not answer,
    // a Balanced ("business only") bot asks once more for general guidance
    // related to the business instead of sending the fallback.
    'guidance_retry_enabled' => (bool) env('SMART_BOT_GUIDANCE_RETRY', true),

    // Smart Bot 2.0 answer engine (Phase 1). A bot answers with it only while
    // this switch is on and the bot is moved to it (`php artisan ai:engine`).
    'engine_v2_enabled' => (bool) env('SMART_BOT_ENGINE_V2', false),
    // A small model call that checks the knowledge states what the reply says.
    'v2_support_check' => (bool) env('SMART_BOT_V2_SUPPORT_CHECK', true),
    // Links, emails, phone numbers and dates: recorded only, until this is on.
    'v2_validator_enforce' => (bool) env('SMART_BOT_V2_VALIDATOR_ENFORCE', false),
    // Passages below this score are not shown to the model; a Strict bot with
    // nothing at or above it offers a person without a model call.
    'v2_min_score' => (float) env('SMART_BOT_V2_MIN_SCORE', 0.30),
    // Reply length per bot (`ai_chatbots.reply_length`), chosen on the bot
    // page and read by both engines: the words and sentences the prompt asks
    // for, and the output budget. Standard is the length every bot used before
    // the setting existed. Engine v2 allows twice the words for email.
    'reply_lengths' => [
        'short' => ['words' => 40, 'sentences' => 3, 'max_tokens' => 500],
        'standard' => ['words' => 70, 'sentences' => 4, 'max_tokens' => 700],
        'detailed' => ['words' => 140, 'sentences' => 8, 'max_tokens' => 1000],
    ],
    // Engine v2 reads a knowledge base this small whole, in the cacheable part
    // of the prompt, instead of searching it (0 turns this off). Estimated
    // tokens: characters ÷ 4, one per non-ASCII character. On a client's own
    // key their provider pays for these input tokens on every reply.
    'v2_full_context_max_tokens' => (int) env('SMART_BOT_FULL_CONTEXT_MAX_TOKENS', 20000),

    // Reply choices as WhatsApp reply buttons and Messenger/Instagram quick
    // replies when they fit; numbered text otherwise (Phase 1.7).
    'native_choices' => (bool) env('SMART_BOT_NATIVE_CHOICES', true),

    // The answer-quality test set (Phase 1.6): `ai:eval:synthesize`, `ai:eval`.
    'eval' => [
        // Only these may use LlmGateway::platformChat() (platform-billed).
        'platform_features' => ['eval_synthesize', 'eval_judge'],
        // A run passes when it meets all of these (Phase 1 acceptance).
        'targets' => [
            'answered_rate' => 0.85,
            'declined_rate' => 0.90,
            'invented_figures' => 0,
        ],
        'default_cases' => 60,
        'max_cases' => 100,
        // Translated cases test multilingual answers; bn-Latn is romanised
        // Bangla ("apnader plan er dam koto?").
        'languages' => ['bn', 'bn-Latn', 'ar'],
    ],
];
