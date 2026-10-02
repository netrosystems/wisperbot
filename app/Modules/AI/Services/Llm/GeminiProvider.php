<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class GeminiProvider implements LlmProviderInterface
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'gemini-3.5-flash',
        private readonly string $embedModel = 'gemini-embedding-2',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $model = $opts['model'] ?? $this->chatModel;

        // Every system message becomes part of the system instruction, in order:
        // a later one (such as a history summary) must add to the main
        // instructions, never replace them. Remaining turns map to user/model.
        $systemParts = [];
        $contents = [];
        foreach ($messages as $m) {
            if ($m['role'] === 'system') {
                $systemParts[] = ['text' => (string) $m['content']];
            } else {
                $contents[] = [
                    'role' => $m['role'] === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $m['content']]],
                ];
            }
        }

        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $opts['max_tokens'] ?? 1024],
        ];
        if (($opts['json_object'] ?? false) === true) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }
        if ($systemParts !== []) {
            $body['systemInstruction'] = ['parts' => $systemParts];
        }
        // Thinking tokens come out of the same output budget, so a short reply
        // budget could be spent before any answer is written.
        if (($thinking = $this->thinkingConfig($model)) !== null) {
            $body['generationConfig']['thinkingConfig'] = $thinking;
        }

        $send = fn (array $payload) => Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->retry(2, 500, throw: false)->timeout(60)
            ->post(self::BASE."/models/{$model}:generateContent", $payload);
        $resp = $send($body);
        if ($resp->status() === 400 && isset($body['generationConfig']['thinkingConfig'])) {
            // A model that does not accept this thinking setting still answers without it.
            unset($body['generationConfig']['thinkingConfig']);
            $resp = $send($body);
        }

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $content = implode('', array_map(
            fn (array $part): string => ($part['thought'] ?? false) === true ? '' : (string) ($part['text'] ?? ''),
            array_filter($json['candidates'][0]['content']['parts'] ?? [], 'is_array'),
        ));
        $meta = $json['usageMetadata'] ?? [];

        return new LlmResponse(
            content: $content,
            promptTokens: $meta['promptTokenCount'] ?? 0,
            completionTokens: $meta['candidatesTokenCount'] ?? 0,
            model: $model,
            latencyMs: $latency,
            finishReason: LlmResponse::normalizeFinishReason($json['candidates'][0]['finishReason'] ?? null),
        );
    }

    /** @return array<string,int|string>|null */
    private function thinkingConfig(string $model): ?array
    {
        return match (true) {
            str_starts_with($model, 'gemini-2.5-flash') => ['thinkingBudget' => 0],
            str_starts_with($model, 'gemini-3') => ['thinkingLevel' => 'low'],
            default => null,
        };
    }

    public function embed(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        $requests = array_map(fn ($text) => [
            'model' => 'models/'.$this->embedModel,
            'content' => ['parts' => [['text' => $text]]],
        ], $texts);

        $resp = Http::withHeaders(['x-goog-api-key' => $this->apiKey])
            ->retry(2, 500)->timeout(60)->post(
                self::BASE."/models/{$this->embedModel}:batchEmbedContents",
                ['requests' => $requests]
            );

        if (! $resp->successful()) {
            throw new \RuntimeException('Gemini batch embed failed: '.$resp->body());
        }

        return array_map(
            fn ($e) => $e['values'] ?? [],
            $resp->json('embeddings', [])
        );
    }
}
