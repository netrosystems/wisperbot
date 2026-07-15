<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

class LlmGateway
{
    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
    ): LlmResponse {
        $model = $opts['model'] ?? null;
        try {
            $provider = LlmManager::forWorkspace($workspaceId);
            $response = $provider->chat($messages, $opts);
        } catch (\Throwable $e) {
            AiRun::create([
                'chatbot_id' => $chatbotId,
                'conversation_id' => $conversationId,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_cents' => 0,
                'latency_ms' => 0,
                'model' => $model,
                'status' => 'error',
            ]);
            Log::error('llm.chat_failed', [
                'workspace_id' => $workspaceId,
                'chatbot_id' => $chatbotId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $totalTokens = $response->promptTokens + $response->completionTokens;
        UsageMeter::track($workspaceId, 'ai_tokens', $totalTokens);

        AiRun::create([
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost_cents' => 0,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
        ]);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }

    public function embed(int $workspaceId, array $texts): array
    {
        // Use embed-specific provider (skips Anthropic which has no embedding support)
        try {
            $provider = LlmManager::forWorkspaceEmbed($workspaceId);
            $embeddings = $provider->embed($texts);
        } catch (\Throwable $e) {
            AiRun::create([
                'chatbot_id' => null,
                'conversation_id' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_cents' => 0,
                'latency_ms' => 0,
                'model' => 'embed',
                'status' => 'error',
            ]);
            Log::error('llm.embed_failed', [
                'workspace_id' => $workspaceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));
        UsageMeter::track($workspaceId, 'ai_tokens', $tokenEstimate);

        AiRun::create([
            'chatbot_id' => null,
            'conversation_id' => null,
            'prompt_tokens' => $tokenEstimate,
            'completion_tokens' => 0,
            'cost_cents' => 0,
            'latency_ms' => 0,
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
    }
}
