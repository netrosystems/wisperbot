<?php

return [
    /*
    | Managed-credit enforcement is intentionally switchable for the staged rollout.
    | In shadow mode reservations and costs are recorded, but exhausted accounts are
    | allowed to continue so operators can reconcile the ledger before hard blocking.
    */
    'enforce' => (bool) env('AI_CREDITS_ENFORCE', false),
    'reservation_ttl_minutes' => 10,
    'max_concurrent_managed_requests' => 3,
    'managed_requests_per_minute' => 30,
    'free_managed_requests_per_minute' => 10,
    'rate_version' => 1,

    'allowances_by_monthly_price_cents' => [
        0 => 100,
        2000 => 1000,
        4000 => 3000,
        15000 => 15000,
    ],

    'rates' => [
        'chatbot_reply' => 1,
        'rag_reply' => 1,
        'email_subject' => 1,
        'short_rewrite' => 1,
        'kb_quality_review' => 1,
        'automation_ai_step' => 1,
        'email_generate' => 2,
        'social_post' => 2,
        'workflow_generate' => 5,
        'social_plan' => 5,
        'provider_test' => 0,
        'document_embedding' => 0,
    ],

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
