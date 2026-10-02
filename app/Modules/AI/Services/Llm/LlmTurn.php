<?php

namespace App\Modules\AI\Services\Llm;

use App\Modules\AI\Models\AiCreditLedger;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\ChatReplyOptions;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

/**
 * One customer-facing answer, however many model calls it takes (Smart Bot
 * 2.0, Phase 1.1; after Cerqle's LlmTurn).
 *
 * A turn holds a single credit reservation. Each call it makes (planning,
 * the answer, a check, a regenerated answer) is a step: logged as its own
 * AiRun, never charged on its own. The turn then either finishes (one charge,
 * with the final answer stored for replay) or aborts (the reservation is
 * returned: a client pays for answers, not attempts).
 *
 * A retried job whose turn already finished receives the stored answer
 * (`replay`) instead of calling the provider again.
 */
final class LlmTurn
{
    public const STORED_KIND = 'smart_bot_turn';

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private ?string $lastModel = null;

    private int $latencyMs = 0;

    private bool $closed = false;

    /**
     * @param  array<string,mixed>|null  $replay  The stored final answer of a turn that already finished.
     */
    public function __construct(
        private readonly LlmGateway $gateway,
        private readonly AiCreditService $credits,
        private readonly ?AiCreditLedger $ledger,
        private readonly ?LlmProviderInterface $client,
        private readonly ?string $providerName,
        private readonly ?string $model,
        private readonly int $workspaceId,
        private readonly string $feature,
        private readonly ?int $chatbotId,
        private readonly ?int $conversationId,
        public readonly ?array $replay = null,
    ) {}

    /**
     * One model call inside this turn.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @param  array<string,mixed>  $opts
     */
    public function step(array $messages, array $opts, string $label = 'generate'): LlmResponse
    {
        if ($this->replay !== null || $this->closed || ! $this->client) {
            throw new \LogicException('This answer is already finished.');
        }
        if ($this->model !== null) {
            $opts['model'] = $this->model;
        }

        try {
            $response = $this->gateway->runStep($this->client, $messages, $opts);
        } catch (\Throwable $error) {
            $this->record($label, null, 'error');
            Log::error('llm.turn_step_failed', [
                'workspace_id' => $this->workspaceId,
                'chatbot_id' => $this->chatbotId,
                'step' => $label,
                'exception' => $error::class,
            ]);

            throw $error;
        }

        $this->promptTokens += $response->promptTokens;
        $this->completionTokens += $response->completionTokens;
        $this->latencyMs += $response->latencyMs;
        $this->lastModel = $response->model;
        $this->record($label, $response, 'ok');

        return $response;
    }

    /**
     * The answer is final: charge once and keep it for a retried job.
     *
     * @param  array<string,mixed>  $answer
     */
    public function finish(array $answer): void
    {
        if ($this->replay !== null || $this->closed) {
            return;
        }
        $this->closed = true;
        if (! $this->ledger) {
            return;
        }
        $stored = new LlmResponse(
            (string) json_encode(['kind' => self::STORED_KIND, 'answer' => $answer], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $this->promptTokens,
            $this->completionTokens,
            (string) $this->lastModel,
            $this->latencyMs,
            'stop',
        );
        $this->credits->succeed($this->ledger, $stored, (string) $this->providerName);
        UsageMeter::track($this->workspaceId, 'ai_tokens', $this->tokensUsed());
    }

    /** No usable answer, or one that is not charged (a question back, an offer of a person). */
    public function abort(string $reason): void
    {
        if ($this->replay !== null || $this->closed) {
            return;
        }
        $this->closed = true;
        if ($this->ledger) {
            $this->credits->refund($this->ledger, $reason);
        }
        if ($this->tokensUsed() > 0) {
            UsageMeter::track($this->workspaceId, 'ai_tokens', $this->tokensUsed());
        }
    }

    public function tokensUsed(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function model(): ?string
    {
        return $this->lastModel;
    }

    public function latencyMs(): int
    {
        return $this->latencyMs;
    }

    /**
     * The answer a finished turn stored. A key first used by a single call
     * (before the bot moved to engine v2) stored the model's reply itself;
     * it is read the way v1 reads a reply, so it is never charged twice.
     *
     * @return array<string,mixed>
     */
    public static function storedAnswer(LlmResponse $stored): array
    {
        $decoded = json_decode($stored->content, true);
        if (is_array($decoded) && ($decoded['kind'] ?? null) === self::STORED_KIND && is_array($decoded['answer'] ?? null)) {
            return $decoded['answer'];
        }
        $parsed = app(ChatReplyOptions::class)->parse($stored->content) ?? ['reply' => '', 'display_body' => '', 'quick_replies' => []];

        return $parsed + [
            'tokens_used' => 0,
            'resources' => [],
            'answer_origin' => 'knowledge_base',
            'response_mode' => 'answer',
            'citations' => [],
        ];
    }

    private function record(string $label, ?LlmResponse $response, string $status): void
    {
        AiRun::create([
            'chatbot_id' => $this->chatbotId,
            'conversation_id' => $this->conversationId,
            'prompt_tokens' => $response ? $response->promptTokens : 0,
            'completion_tokens' => $response ? $response->completionTokens : 0,
            'cost_cents' => 0,
            'latency_ms' => $response ? $response->latencyMs : 0,
            'model' => $response !== null ? $response->model : $this->model,
            'status' => $status,
            'metadata_json' => array_filter([
                'engine' => 'v2',
                'step' => $label,
                'finish_reason' => $response?->finishReason,
                'feature' => $this->feature,
            ]),
        ]);

        if ($response) {
            Log::channel('json')->info('llm.chat', [
                'workspace_id' => $this->workspaceId,
                'chatbot_id' => $this->chatbotId,
                'step' => $label,
                'model' => $response->model,
                'prompt_tokens' => $response->promptTokens,
                'completion_tokens' => $response->completionTokens,
                'finish_reason' => $response->finishReason,
                'latency_ms' => $response->latencyMs,
                'feature' => $this->feature,
            ]);
        }
    }
}
