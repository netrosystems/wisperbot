<?php

return [
    'guarded_publishing' => (bool) env('KB_GUARDED_PUBLISHING', false),
    // Platform-managed Smart Bot retrieval policy. These are deliberately not
    // tenant-editable; SmartBotRetrievalPolicy applies conservative bounds.
    'retrieval_match_threshold' => (float) env('KB_RETRIEVAL_MATCH_THRESHOLD', 0.60),
    'max_context_chunks' => (int) env('KB_MAX_CONTEXT_CHUNKS', 3),
    'max_context_tokens' => (int) env('KB_MAX_CONTEXT_TOKENS', 1200),
    'video_match_threshold' => (float) env('KB_VIDEO_MATCH_THRESHOLD', 0.72),
    'max_context_characters' => 6000,
    'semantic_cache_threshold' => 0.92,
    'answer_cache_hours' => 24,
    'query_embedding_cache_days' => 7,
    'sitemap_page_limit' => 200,
    'sitemap_response_max_bytes' => 20_000_000,
    'url_max_redirects' => 6,
    'chunk_target_words' => 280,
    'chunk_overlap_words' => 35,
    'semantic_chunk_target_words' => (int) env('KB_SEMANTIC_CHUNK_WORDS', 160),
    'semantic_chunk_overlap_words' => (int) env('KB_SEMANTIC_CHUNK_OVERLAP_WORDS', 24),
    'current_index_version' => 2,
    'hybrid_retrieval_enabled' => (bool) env('KB_HYBRID_RETRIEVAL_ENABLED', false),
    'live_product_facts_enabled' => (bool) env('KB_LIVE_PRODUCT_FACTS_ENABLED', false),
    'live_product_freshness_minutes' => (int) env('KB_LIVE_PRODUCT_FRESHNESS_MINUTES', 15),
    'live_product_refresh_batch' => (int) env('KB_LIVE_PRODUCT_REFRESH_BATCH', 50),
    'live_product_requests_per_minute' => (int) env('KB_LIVE_PRODUCT_REQUESTS_PER_MINUTE', 20),
    'clarification_threshold_margin' => (float) env('KB_CLARIFICATION_THRESHOLD_MARGIN', 0.20),
    'clarification_min_threshold' => (float) env('KB_CLARIFICATION_MIN_THRESHOLD', 0.38),
    'critical_test_pass_percent' => 100,
    'normal_test_pass_percent' => 80,
];
