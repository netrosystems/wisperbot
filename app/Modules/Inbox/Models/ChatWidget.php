<?php

namespace App\Modules\Inbox\Models;

use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\ChannelAccount;
use App\Services\StorageManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A website live-chat widget. Owns one `webchat` channel_account and stores the
 * theming + behaviour served to the embeddable script and used by the inbox.
 */
class ChatWidget extends Model
{
    protected $table = 'chat_widgets';

    protected $fillable = [
        'workspace_id', 'channel_account_id', 'widget_key', 'name',
        'title', 'subtitle', 'welcome_message', 'agent_name', 'avatar_url',
        'primary_color', 'position', 'launcher_text', 'footer_company_name',
        'launcher_logo_path', 'launcher_logo_disk',
        'ai_enabled', 'ai_chatbot_id', 'require_prechat', 'prechat_fields',
        'offline_message', 'allowed_domains', 'working_hours_json', 'enabled',
        'identity_verification', 'identity_secret',
    ];

    protected $hidden = ['identity_secret'];

    protected $appends = ['launcher_logo_url'];

    protected function casts(): array
    {
        return [
            'ai_enabled' => 'boolean',
            'require_prechat' => 'boolean',
            'enabled' => 'boolean',
            'identity_verification' => 'boolean',
            'prechat_fields' => 'array',
            'allowed_domains' => 'array',
            'working_hours_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->widget_key)) {
                $model->widget_key = Str::random(32);
            }
            if (empty($model->identity_secret)) {
                $model->identity_secret = Str::random(48);
            }
        });
    }

    public function channelAccount(): BelongsTo
    {
        return $this->belongsTo(ChannelAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function aiChatbot(): BelongsTo
    {
        return $this->belongsTo(AiChatbot::class, 'ai_chatbot_id');
    }

    public function hasActiveAiChatbot(): bool
    {
        if (! $this->ai_enabled || ! $this->ai_chatbot_id) {
            return false;
        }

        $chatbot = $this->relationLoaded('aiChatbot')
            ? $this->aiChatbot
            : $this->aiChatbot()->first();

        return (bool) $chatbot
            && $chatbot->enabled
            && (int) $chatbot->workspace_id === (int) $this->workspace_id;
    }

    public function getLauncherLogoUrlAttribute(): ?string
    {
        if (! $this->launcher_logo_path || ! $this->canUseCustomLauncherLogo()) {
            return null;
        }

        $disk = $this->launcher_logo_disk ?: app(StorageManager::class)->diskName();

        return Storage::disk($disk)->url($this->launcher_logo_path);
    }

    private function canUseCustomLauncherLogo(): bool
    {
        $plan = $this->workspace?->client?->effectivePlan()
            ?: $this->workspace?->owner?->effectiveSubscription()?->plan;

        return (bool) $plan?->hasFeature('white_label');
    }

    /** Public theming/config surfaced to the embed script + widget UI. */
    public function publicConfig(): array
    {
        $launcherLogoUrl = $this->launcher_logo_url ?: url('/wisperbot-icon-white.svg');
        $teamMembers = $this->publicTeamMembers();

        return [
            'key' => $this->widget_key,
            'title' => $this->title ?: 'Chat with us',
            'subtitle' => $this->subtitle ?: 'We typically reply in a few minutes',
            'welcome_message' => $this->welcome_message ?: 'Hi there 👋 How can we help?',
            'agent_name' => $this->agent_name ?: 'Support',
            // Keep the agent/header avatar aligned with the launcher branding.
            // Updating the paid custom icon therefore updates both surfaces.
            'avatar_url' => $launcherLogoUrl,
            'primary_color' => $this->primary_color ?: '#ff762e',
            'position' => $this->position ?: 'bottom_right',
            'launcher_text' => $this->launcher_text,
            // Every plan can use its own brand in the embedded widget. Existing
            // widgets retain the familiar WisperBot fallback until edited.
            'footer_company_name' => $this->footer_company_name ?: 'WisperBot',
            // The product icon remains the default for every free widget.
            // A custom launcher mark is only exposed for white-label plans.
            'launcher_logo_url' => $launcherLogoUrl,
            'team_members' => $teamMembers,
            'team_member_count' => count($teamMembers),
            // Only expose whether AI is active; never expose the internal bot id.
            'ai_enabled' => $this->hasActiveAiChatbot(),
            'require_prechat' => (bool) $this->require_prechat,
            'prechat_fields' => $this->prechat_fields ?: ['name', 'email'],
            'offline_message' => $this->offline_message,
        ];
    }

    /**
     * Small, public-safe team presence summary for the widget header.
     *
     * @return array<int, array{name:string,avatar_url:?string}>
     */
    private function publicTeamMembers(): array
    {
        $workspace = $this->workspace()
            ->with(['owner', 'members', 'users'])
            ->first();

        if (! $workspace) {
            return [];
        }

        return collect([$workspace->owner])
            ->merge($workspace->members)
            ->merge($workspace->users)
            ->filter(fn ($user) => $user && $user->status === 'active')
            ->unique('id')
            ->take(5)
            ->map(fn ($user) => [
                'name' => $user->name,
                'avatar_url' => $user->avatarUrl(),
            ])
            ->values()
            ->all();
    }
}
