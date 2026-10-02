<?php

return [
    // Additive text choices. Disable to return to plain-text generation.
    'quick_replies_enabled' => env('CHATBOT_QUICK_REPLIES_ENABLED', true),

    // Business-aware routing is intentionally guarded for staged rollout.
    'business_aware_routing_enabled' => env('SMART_BOT_BUSINESS_AWARE_ROUTING', false),
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
];
