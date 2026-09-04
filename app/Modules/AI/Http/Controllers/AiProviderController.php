<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiWorkspaceSetting;
use App\Modules\AI\Services\AiCreditService;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\ProviderErrorPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AiProviderController extends Controller
{
    public const CLIENT_PROVIDERS = ['openai', 'anthropic', 'gemini'];

    public function index(Request $request, AiCreditService $credits): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $configs = AiProviderConfig::where('workspace_id', $workspaceId)->get()->keyBy('provider');

        $list = collect(self::CLIENT_PROVIDERS)->map(fn ($p) => [
            'provider' => $p,
            'enabled' => $configs->get($p)?->enabled ?? false,
            'configured' => ! empty($configs->get($p)?->credentials),
            'default_model_chat' => $configs->get($p)?->default_model_chat ?? '',
            'default_model_embed' => $configs->get($p)?->default_model_embed ?? '',
            'last_test_succeeded_at' => $configs->get($p)?->last_test_succeeded_at?->toIso8601String(),
        ]);

        return Inertia::render('AI/Providers/Index', [
            'providers' => $list,
            'providerMode' => AiWorkspaceSetting::modeFor((int) $workspaceId),
            'aiCredits' => $credits->usage((int) $workspaceId),
        ]);
    }

    public function updateMode(Request $request): RedirectResponse
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
        $validated = $request->validate(['provider_mode' => ['required', 'in:managed,byok,auto_fallback']]);

        AiWorkspaceSetting::updateOrCreate(
            ['workspace_id' => $workspaceId],
            ['provider_mode' => $validated['provider_mode']],
        );

        return back()->with('success', 'AI provider mode updated.');
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::CLIENT_PROVIDERS, true), 404);
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'default_model_chat' => ['nullable', 'string', 'max:64'],
            'default_model_embed' => ['nullable', 'string', 'max:64'],
            'enabled' => ['boolean'],
        ]);

        $config = AiProviderConfig::firstOrNew(['workspace_id' => $workspaceId, 'provider' => $provider]);
        $creds = $config->credentials ?? [];

        $credentialChanged = ! empty($validated['api_key']) && ! preg_match('/^•+/', $validated['api_key']);
        if ($credentialChanged) {
            $creds['api_key'] = $validated['api_key'];
        }

        if (($validated['enabled'] ?? false) && empty($creds['api_key'])) {
            throw ValidationException::withMessages([
                'api_key' => 'An API key is required before this provider can be enabled.',
            ]);
        }

        $config->fill([
            'credentials' => $creds,
            'default_model_chat' => $validated['default_model_chat'] ?? $config->default_model_chat,
            'default_model_embed' => $validated['default_model_embed'] ?? $config->default_model_embed,
            'enabled' => (bool) $validated['enabled'],
            'last_test_succeeded_at' => $credentialChanged ? null : $config->last_test_succeeded_at,
            'last_test_error_code' => $credentialChanged ? null : $config->last_test_error_code,
        ])->save();

        return back()->with('success', ucfirst($provider).' configuration saved.');
    }

    public function test(Request $request, string $provider): JsonResponse
    {
        abort_unless(in_array($provider, self::CLIENT_PROVIDERS, true), 404);
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        $config = AiProviderConfig::where('workspace_id', $workspaceId)
            ->where('provider', $provider)
            ->first();

        if (! $config || empty($config->credentials['api_key'] ?? '')) {
            return response()->json([
                'ok' => false,
                'error' => 'Save an API key before testing this provider.',
                'error_code' => 'provider_not_configured',
            ], 422);
        }

        try {
            $client = LlmManager::build($provider, $config->credentials ?? [], [
                'chat' => $config->default_model_chat,
                'embed' => $config->default_model_embed,
            ]);

            $client->chat([
                ['role' => 'user', 'content' => 'Reply with OK.'],
            ], [
                'max_tokens' => 8,
                'temperature' => 0,
            ]);

            $supportsEmbeddings = in_array($provider, ['openai', 'gemini'], true);
            if ($supportsEmbeddings) {
                $client->embed(['connection test']);
            }

            $config->update([
                'last_tested_at' => now(),
                'last_test_succeeded_at' => now(),
                'last_test_error_code' => null,
            ]);

            return response()->json([
                'ok' => true,
                'message' => ucfirst($provider).' connected successfully.',
                'capabilities' => [
                    'chat' => true,
                    'embeddings' => $supportsEmbeddings,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('ai.provider_test_failed', [
                'workspace_id' => $workspaceId,
                'provider' => $provider,
                'exception' => $e::class,
            ]);

            $error = ProviderErrorPresenter::present($e);
            $config->update([
                'last_tested_at' => now(),
                'last_test_succeeded_at' => null,
                'last_test_error_code' => $error['code'],
            ]);

            return response()->json([
                'ok' => false,
                'error' => $error['message'],
                'error_code' => $error['code'],
            ], 422);
        }
    }
}
