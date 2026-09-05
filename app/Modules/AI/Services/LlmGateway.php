<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Exceptions\AiOutputRejectedException;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LlmGateway
{
    public function __construct(private readonly AiCreditService $credits) {}

    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
    ): LlmResponse {
        $feature = (string) ($opts['feature'] ?? '');
        $idempotencyKey = (string) ($opts['idempotency_key'] ?? request()?->header('Idempotency-Key') ?? 'request:'.(string) Str::uuid());
        $actorId = isset($opts['actor_id']) ? (int) $opts['actor_id'] : (auth()->id() ?: null);
        unset($opts['feature'], $opts['idempotency_key'], $opts['actor_id']);
        // Unknown or omitted feature keys fail closed before any provider request.
        $this->credits->creditsFor($feature);

        $mode = AiWorkspaceSetting::modeFor($workspaceId);
        $source = $mode === 'byok' ? 'byok' : 'managed';
        $reservation = null;
        $providerName = null;
        $model = $opts['model'] ?? null;
        $diagnostics = $opts['diagnostics'] ?? null;
        $responseValidator = $opts['response_validator'] ?? null;
        unset($opts['diagnostics'], $opts['response_validator']);

        try {
            if ($source === 'managed') {
                try {
                    $reservation = $this->credits->reserve($workspaceId, $feature, $idempotencyKey, $actorId);
                    if ($reservation->replayedResponse) {
                        return $reservation->replayedResponse;
                    }
                    $resolved = LlmManager::managedChat($feature);
                    $opts['model'] = $resolved['model'];
                } catch (\Throwable $managedFailure) {
                    if ($mode !== 'auto_fallback') {
                        throw $managedFailure;
                    }
                    if ($reservation) {
                        $this->credits->refund($reservation->ledger, 'managed_provider_unavailable');
                    }
                    $source = 'byok';
                    $reservation = $this->credits->recordByok($workspaceId, $feature, $idempotencyKey.':fallback', $actorId);
                    if ($reservation->replayedResponse) {
                        return $reservation->replayedResponse;
                    }
                    $resolved = LlmManager::forWorkspaceByok($workspaceId, requireSuccessfulTest: true);
                }
            } else {
                $reservation = $this->credits->recordByok($workspaceId, $feature, $idempotencyKey, $actorId);
                if ($reservation->replayedResponse) {
                    return $reservation->replayedResponse;
                }
                $resolved = LlmManager::forWorkspaceByok($workspaceId);
            }
            $providerName = $resolved['provider'];
            $model = $opts['model'] ?? $model;
            $response = $resolved['client']->chat($messages, $opts);
            if (trim($response->content) === '') {
                throw new AiOutputRejectedException('The AI provider returned an empty response. Please check its model and output budget.');
            }
            if (is_callable($responseValidator) && ! $responseValidator($response)) {
                throw new AiOutputRejectedException('The AI provider returned an unusable response.');
            }
            $this->credits->succeed($reservation->ledger, $response, $providerName);
        } catch (\Throwable $e) {
            if ($reservation) {
                $this->credits->refund($reservation->ledger, $e instanceof AiCreditsException ? $e->errorCode : 'provider_failed');
            }
            AiRun::create([
                'chatbot_id' => $chatbotId,
                'conversation_id' => $conversationId,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_cents' => 0,
                'latency_ms' => 0,
                'model' => $model,
                'status' => 'error',
                'metadata_json' => $diagnostics,
            ]);
            Log::error('llm.chat_failed', [
                'workspace_id' => $workspaceId,
                'chatbot_id' => $chatbotId,
                'exception' => $e::class,
                'error_code' => $e instanceof AiCreditsException ? $e->errorCode : 'provider_failed',
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
            'metadata_json' => $diagnostics,
        ]);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
            'provider_source' => $source,
            'feature' => $feature,
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
                'exception' => $e::class,
            ]);

            throw $e;
        }
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));

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
