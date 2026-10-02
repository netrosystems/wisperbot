<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Exceptions\AiCreditsException;
use App\Modules\AI\Exceptions\AiOutputRejectedException;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmProviderInterface;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Llm\LlmTurn;
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

        $source = AiWorkspaceSetting::modeFor($workspaceId) === 'byok' ? 'byok' : 'managed';
        $reservation = null;
        $providerName = null;
        $model = $opts['model'] ?? null;
        $diagnostics = $opts['diagnostics'] ?? null;
        $responseValidator = $opts['response_validator'] ?? null;
        $retryRejected = $opts['retry_rejected'] ?? null;
        unset($opts['diagnostics'], $opts['response_validator'], $opts['retry_rejected']);

        try {
            $route = $this->openRoute($workspaceId, $feature, $idempotencyKey, $actorId);
            $reservation = $route['reservation'];
            $source = $route['source'];
            if ($reservation->replayedResponse) {
                return $reservation->replayedResponse;
            }
            $resolved = $route['resolved'];
            if ($route['model'] !== null) {
                $opts['model'] = $route['model'];
            }
            $providerName = $resolved['provider'];
            $model = $opts['model'] ?? $model;
            $response = $resolved['client']->chat($messages, $opts);
            $valid = ! is_callable($responseValidator) || $responseValidator($response);
            // A reply cut off by its output budget (often spent on reasoning) is
            // drawn once more with double the budget, under the same reservation.
            if ($response->truncated() && (! $valid || trim($response->content) === '')) {
                $opts['max_tokens'] = min(4096, 2 * (int) ($opts['max_tokens'] ?? 1024));
                $response = $resolved['client']->chat($messages, $opts);
                $valid = ! is_callable($responseValidator) || $responseValidator($response);
            } elseif (! $valid && is_callable($retryRejected) && trim($response->content) !== '' && $retryRejected($response)) {
                // Model output varies between samples. When the caller confirms a
                // rejected reply was a genuine attempt (not a deliberate refusal), one
                // more sample is drawn under the same reservation, so it is charged once.
                $response = $resolved['client']->chat($messages, $opts);
                $valid = $responseValidator($response);
            }
            if (trim($response->content) === '') {
                throw new AiOutputRejectedException('The AI provider returned an empty response. Please check its model and output budget.');
            }
            if (! $valid) {
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

    /**
     * One customer-facing answer that may take several model calls (Smart Bot
     * 2.0): one credit reservation, settled once by LlmTurn::finish() or
     * returned by LlmTurn::abort(). A key that already finished replays its
     * stored answer instead of calling the provider again.
     */
    public function beginTurn(int $workspaceId, string $feature, string $idempotencyKey, ?int $chatbotId = null, ?int $conversationId = null): LlmTurn
    {
        $this->credits->creditsFor($feature);
        $route = $this->openRoute($workspaceId, $feature, $idempotencyKey, auth()->id() ?: null);
        $reservation = $route['reservation'];
        if ($reservation->replayedResponse) {
            return new LlmTurn($this, $this->credits, null, null, null, null, $workspaceId, $feature, $chatbotId, $conversationId, LlmTurn::storedAnswer($reservation->replayedResponse));
        }

        return new LlmTurn($this, $this->credits, $reservation->ledger, $route['resolved']['client'], $route['resolved']['provider'], $route['model'], $workspaceId, $feature, $chatbotId, $conversationId);
    }

    /**
     * One call of a turn: the provider call and the double-budget retry for a
     * reply cut off before it was usable. No ledger of its own.
     *
     * @internal Used by LlmTurn.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     */
    public function runStep(LlmProviderInterface $client, array $messages, array $opts): LlmResponse
    {
        $response = $client->chat($messages, $opts);
        $json = ($opts['json_object'] ?? false) === true || isset($opts['json_schema']);
        $unusable = trim($response->content) === '' || ($json && ! is_array(json_decode(trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($response->content))), true)));
        if ($response->truncated() && $unusable) {
            $opts['max_tokens'] = min(4096, 2 * (int) ($opts['max_tokens'] ?? 1024));
            $response = $client->chat($messages, $opts);
        }

        return $response;
    }

    /**
     * Who pays and who answers: opens the credit reservation (managed, BYOK,
     * or managed with BYOK fallback) and resolves the provider. A reservation
     * that already succeeded carries its replayed response and no provider.
     * A reservation opened here is refunded if the provider cannot be resolved.
     *
     * @return array{reservation:AiCreditReservation,resolved:array{provider:string,client:LlmProviderInterface,model?:string}|null,model:?string,source:string}
     */
    private function openRoute(int $workspaceId, string $feature, string $idempotencyKey, ?int $actorId): array
    {
        $mode = AiWorkspaceSetting::modeFor($workspaceId);
        $source = $mode === 'byok' ? 'byok' : 'managed';
        $reservation = null;

        try {
            if ($source === 'managed') {
                try {
                    $reservation = $this->credits->reserve($workspaceId, $feature, $idempotencyKey, $actorId);
                    if ($reservation->replayedResponse) {
                        return ['reservation' => $reservation, 'resolved' => null, 'model' => null, 'source' => $source];
                    }
                    $resolved = LlmManager::managedChat($feature);

                    return ['reservation' => $reservation, 'resolved' => $resolved, 'model' => $resolved['model'], 'source' => $source];
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
                        return ['reservation' => $reservation, 'resolved' => null, 'model' => null, 'source' => $source];
                    }

                    return ['reservation' => $reservation, 'resolved' => LlmManager::forWorkspaceByok($workspaceId, requireSuccessfulTest: true), 'model' => null, 'source' => $source];
                }
            }
            $reservation = $this->credits->recordByok($workspaceId, $feature, $idempotencyKey, $actorId);
            if ($reservation->replayedResponse) {
                return ['reservation' => $reservation, 'resolved' => null, 'model' => null, 'source' => $source];
            }

            return ['reservation' => $reservation, 'resolved' => LlmManager::forWorkspaceByok($workspaceId), 'model' => null, 'source' => $source];
        } catch (\Throwable $e) {
            if ($reservation) {
                $this->credits->refund($reservation->ledger, $e instanceof AiCreditsException ? $e->errorCode : 'provider_failed');
            }

            throw $e;
        }
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
