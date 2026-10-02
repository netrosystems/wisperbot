<?php

namespace Tests\Unit;

use App\Modules\AI\Services\Llm\AnthropicProvider;
use App\Modules\AI\Services\Llm\GeminiProvider;
use App\Modules\AI\Services\Llm\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The main instructions must reach every provider intact, and a short reply
 * budget must not be spent on reasoning before the answer is written.
 */
class LlmProviderInstructionsTest extends TestCase
{
    private const MESSAGES = [
        ['role' => 'system', 'content' => 'Main rules and knowledge.'],
        ['role' => 'system', 'content' => 'Earlier conversation summary.'],
        ['role' => 'user', 'content' => 'hello'],
    ];

    public function test_anthropic_keeps_every_system_message_and_reports_a_cut_off(): void
    {
        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Hel'], ['type' => 'text', 'text' => 'lo']],
            'stop_reason' => 'max_tokens',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            'model' => 'claude-haiku-4-5-20251001',
        ])]);

        $response = (new AnthropicProvider('key'))->chat(self::MESSAGES);

        $this->assertSame('Hello', $response->content);
        $this->assertSame('length', $response->finishReason);
        Http::assertSent(fn (Request $request) => $request['system'] === "Main rules and knowledge.\n\nEarlier conversation summary."
            && $request['messages'] === [['role' => 'user', 'content' => 'hello']]);
    }

    public function test_gemini_keeps_every_system_message_limits_thinking_and_skips_thoughts(): void
    {
        Http::fake(['*:generateContent' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'planning…', 'thought' => true], ['text' => 'Hello']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => [],
        ])]);

        $response = (new GeminiProvider('key'))->chat(self::MESSAGES);

        $this->assertSame('Hello', $response->content);
        $this->assertSame('stop', $response->finishReason);
        Http::assertSent(fn (Request $request) => $request['systemInstruction']['parts'] === [['text' => 'Main rules and knowledge.'], ['text' => 'Earlier conversation summary.']]
            && $request['generationConfig']['thinkingConfig'] === ['thinkingLevel' => 'low']);
    }

    public function test_gemini_retries_without_a_thinking_setting_the_model_rejects(): void
    {
        Http::fake(['*:generateContent' => Http::sequence()
            // The HTTP client tries each request three times before giving up.
            ->push(['error' => ['message' => 'Thinking level is not supported for this model.']], 400)
            ->push(['error' => ['message' => 'Thinking level is not supported for this model.']], 400)
            ->push(['error' => ['message' => 'Thinking level is not supported for this model.']], 400)
            ->push(['candidates' => [['content' => ['parts' => [['text' => 'ok']]], 'finishReason' => 'STOP']], 'usageMetadata' => []])]);

        $response = (new GeminiProvider('key'))->chat(self::MESSAGES);

        $this->assertSame('ok', $response->content);
        $this->assertFalse(isset(collect(Http::recorded())->last()[0]['generationConfig']['thinkingConfig']));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function reasoningModels(): array
    {
        return [
            'gpt-5 mini' => ['gpt-5-mini', 'minimal'],
            'gpt-5 dated' => ['gpt-5-nano-2025-08-07', 'minimal'],
            'gpt-5.1' => ['gpt-5.1', 'none'],
            'o-series' => ['o4-mini', 'low'],
            'no reasoning' => ['gpt-4o-mini', null],
        ];
    }

    #[DataProvider('reasoningModels')]
    public function test_openai_keeps_reasoning_low_for_reasoning_models(string $model, ?string $effort): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
            'model' => $model,
        ])]);

        (new OpenAiProvider('key', $model))->chat([['role' => 'user', 'content' => 'hello']], ['max_tokens' => 600]);

        Http::assertSent(function (Request $request) use ($effort): bool {
            if ($effort === null) {
                return $request['max_tokens'] === 600 && ! isset($request['reasoning_effort']);
            }

            return $request['max_completion_tokens'] === 600 && $request['reasoning_effort'] === $effort && ! isset($request['temperature']);
        });
    }

    public function test_openai_retries_without_a_reasoning_effort_the_model_rejects(): void
    {
        Http::fake(['api.openai.com/v1/chat/completions' => Http::sequence()
            // The HTTP client tries each request three times before giving up.
            ->push(['error' => ['message' => "Unsupported value: 'reasoning_effort' does not support 'none' with this model."]], 400)
            ->push(['error' => ['message' => "Unsupported value: 'reasoning_effort' does not support 'none' with this model."]], 400)
            ->push(['error' => ['message' => "Unsupported value: 'reasoning_effort' does not support 'none' with this model."]], 400)
            ->push(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => [], 'model' => 'gpt-5-chat-latest'])]);

        $response = (new OpenAiProvider('key', 'gpt-5-chat-latest'))->chat([['role' => 'user', 'content' => 'hello']]);

        $this->assertSame('ok', $response->content);
        $requests = collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->all();
        $this->assertSame('none', $requests[0]['reasoning_effort']);
        $this->assertArrayNotHasKey('reasoning_effort', $requests[array_key_last($requests)]);
    }
}
