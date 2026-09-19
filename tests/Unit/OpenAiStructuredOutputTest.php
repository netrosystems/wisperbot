<?php

namespace Tests\Unit;

use App\Modules\AI\Services\Llm\OpenAiProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiStructuredOutputTest extends TestCase
{
    private const SCHEMA = [
        'name' => 'reply',
        'strict' => true,
        'schema' => ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
    ];

    public function test_schema_requests_use_structured_outputs(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->completion(), 200)]);

        (new OpenAiProvider('sk-test'))->chat([['role' => 'user', 'content' => 'Hi']], ['json_object' => true, 'json_schema' => self::SCHEMA]);

        Http::assertSent(fn (Request $request): bool => $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['name'] === 'reply');
    }

    public function test_models_without_structured_outputs_fall_back_to_json_mode(): void
    {
        $formats = [];
        Http::fake(['api.openai.com/*' => function (Request $request) use (&$formats) {
            $formats[] = $request['response_format']['type'];

            return $request['response_format']['type'] === 'json_schema'
                ? Http::response(['error' => ['message' => "Invalid parameter: 'response_format' of type 'json_schema' is not supported with this model."]], 400)
                : Http::response($this->completion(), 200);
        }]);

        $response = (new OpenAiProvider('sk-test', 'gpt-4-turbo'))->chat([['role' => 'user', 'content' => 'Hi']], ['json_object' => true, 'json_schema' => self::SCHEMA]);

        $this->assertSame('{"reply":"Hello"}', $response->content);
        $this->assertSame('json_object', end($formats));
        $this->assertContains('json_schema', $formats);
    }

    /** @return array<string,mixed> */
    private function completion(): array
    {
        return [
            'choices' => [['message' => ['content' => '{"reply":"Hello"}']]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
            'model' => 'gpt-4o-mini',
        ];
    }
}
