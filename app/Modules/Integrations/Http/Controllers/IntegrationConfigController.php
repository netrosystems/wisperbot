<?php

namespace App\Modules\Integrations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Services\Llm\QwenProvider;
use App\Modules\Integrations\Models\IntegrationAuditLog;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Integrations\Services\ConnectionTester;
use App\Services\StorageManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationConfigController extends Controller
{
    public function index(): Response
    {
        $configs = IntegrationConfig::whereIn('provider', IntegrationConfig::PROVIDERS)
            ->where('mode', 'live')
            ->get()
            ->keyBy('provider');

        $grouped = [];
        foreach (IntegrationConfig::PROVIDERS as $provider) {
            $config = $configs->get($provider);
            $category = IntegrationConfig::CATEGORIES[$provider] ?? 'Other';
            $grouped[$category][] = [
                'provider' => $provider,
                'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
                'category' => $category,
                'enabled' => $config?->enabled ?? false,
                'is_default' => $config?->is_default ?? false,
                'mode' => $config?->mode ?? 'live',
                'configured' => $config?->isConfigured() ?? false,
                'last_test_status' => $config?->last_test_status ?? 'untested',
                'last_test_message' => $config?->last_test_message,
                'last_tested_at' => $config?->last_tested_at?->toISOString(),
            ];
        }

        return Inertia::render('Admin/Integrations/Index', [
            'grouped' => $grouped,
        ]);
    }

    public function edit(Request $request, string $provider): Response
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $mode = in_array($request->query('mode'), ['test', 'live'], true)
            ? $request->query('mode')
            : 'live';

        $config = IntegrationConfig::forProvider($provider, $mode) ?? new IntegrationConfig([
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'mode' => $mode,
            'enabled' => false,
        ]);

        return Inertia::render('Admin/Integrations/Edit', [
            'provider' => $provider,
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'category' => IntegrationConfig::CATEGORIES[$provider] ?? 'Other',
            'fields' => IntegrationConfig::FIELDS[$provider] ?? [],
            // OAuth redirect/callback URL the admin must register in the platform's app settings.
            'callbackUrl' => match ($provider) {
                'oauth_linkedin' => route('client.social.oauth.callback', 'linkedin'),
                'oauth_youtube' => route('client.social.oauth.callback', 'youtube'),
                'oauth_google_mail' => route('client.inbox.email.google.callback'),
                'oauth_tiktok' => route('client.social.oauth.callback', 'tiktok'),
                'oauth_shopify' => route('client.ecommerce.oauth.shopify.callback'),
                'oauth_bigcommerce' => route('client.ecommerce.oauth.bigcommerce.callback'),
                'oauth_ebay' => route('client.inbox.setup.ebay.callback'),
                'oauth_amazon_spapi' => route('client.inbox.setup.amazon.callback'),
                default => null,
            },
            'oauthLoginUrl' => $provider === 'oauth_amazon_spapi'
                ? route('client.inbox.setup.amazon.login')
                : null,
            'config' => [
                'enabled' => $config->enabled ?? false,
                'mode' => $config->mode ?? 'live',
                'last_test_status' => $config->last_test_status ?? 'untested',
                'last_test_message' => $config->last_test_message,
                'last_tested_at' => $config->last_tested_at?->toISOString(),
                'credentials' => $config->exists ? $config->maskedCredentials() : [],
            ],
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $fields = IntegrationConfig::FIELDS[$provider] ?? [];
        $rules = ['enabled' => ['required', 'boolean'], 'mode' => ['required', 'in:test,live']];
        foreach ($fields as $f) {
            $rules['credentials.'.$f['key']] = [$f['required'] ? 'nullable' : 'nullable', 'string', 'max:1024'];
        }
        if ($provider === 'llm_qwen_default') {
            $rules['credentials.region'] = ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (! preg_match('/^•+$/u', (string) $value) && ! in_array($value, QwenProvider::REGIONS, true)) {
                    $fail('Select a supported Alibaba Cloud region.');
                }
            }];
            $rules['credentials.workspace_id'] = ['nullable', 'string', 'max:128', function (string $attribute, mixed $value, \Closure $fail) {
                if (! preg_match('/^•+$/u', (string) $value)
                    && ! preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{1,126}[A-Za-z0-9])?$/', (string) $value)) {
                    $fail('Enter a valid Model Studio Workspace ID.');
                }
            }];
        }

        $validated = $request->validate($rules);

        $config = IntegrationConfig::firstOrNew(['provider' => $provider, 'mode' => $validated['mode']]);

        // Merge credentials: skip masked values (••••xxxx) to preserve existing
        $existing = $config->credentials ?? [];
        $incoming = $validated['credentials'] ?? [];
        $merged = $existing;
        $changedKeys = [];

        foreach ($incoming as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (preg_match('/^•+/', (string) $v)) {
                continue; // keep existing
            }
            $merged[$k] = $v;
            $changedKeys[] = $k;
        }

        if ($config->exists && $config->is_default && $changedKeys !== []) {
            throw ValidationException::withMessages([
                'credentials' => 'Select another managed AI provider before changing credentials for the active provider.',
            ]);
        }

        if ($config->exists && $config->is_default && ! (bool) $validated['enabled']) {
            throw ValidationException::withMessages([
                'enabled' => 'Select another managed AI provider before disabling the active provider.',
            ]);
        }

        if ((bool) $validated['enabled']) {
            $missing = IntegrationConfig::missingRequiredCredentialKeys($provider, $merged);
            if ($missing !== []) {
                $messages = [];
                foreach ($missing as $key) {
                    $messages['credentials.'.$key] = 'This credential is required before the integration can be enabled.';
                }

                throw ValidationException::withMessages($messages);
            }
        }

        $wasEnabled = $config->enabled ?? false;
        $updates = [
            'label' => IntegrationConfig::LABELS[$provider] ?? $provider,
            'enabled' => (bool) $validated['enabled'],
            'mode' => $validated['mode'],
            'credentials' => $merged,
            'updated_by_admin_id' => auth('admin')->id(),
        ];
        if ($changedKeys !== []) {
            $updates += [
                'last_test_status' => 'untested',
                'last_test_message' => null,
                'last_tested_at' => null,
            ];
        }
        $config->fill($updates)->save();

        $this->auditLog($request, $config, $config->wasRecentlyCreated ? 'create' : 'update', $changedKeys);
        if ($wasEnabled !== $config->enabled) {
            $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);
        }

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with('success', 'Integration saved.');
    }

    public function test(Request $request, string $provider): RedirectResponse|JsonResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $validated = $request->validate([
            'mode' => ['sometimes', 'in:test,live'],
        ]);
        $config = IntegrationConfig::forProvider($provider, $validated['mode'] ?? 'live');
        if (! $config) {
            return response()->json(['ok' => false, 'message' => 'Not configured yet.']);
        }

        $result = app(ConnectionTester::class)->test($config);
        $this->auditLog($request, $config, 'test', []);

        if ($request->wantsJson()) {
            return response()->json($result);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function toggle(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', 'Configure credentials before enabling.');
        }

        if (! $config->enabled && ! $config->isConfigured()) {
            return back()->with('error', 'Complete all required credentials before enabling.');
        }

        if ($config->enabled && $config->is_default && in_array($provider, IntegrationConfig::LLM_PROVIDERS, true)) {
            return back()->with('error', 'Select another managed AI provider before disabling the active provider.');
        }

        $updates = ['enabled' => ! $config->enabled];
        // If disabling a storage that was the default, clear its default flag
        if ($config->enabled && ($config->is_default ?? false) && str_starts_with($provider, 'storage_')) {
            $updates['is_default'] = false;
        }
        $config->update($updates);
        $this->auditLog($request, $config, $config->enabled ? 'enable' : 'disable', []);

        if (str_starts_with($provider, 'storage_')) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with('success', 'Integration '.($config->enabled ? 'enabled' : 'disabled').'.');
    }

    public function setDefault(Request $request, string $provider): RedirectResponse
    {
        $isStorage = in_array($provider, IntegrationConfig::STORAGE_PROVIDERS, true);
        $isLlm = in_array($provider, IntegrationConfig::LLM_PROVIDERS, true);
        abort_unless($isStorage || $isLlm, 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config || ! $config->enabled || ! $config->isConfigured()) {
            return back()->with('error', 'Only an enabled and configured provider can be selected.');
        }

        if ($isLlm && $config->last_test_status !== 'ok') {
            return back()->with('error', 'Test this AI provider successfully before using it as managed AI.');
        }

        $providers = $isStorage ? IntegrationConfig::STORAGE_PROVIDERS : IntegrationConfig::LLM_PROVIDERS;
        DB::transaction(function () use ($config, $provider, $providers) {
            IntegrationConfig::whereIn('provider', $providers)
                ->where('mode', 'live')
                ->lockForUpdate()
                ->get(['id']);
            IntegrationConfig::whereIn('provider', $providers)
                ->where('mode', 'live')
                ->where('provider', '!=', $provider)
                ->update(['is_default' => false]);
            $config->update(['is_default' => true]);
        });
        $this->auditLog($request, $config, 'update', ['is_default']);

        if ($isStorage) {
            app(StorageManager::class)->clearCache();
        }

        return back()->with(
            'success',
            IntegrationConfig::LABELS[$provider].($isStorage ? ' set as default storage.' : ' will now power managed AI generation.'),
        );
    }

    public function rotate(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, IntegrationConfig::PROVIDERS, true), 404);

        $config = IntegrationConfig::forProvider($provider);
        if (! $config) {
            return back()->with('error', 'Not configured.');
        }

        $secret = bin2hex(random_bytes(32));
        $config->update(['webhook_secret' => $secret, 'updated_by_admin_id' => auth('admin')->id()]);
        $this->auditLog($request, $config, 'rotate', ['webhook_secret']);

        return back()->with('success', 'Webhook secret rotated.');
    }

    public function auditLogIndex(Request $request): Response
    {
        $logs = IntegrationAuditLog::with('admin')
            ->latest('created_at')
            ->paginate(50);

        return Inertia::render('Admin/Integrations/AuditLog', ['logs' => $logs]);
    }

    private function auditLog(Request $request, IntegrationConfig $config, string $action, array $changedKeys): void
    {
        IntegrationAuditLog::create([
            'admin_user_id' => auth('admin')->id(),
            'integration_config_id' => $config->id,
            'provider' => $config->provider,
            'action' => $action,
            'diff_json' => $changedKeys,
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 512),
        ]);
    }
}
