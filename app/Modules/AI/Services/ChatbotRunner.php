<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Message;

class ChatbotRunner
{
    public function __construct(
        private LlmGateway $llmGateway,
        private EmbeddingStore $embedStore,
    ) {}

    public function run(AiChatbot $bot, Message $inboundMessage, bool $throwProviderErrors = false): ?string
    {
        if (! $bot->enabled) {
            return null;
        }

        $conversation = $inboundMessage->conversation;
        $body = $inboundMessage->body ?? '';
        $workspaceId = $conversation->workspace_id;

        // 1. Embed the user query
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$body]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable $e) {
                if ($throwProviderErrors) {
                    throw $e;
                }

                // proceed without retrieval
            }
        }

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // 3. Build prompt
        $systemPrompt = $this->systemPrompt($bot, $conversation->contact);
        if (! empty($contextChunks)) {
            $context = implode("\n\n---\n\n", array_map(fn ($c) => $c->content, $contextChunks));
            $systemPrompt .= "\n\nVerified business context (use it when relevant):\n".$context;
        }

        // Inject the customer's recent orders so the bot can answer "where is my order?".
        // Gated on a connected Ecommerce store; resolved lazily to avoid a hard
        // cross-module dependency (matches the CredentialResolver class_exists pattern).
        $orderSummary = $this->orderSummary($workspaceId, $conversation->contact_id);
        if ($orderSummary !== null) {
            $systemPrompt .= "\n\nUse this order information if the customer asks about their order status, shipping, or delivery:\n".$orderSummary;
        }

        // Load recent conversation turns as context (last 20 messages)
        $history = [];
        $recentMessages = $conversation->messages()
            ->whereIn('type', ['text', 'template'])
            ->where('id', '!=', $inboundMessage->id)
            ->orderBy('sent_at')
            ->take(20)
            ->get();

        foreach ($recentMessages as $m) {
            if (! $m->body) {
                continue;
            }
            $history[] = [
                'role' => $m->direction === 'out' ? 'assistant' : 'user',
                'content' => $m->body,
            ];
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $body]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                ['max_tokens' => 160],
                $bot->id,
                $conversation->id,
            );

            return $response->content;
        } catch (\Throwable $e) {
            if ($throwProviderErrors) {
                throw $e;
            }

            // Fallback
            return $bot->fallback_reply ?? null;
        }
    }

    /**
     * Build a short summary of the contact's recent orders, or null when the
     * Ecommerce module is absent / no store is connected / no orders exist.
     */
    private function orderSummary(int $workspaceId, ?int $contactId): ?string
    {
        $storeModel = 'App\Modules\Ecommerce\Models\EcommerceStore';
        $orderModel = 'App\Modules\Ecommerce\Models\EcommerceOrder';

        if (! $contactId || ! class_exists($storeModel) || ! class_exists($orderModel)) {
            return null;
        }

        $hasStore = $storeModel::where('workspace_id', $workspaceId)
            ->where('status', 'connected')
            ->exists();
        if (! $hasStore) {
            return null;
        }

        $orders = $orderModel::where('workspace_id', $workspaceId)
            ->where('contact_id', $contactId)
            ->latest('placed_at')
            ->take(3)
            ->get();

        if ($orders->isEmpty()) {
            return null;
        }

        return $orders->map(function ($o) {
            $parts = ['Order '.($o->number ?: $o->external_order_id)];
            if ($o->fulfillment_status) {
                $parts[] = 'status: '.$o->fulfillment_status;
            }
            $parts[] = 'total: '.$o->currency.' '.$o->total;
            if ($o->tracking_url) {
                $parts[] = 'tracking: '.$o->tracking_url;
            }
            if ($o->placed_at) {
                $parts[] = 'placed: '.$o->placed_at->toDateString();
            }

            return '- '.implode(', ', $parts);
        })->implode("\n");
    }

    /**
     * API-friendly variant: run the chatbot with a plain text message.
     * Does not require an existing Message/Conversation model.
     *
     * @param  array  $history  Array of {role, content} prior turns (optional)
     * @return array{reply: string|null, tokens_used: int}
     */
    public function runForApi(AiChatbot $bot, string $message, int $workspaceId, array $history = []): array
    {
        // 1. Embed the user query for RAG
        $queryEmbedding = [];
        if ($bot->ai_kb_id) {
            try {
                $embeddings = $this->llmGateway->embed($workspaceId, [$message]);
                $queryEmbedding = $embeddings[0] ?? [];
            } catch (\Throwable) {
            }
        }

        // 2. Retrieve top-k relevant chunks
        $contextChunks = [];
        if ($bot->ai_kb_id && ! empty($queryEmbedding)) {
            $results = $this->embedStore->search($bot->ai_kb_id, $queryEmbedding, $bot->max_context_chunks ?? 5);
            $contextChunks = array_column($results, 'chunk');
        }

        // 3. Build messages array
        $systemPrompt = $this->systemPrompt($bot);
        if (! empty($contextChunks)) {
            $context = implode("\n\n---\n\n", array_map(fn ($c) => $c->content, $contextChunks));
            $systemPrompt .= "\n\nVerified business context (use it when relevant):\n".$context;
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
            [['role' => 'user', 'content' => $message]],
        );

        // 4. Call LLM
        try {
            $response = $this->llmGateway->chat(
                $workspaceId,
                $messages,
                ['max_tokens' => 160],
                $bot->id,
            );

            return [
                'reply' => $response->content,
                'tokens_used' => $response->promptTokens + $response->completionTokens,
            ];
        } catch (\Throwable) {
            return ['reply' => $bot->fallback_reply ?? null, 'tokens_used' => 0];
        }
    }

    /**
     * Keep customer-facing answers brief and natural while still allowing the
     * assistant to help with safe general questions that are not covered by the
     * workspace knowledge base.
     */
    private function systemPrompt(AiChatbot $bot, mixed $contact = null): string
    {
        $prompt = trim((string) ($bot->system_prompt ?: 'You are a helpful customer support assistant.'));
        $name = trim((string) (($contact?->first_name ?? '').' '.($contact?->last_name ?? '')));
        $isAnonymousName = $name === '' || preg_match('/^Customer\s+\d+$/i', $name);

        $prompt .= <<<'PROMPT'

Customer reply rules:
- Reply like a helpful human: direct, warm, and personalized, without repetitive greetings.
- Keep every answer to 1-3 short sentences and at most 60 words. Avoid long introductions and long lists.
- Reply in the customer's language. If they request another language or format, follow that request.
- Treat the verified business context as authoritative for company-specific facts.
- If the context does not contain the answer, still take initiative and help using safe general knowledge. Do not mention a missing knowledge base.
- Never invent company-specific prices, policies, availability, account details, or URLs. When one of those facts is missing, give the most useful short next step or ask one concise clarifying question.
- When suggesting a real URL from the context, order data, or the customer's message, format it as a Markdown link: [short label](https://example.com).
- Include only links that are directly useful to the answer.
PROMPT;

        if (! $isAnonymousName) {
            $prompt .= "\n- The customer's name is {$name}. Use it naturally only when it improves the reply.";
        }

        return $prompt;
    }
}
