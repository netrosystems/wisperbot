<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class AnthropicProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.anthropic.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'claude-haiku-4-5-20251001',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);

        // Anthropic takes one system prompt separate from the turns. Every system
        // message is kept, in order: a later one (such as a history summary)
        // must add to the main instructions, never replace them.
        $systemParts = [];
        $turns = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = (string) $m['content'];
            } else {
                $turns[] = ['role' => $m['role'], 'content' => $m['content']];
            }
        }
        $system = $systemParts === [] ? null : implode("\n\n", $systemParts);

        $body = [
            'model' => $opts['model'] ?? $this->chatModel,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'messages' => $turns,
        ];
        if ($system !== null) {
            $body['system'] = $system;
        }

        $resp = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])->retry(2, 500)->timeout(60)->post(self::BASE.'/messages', $body);

        if (! $resp->successful()) {
            throw new \RuntimeException('Anthropic chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $content = implode('', array_map(
            fn (array $block): string => ($block['type'] ?? 'text') === 'text' ? (string) ($block['text'] ?? '') : '',
            array_filter($json['content'] ?? [], 'is_array'),
        ));

        return new LlmResponse(
            content: $content,
            promptTokens: $json['usage']['input_tokens'] ?? 0,
            completionTokens: $json['usage']['output_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            finishReason: LlmResponse::normalizeFinishReason($json['stop_reason'] ?? null),
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException('Anthropic does not support embeddings natively. Use OpenAI or Gemini.');
    }
}
