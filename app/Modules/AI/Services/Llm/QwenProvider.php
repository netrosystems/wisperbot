<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class QwenProvider implements LlmProviderInterface
{
    public const MODEL = 'qwen3.7-flash';

    public const REGIONS = [
        'ap-southeast-1',
        'us-east-1',
        'eu-central-1',
        'ap-northeast-1',
        'cn-beijing',
        'cn-hongkong',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly string $region,
        private readonly string $workspaceId,
        private readonly string $chatModel = self::MODEL,
    ) {
        if (! in_array($this->region, self::REGIONS, true)) {
            throw new \InvalidArgumentException('Unsupported Alibaba Cloud Model Studio region.');
        }

        if (! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{1,126}[A-Za-z0-9])?$/', $this->workspaceId)) {
            throw new \InvalidArgumentException('Invalid Alibaba Cloud Model Studio Workspace ID.');
        }
    }

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $response = Http::withToken($this->apiKey)
            ->retry(2, 500)
            ->timeout(60)
            ->post($this->baseUrl().'/chat/completions', [
                'model' => $opts['model'] ?? $this->chatModel,
                'messages' => $messages,
                'max_tokens' => $opts['max_tokens'] ?? 1024,
                'temperature' => $opts['temperature'] ?? 0.7,
                'enable_thinking' => false,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Alibaba Qwen request failed with HTTP '.$response->status().'.');
        }

        $json = $response->json();
        $content = $json['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new \RuntimeException('Alibaba Qwen returned an empty or malformed response.');
        }

        return new LlmResponse(
            content: $content,
            promptTokens: (int) ($json['usage']['prompt_tokens'] ?? 0),
            completionTokens: (int) ($json['usage']['completion_tokens'] ?? 0),
            model: (string) ($json['model'] ?? $this->chatModel),
            latencyMs: (int) ((microtime(true) - $start) * 1000),
        );
    }

    public function embed(array $texts): array
    {
        throw new \RuntimeException('Qwen 3.7 Flash does not provide the embedding service used by WisperBot.');
    }

    public function baseUrl(): string
    {
        return sprintf(
            'https://%s.%s.maas.aliyuncs.com/compatible-mode/v1',
            $this->workspaceId,
            $this->region,
        );
    }
}
