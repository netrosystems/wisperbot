<?php

return [
    'guarded_publishing' => (bool) env('KB_GUARDED_PUBLISHING', false),
    'retrieval_match_threshold' => 0.60,
    'max_context_chunks' => 3,
    'max_context_tokens' => 1200,
    'max_context_characters' => 6000,
    'semantic_cache_threshold' => 0.92,
    'answer_cache_hours' => 24,
    'query_embedding_cache_days' => 7,
    'sitemap_page_limit' => 200,
    'sitemap_response_max_bytes' => 20_000_000,
    'chunk_target_words' => 280,
    'chunk_overlap_words' => 35,
    'critical_test_pass_percent' => 100,
    'normal_test_pass_percent' => 80,
];
