<?php

$actions = [
    'chatbot_reply' => ['label' => 'Chatbot or Knowledge Base answer', 'credits' => 1],
    // Kept as a compatibility alias for callers and historical ledger entries.
    'rag_reply' => ['label' => 'Chatbot or Knowledge Base answer', 'credits' => 1],
    'email_subject' => ['label' => 'Improve an email subject', 'credits' => 1],
    'short_rewrite' => ['label' => 'Short rewrite or correction', 'credits' => 1],
    'kb_quality_review' => ['label' => 'Knowledge Base quality review', 'credits' => 1],
    'automation_ai_step' => ['label' => 'AI step inside an automation run', 'credits' => 1],
    'email_generate' => ['label' => 'Generate a complete email', 'credits' => 2],
    'social_post' => ['label' => 'Generate one social post', 'credits' => 2],
    'workflow_generate' => ['label' => 'Generate an automation workflow', 'credits' => 5],
    'social_plan' => ['label' => 'Generate a multi-post social plan', 'credits' => 5],
    'document_embedding' => ['label' => 'Knowledge Base indexing', 'credits' => 0],
    'provider_test' => ['label' => 'AI provider connection test', 'credits' => 0],
];

return [
    /*
    | Managed-credit enforcement defaults on. Operators may explicitly use shadow
    | mode while auditing a deployment: reservations and costs are recorded, but
    | exhausted accounts are allowed to continue without a negative visible balance.
    */
    'enforce' => (bool) env('AI_CREDITS_ENFORCE', true),
    'reservation_ttl_minutes' => 10,
    'max_concurrent_managed_requests' => 3,
    'managed_requests_per_minute' => 30,
    'free_managed_requests_per_minute' => 10,
    'rate_version' => 1,

    // This catalog is the single source for charging and client-facing labels.
    'actions' => $actions,
    'rates' => array_map(static fn (array $action): int => $action['credits'], $actions),

    'managed' => [
        'provider' => 'openai',
        'routine_model' => env('AI_MANAGED_ROUTINE_MODEL', 'gpt-5-nano'),
        'complex_model' => env('AI_MANAGED_COMPLEX_MODEL', 'gpt-5-mini'),
        'embedding_model' => env('AI_MANAGED_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    /* Estimated input/output prices in micro-USD per million tokens. */
    'costs' => [
        'gpt-5-nano' => ['input' => 50000, 'output' => 400000],
        'gpt-5-mini' => ['input' => 250000, 'output' => 2000000],
        // Qwen 3.7 Flash international pay-as-you-go rate for prompts up to 32K tokens.
        'qwen3.7-flash' => ['input' => 30000, 'output' => 130000],
        'qwen3.7-flash-2026-07-15' => ['input' => 30000, 'output' => 130000],
    ],
];
