<?php

namespace App\Modules\AI\Services\Llm;

use App\Models\Workspace;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\CredentialResolver;

class LlmManager
{
    /** Providers that support embeddings natively. */
    private const EMBED_CAPABLE = ['openai', 'gemini'];

    /** Providers clients may configure for BYOK and automatic fallback. */
    private const WORKSPACE_PROVIDERS = ['openai', 'anthropic', 'gemini'];

    /** Resolve a provider for chat completions (all providers supported). */
    public static function forWorkspace(int $workspaceId): LlmProviderInterface
    {
        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->whereIn('provider', self::WORKSPACE_PROVIDERS)
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'anthropic' THEN 2 WHEN 'gemini' THEN 3 ELSE 4 END")
            ->first();

        if ($config && ! empty($config->credentials['api_key'] ?? '')) {
            return static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::WORKSPACE_PROVIDERS as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray());
            }
        }

        // DeepSeek is a system-level integration only. Workspace credentials
        // and client fallback selection must never resolve it.
        $systemDeepSeek = CredentialResolver::system()->llm('deepseek');
        if ($systemDeepSeek) {
            return static::build('deepseek', $systemDeepSeek->toArray());
        }

        throw new \RuntimeException('No AI provider configured for workspace '.$workspaceId);
    }

    /** Resolve only a customer-owned workspace provider; never fall back to platform credentials. */
    public static function forWorkspaceByok(int $workspaceId, bool $requireSuccessfulTest = false): array
    {
        $query = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->whereIn('provider', self::WORKSPACE_PROVIDERS)
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'anthropic' THEN 2 WHEN 'gemini' THEN 3 ELSE 4 END");
        if ($requireSuccessfulTest) {
            $query->whereNotNull('last_test_succeeded_at');
        }
        $config = $query->get()->first(fn (AiProviderConfig $row) => ! empty($row->credentials['api_key'] ?? ''));
        if (! $config) {
            throw new \RuntimeException($requireSuccessfulTest
                ? 'Reconnect and successfully test your fallback AI provider.'
                : 'No customer AI provider is configured for this workspace.');
        }

        return [
            'provider' => $config->provider,
            'client' => static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]),
        ];
    }

    /** Resolve only the platform-managed provider; never inspect workspace credentials. */
    public static function managedChat(string $feature): array
    {
        $selected = IntegrationConfig::query()
            ->whereIn('provider', IntegrationConfig::LLM_PROVIDERS)
            ->where('mode', 'live')
            ->where('enabled', true)
            ->where('is_default', true)
            ->latest('updated_at')
            ->first();
        $provider = $selected
            ? str($selected->provider)->after('llm_')->beforeLast('_default')->toString()
            : (string) config('ai_credits.managed.provider', 'openai');
        $creds = CredentialResolver::system()->llm($provider);
        if (! $creds || empty($creds->toArray()['api_key'] ?? '')) {
            throw new \RuntimeException('WisperBot managed AI is temporarily unavailable.');
        }
        $complex = in_array($feature, ['email_generate', 'social_post', 'workflow_generate', 'social_plan'], true);
        $model = match ($provider) {
            'qwen' => QwenProvider::MODEL,
            'deepseek' => 'deepseek-chat',
            'anthropic' => 'claude-haiku-4-5-20251001',
            'gemini' => 'gemini-3.5-flash',
            default => (string) config($complex ? 'ai_credits.managed.complex_model' : 'ai_credits.managed.routine_model'),
        };

        return [
            'provider' => $provider,
            'model' => $model,
            'client' => static::build($provider, $creds->toArray(), ['chat' => $model]),
        ];
    }

    /**
     * Resolve a provider for embeddings only.
     * Anthropic, DeepSeek, and Qwen 3.7 Flash do not support embeddings, so they are skipped.
     * A customer OpenAI/Gemini key takes precedence, then managed infrastructure.
     */
    public static function forWorkspaceEmbed(int $workspaceId): LlmProviderInterface
    {
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('enabled', true)
            ->orderByRaw("CASE provider WHEN 'openai' THEN 1 WHEN 'gemini' THEN 2 ELSE 3 END")
            ->get();

        foreach ($configs as $config) {
            if (! in_array($config->provider, self::EMBED_CAPABLE, true)) {
                continue;
            }
            if (empty($config->credentials['api_key'] ?? '')) {
                continue;
            }

            return static::build($config->provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);
        }

        $workspace = app(Workspace::class)->find($workspaceId);
        foreach (self::EMBED_CAPABLE as $provider) {
            $creds = CredentialResolver::for($workspace)->llm($provider);
            if ($creds) {
                return static::build($provider, $creds->toArray(), [
                    'embed' => $provider === 'openai' ? (string) config('ai_credits.managed.embedding_model') : null,
                ]);
            }
        }

        throw new \RuntimeException(
            'No embedding-capable AI provider (OpenAI or Gemini) configured for workspace '.$workspaceId.
            '. Anthropic, DeepSeek, and Qwen 3.7 Flash do not support embeddings.'
        );
    }

    public static function build(string $provider, array $creds, array $models = []): LlmProviderInterface
    {
        $chatModel = static::currentChatModel($provider, $models['chat'] ?? null);
        $embedModel = static::currentEmbedModel($provider, $models['embed'] ?? null);

        return match ($provider) {
            'openai' => new OpenAiProvider(
                $creds['api_key'] ?? '',
                $chatModel,
                $embedModel,
                $creds['organization_id'] ?? null,
            ),
            'anthropic' => new AnthropicProvider($creds['api_key'] ?? '', $chatModel),
            'gemini' => new GeminiProvider($creds['api_key'] ?? '', $chatModel, $embedModel),
            'deepseek' => new DeepSeekProvider($creds['api_key'] ?? '', $chatModel),
            'qwen' => new QwenProvider(
                $creds['api_key'] ?? '',
                $creds['region'] ?? '',
                $creds['workspace_id'] ?? '',
                $chatModel,
            ),
            default => throw new \RuntimeException("Unknown LLM provider: {$provider}"),
        };
    }

    private static function currentChatModel(string $provider, ?string $model): string
    {
        $model = trim((string) $model);

        return match ($provider) {
            'openai' => $model !== '' ? $model : 'gpt-4o-mini',
            'anthropic' => $model === '' || str_starts_with($model, 'claude-3-')
                ? 'claude-haiku-4-5-20251001'
                : $model,
            'gemini' => $model === '' || preg_match('/^gemini-(1|2)\./', $model)
                ? 'gemini-3.5-flash'
                : $model,
            'deepseek' => $model !== '' ? $model : 'deepseek-chat',
            'qwen' => $model !== '' ? $model : QwenProvider::MODEL,
            default => $model,
        };
    }

    private static function currentEmbedModel(string $provider, ?string $model): string
    {
        $model = trim((string) $model);
        if ($provider === 'openai') {
            return $model !== '' ? $model : 'text-embedding-3-small';
        }
        if ($provider === 'gemini') {
            return in_array($model, ['', 'text-embedding-004', 'embedding-001', 'gemini-embedding-001'], true)
                ? 'gemini-embedding-2'
                : $model;
        }

        return $model;
    }
}
