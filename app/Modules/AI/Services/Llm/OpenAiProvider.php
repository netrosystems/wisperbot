<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider implements LlmProviderInterface
{
    private const BASE = 'https://api.openai.com/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gpt-4o-mini',
        private readonly string $embedModel = 'text-embedding-3-small',
        private readonly ?string $organization = null,
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $model = $opts['model'] ?? $this->chatModel;
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        if (($effort = $this->reasoningEffort($model)) !== null) {
            // Reasoning tokens come out of the same budget as the reply, so a
            // short reply budget is spent thinking unless the effort is kept low.
            $payload['max_completion_tokens'] = $opts['max_tokens'] ?? 1024;
            $payload['reasoning_effort'] = $opts['reasoning_effort'] ?? $effort;
        } else {
            $payload['max_tokens'] = $opts['max_tokens'] ?? 1024;
            $payload['temperature'] = $opts['temperature'] ?? 0.7;
        }
        if (is_array($opts['json_schema'] ?? null)) {
            // Structured outputs guarantee every required key, not just valid JSON.
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => $opts['json_schema']];
        } elseif (($opts['json_object'] ?? false) === true) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $send = fn (array $body) => Http::withHeaders($headers)->retry(2, 500)->timeout(60)->post(self::BASE.'/chat/completions', $body);
        try {
            $resp = $send($payload);
        } catch (RequestException $e) {
            if ($e->response->status() !== 400) {
                throw $e;
            }
            // A model that does not accept this reasoning effort still answers
            // with its default one.
            if (isset($payload['reasoning_effort']) && str_contains($e->response->body(), 'reasoning_effort')) {
                unset($payload['reasoning_effort']);
                $resp = $send($payload);
            } elseif (($payload['response_format']['type'] ?? null) === 'json_schema') {
                // Some models (for example a client's own older model) reject
                // structured outputs; plain JSON mode keeps them working.
                $payload['response_format'] = ['type' => 'json_object'];
                $resp = $send($payload);
            } else {
                throw $e;
            }
        }

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);

        return new LlmResponse(
            content: $json['choices'][0]['message']['content'] ?? '',
            promptTokens: $json['usage']['prompt_tokens'] ?? 0,
            completionTokens: $json['usage']['completion_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
            finishReason: LlmResponse::normalizeFinishReason($json['choices'][0]['finish_reason'] ?? null),
        );
    }

    /**
     * The lowest reasoning effort each reasoning model family accepts, or null
     * for a model without reasoning (which takes max_tokens and temperature).
     */
    private function reasoningEffort(string $model): ?string
    {
        return match (true) {
            (bool) preg_match('/^gpt-5(-mini|-nano)?(-\d{4}-\d{2}-\d{2})?$/', $model) => 'minimal',
            str_starts_with($model, 'gpt-5') => 'none',
            (bool) preg_match('/^o\d/', $model) => 'low',
            default => null,
        };
    }

    public function embed(array $texts): array
    {
        $headers = ['Authorization' => 'Bearer '.$this->apiKey];
        if ($this->organization) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $resp = Http::withHeaders($headers)->retry(2, 500)->timeout(30)->post(self::BASE.'/embeddings', [
            'model' => $this->embedModel,
            'input' => $texts,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenAI embed failed: '.$resp->body());
        }

        return array_column($resp->json()['data'] ?? [], 'embedding');
    }
}
